<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Worker;

use finfo;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Image\ImageManager;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory;
use Imagick;
use Spatie\PdfToText\Exceptions\CouldNotExtractText;
use Spatie\PdfToText\Pdf;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Throwable;

/** @param array<string, mixed> $data */
function respond(bool $ok, array $data = [], ?string $code = null): never
{
    $response = ['ok' => $ok, 'data' => $data];

    if ($code !== null) {
        $response['code'] = $code;
    }

    echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit(0);
}

/** @return array<string, mixed> */
function request(): array
{
    $input = stream_get_contents(STDIN, 20_000_000);

    if (! is_string($input)) {
        respond(false, code: 'preparation_failed');
    }

    $request = json_decode($input, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($request)) {
        respond(false, code: 'preparation_failed');
    }

    return $request;
}

function regularPath(mixed $path): string
{
    $resolved = is_string($path) ? realpath($path) : false;
    $stat = $resolved === false ? false : @stat($resolved);

    if ($resolved === false || ! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
        respond(false, code: 'preparation_failed');
    }

    return $resolved;
}

function outputPath(mixed $path): string
{
    if (! is_string($path) || str_contains($path, "\0")) {
        respond(false, code: 'preparation_failed');
    }

    $parent = realpath(dirname($path));

    if ($parent === false || ! is_dir($parent) || preg_match('/^[a-z0-9][a-z0-9._-]*$/', basename($path)) !== 1) {
        respond(false, code: 'preparation_failed');
    }

    return $parent.'/'.basename($path);
}

/**
 * @param  list<string>  $argv
 * @return array{ok: bool, stdout: string, stderr: string}
 */
function native(array $argv, int $timeout): array
{
    try {
        $result = (new Factory)->newPendingProcess()->timeout($timeout)->run($argv);

        return [
            'ok' => $result->successful(),
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
        ];
    } catch (ProcessTimedOutException) {
        respond(false, code: 'parser_timeout');
    } catch (ProcessSignaledException) {
        respond(false, code: 'resource_limit_exceeded');
    }
}

function imageManager(): ImageManager
{
    $container = new Container;
    $container->instance('config', new Repository(['images' => ['default' => 'imagick']]));
    $manager = new ImageManager($container);
    $container->instance('image', $manager);
    Container::setInstance($container);

    return $manager;
}

function writePrivate(string $path, string $bytes, int $limit): void
{
    if ($bytes === '' || strlen($bytes) > $limit) {
        respond(false, code: 'temporary_limit_exceeded');
    }

    $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
    $stream = @fopen($temporary, 'x+b');

    if (! is_resource($stream) || ! @chmod($temporary, 0600)) {
        @unlink($temporary);
        respond(false, code: 'preparation_failed');
    }

    $offset = 0;

    while ($offset < strlen($bytes)) {
        $written = @fwrite($stream, substr($bytes, $offset, 8192));

        if (! is_int($written) || $written < 1) {
            fclose($stream);
            @unlink($temporary);
            respond(false, code: 'preparation_failed');
        }

        $offset += $written;
    }

    if (! fflush($stream) || ! fclose($stream) || ! @rename($temporary, $path)) {
        @unlink($temporary);
        respond(false, code: 'preparation_failed');
    }
}

/** @param array<string, mixed> $payload @param array<string, string> $binaries */
function pdfInfo(array $payload, array $binaries, int $timeout): never
{
    $source = regularPath($payload['source'] ?? null);
    $pageLimit = filter_var($payload['page_limit'] ?? null, FILTER_VALIDATE_INT);
    $sourceBytes = filter_var($payload['source_bytes'] ?? null, FILTER_VALIDATE_INT);

    if (! is_int($pageLimit) || $pageLimit < 1 || ! is_int($sourceBytes) || $sourceBytes < 0) {
        respond(false, code: 'preparation_failed');
    }

    $result = native([
        $binaries['pdfinfo'], '-f', '1', '-l', (string) ($pageLimit + 1), '-box', $source,
    ], $timeout);

    if (! $result['ok']) {
        respond(false, code: stripos($result['stderr'], 'password') !== false ? 'encrypted_pdf' : 'invalid_pdf');
    }

    if (trim($result['stderr']) !== '') {
        respond(false, code: 'invalid_pdf');
    }

    $stdout = $result['stdout'];

    if (preg_match('/^Pages:\s+(\d+)$/mi', $stdout, $pagesMatch) !== 1
        || preg_match('/^Encrypted:\s+(yes|no)\b/mi', $stdout, $encryptedMatch) !== 1
        || preg_match('/^File size:\s+(\d+) bytes$/mi', $stdout, $sizeMatch) !== 1) {
        respond(false, code: 'invalid_pdf');
    }

    $pages = (int) $pagesMatch[1];

    if ($pages < 1 || (int) $sizeMatch[1] !== $sourceBytes) {
        respond(false, code: 'invalid_pdf');
    }

    if (strtolower($encryptedMatch[1]) === 'yes') {
        respond(false, code: 'encrypted_pdf');
    }

    if (preg_match('/^JavaScript:\s+yes$/mi', $stdout) === 1) {
        respond(false, code: 'active_pdf');
    }

    if ($pages > $pageLimit) {
        respond(false, code: 'page_limit_exceeded');
    }

    preg_match_all('/^Page\s+(\d+)\s+size:\s+([0-9]+(?:\.[0-9]+)?)\s+x\s+([0-9]+(?:\.[0-9]+)?)\s+pts\b/mi', $stdout, $dimensionMatches, PREG_SET_ORDER);
    preg_match_all('/^Page\s+(\d+)\s+rot:\s+(-?\d+)$/mi', $stdout, $rotationMatches, PREG_SET_ORDER);
    $rotations = [];

    foreach ($rotationMatches as $match) {
        $rotations[(int) $match[1]] = ((int) $match[2] % 360 + 360) % 360;
    }

    $dimensions = [];

    foreach ($dimensionMatches as $match) {
        $page = (int) $match[1];
        $width = (float) $match[2];
        $height = (float) $match[3];
        $rotation = $rotations[$page] ?? 0;

        if ($page < 1 || $page > $pages || ! is_finite($width) || ! is_finite($height)
            || $width <= 0 || $height <= 0 || $width > 10_000_000 || $height > 10_000_000
            || ! in_array($rotation, [0, 90, 180, 270], true)) {
            respond(false, code: 'invalid_pdf');
        }

        if (in_array($rotation, [90, 270], true)) {
            [$width, $height] = [$height, $width];
        }

        $dimensions[$page] = ['width_points' => $width, 'height_points' => $height];
    }

    if (count($dimensions) !== $pages || array_keys($dimensions) !== range(1, $pages)) {
        respond(false, code: 'invalid_pdf');
    }

    respond(true, ['page_count' => $pages, 'dimensions' => $dimensions]);
}

/** @param array<string, mixed> $payload @param array<string, string> $binaries */
function pdfInventory(array $payload, array $binaries, int $timeout): never
{
    $source = regularPath($payload['source'] ?? null);
    $pageCount = (int) ($payload['page_count'] ?? 0);
    $result = native([$binaries['pdfimages'], '-list', $source], $timeout);

    if (! $result['ok'] || trim($result['stderr']) !== '') {
        respond(false, code: 'invalid_pdf');
    }

    $pages = [];

    foreach (array_slice(preg_split('/\R/', $result['stdout']) ?: [], 2) as $line) {
        if (trim($line) === '') {
            continue;
        }

        $columns = preg_split('/\s+/', trim($line));
        $page = is_array($columns) ? filter_var($columns[0] ?? null, FILTER_VALIDATE_INT) : false;

        if (! is_int($page) || $page < 1 || $page > $pageCount || count($columns) < 12) {
            respond(false, code: 'invalid_pdf');
        }

        $pages[$page] = true;
    }

    $values = array_keys($pages);
    sort($values, SORT_NUMERIC);
    respond(true, ['raster_pages' => $values]);
}

/** @param array<string, mixed> $payload @param array<string, string> $binaries */
function pdfText(array $payload, array $binaries, int $timeout): never
{
    $source = regularPath($payload['source'] ?? null);
    $page = (int) ($payload['page'] ?? 0);
    $outputLimit = (int) ($payload['output_limit'] ?? 0);

    if ($page < 1 || $outputLimit < 1) {
        respond(false, code: 'preparation_failed');
    }

    try {
        $text = (new Pdf($binaries['pdftotext']))
            ->setPdf($source)
            ->setOptions(['f '.$page, 'l '.$page])
            ->setTimeout($timeout)
            ->text();
    } catch (CouldNotExtractText) {
        respond(true, ['text' => null, 'text_error' => true]);
    } catch (ProcessTimedOutException|\Symfony\Component\Process\Exception\ProcessTimedOutException) {
        respond(false, code: 'parser_timeout');
    } catch (ProcessSignaledException) {
        respond(false, code: 'resource_limit_exceeded');
    }

    if (strlen($text) > $outputLimit) {
        respond(false, code: 'output_limit_exceeded');
    }

    if (! mb_check_encoding($text, 'UTF-8')) {
        respond(true, ['text' => null, 'text_error' => true]);
    }

    respond(true, ['text_base64' => base64_encode($text), 'text_error' => false]);
}

/** @param array<string, mixed> $payload @param array<string, string> $binaries */
function pdfRender(array $payload, array $binaries, int $timeout): never
{
    $source = regularPath($payload['source'] ?? null);
    $output = outputPath($payload['output'] ?? null);
    $page = (int) ($payload['page'] ?? 0);
    $dpi = (int) ($payload['dpi'] ?? 0);
    $expectedWidth = (int) ($payload['expected_width'] ?? 0);
    $expectedHeight = (int) ($payload['expected_height'] ?? 0);
    $temporaryLimit = (int) ($payload['temporary_limit'] ?? 0);

    if ($page < 1 || $dpi < 1 || $expectedWidth < 1 || $expectedHeight < 1 || $temporaryLimit < 1) {
        respond(false, code: 'preparation_failed');
    }

    $prefix = substr($output, 0, -4);
    $result = native([
        $binaries['pdftoppm'], '-png', '-singlefile', '-r', (string) $dpi,
        '-f', (string) $page, '-l', (string) $page, $source, $prefix,
    ], $timeout);

    if (! $result['ok'] || trim($result['stderr']) !== '' || ! is_file($output)) {
        @unlink($output);
        respond(false, code: 'invalid_pdf');
    }

    try {
        $image = imageManager()->fromPath($output)->usingImagick()->orient()->toPng();
        $bytes = $image->toBytes();
        [$width, $height] = $image->dimensions();
    } catch (Throwable) {
        @unlink($output);
        respond(false, code: 'invalid_pdf');
    }

    if ($width !== $expectedWidth || $height !== $expectedHeight) {
        @unlink($output);
        respond(false, code: 'invalid_pdf');
    }

    @unlink($output);
    writePrivate($output, $bytes, $temporaryLimit);
    respond(true, ['width' => $width, 'height' => $height, 'bytes' => strlen($bytes)]);
}

/** @param array<string, mixed> $payload */
function imageInspect(array $payload): never
{
    $source = regularPath($payload['source'] ?? null);
    $expectedMediaType = $payload['media_type'] ?? null;
    $pageLimit = (int) ($payload['page_limit'] ?? 0);
    $pixelLimit = (int) ($payload['pixel_limit'] ?? 0);
    $actual = (new finfo(FILEINFO_MIME_TYPE))->file($source);
    $accepted = ['image/jpeg', 'image/png', 'image/webp', 'image/tiff', 'image/heic', 'image/heif', 'image/bmp', 'image/avif', 'image/gif'];

    if (! is_string($expectedMediaType) || ! is_string($actual)) {
        respond(false, code: 'unsupported_codec');
    }

    $actual = match (strtolower($actual)) {
        'image/x-avif' => 'image/avif',
        'image/x-heic' => 'image/heic',
        'image/x-ms-bmp', 'image/x-bmp' => 'image/bmp',
        default => strtolower($actual),
    };

    if (! in_array($actual, $accepted, true) || $actual !== $expectedMediaType) {
        respond(false, code: 'unsupported_codec');
    }

    try {
        $image = new Imagick;
        $image->pingImage($source);
        $frames = $image->getNumberImages();
    } catch (Throwable) {
        respond(false, code: 'unsupported_codec');
    }

    if ($frames < 1 || $frames > $pageLimit) {
        respond(false, code: 'page_limit_exceeded');
    }

    if ($actual !== 'image/tiff' && $frames !== 1) {
        respond(false, code: 'animated_image');
    }

    $dimensions = [];

    for ($frame = 0; $frame < $frames; $frame++) {
        try {
            $image->setIteratorIndex($frame);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $orientation = $image->getImageOrientation();
        } catch (Throwable) {
            respond(false, code: 'unsupported_codec');
        }

        if ($width < 1 || $height < 1 || $width > intdiv($pixelLimit, $height)) {
            respond(false, code: 'pixel_limit_exceeded');
        }

        if (in_array($orientation, [5, 6, 7, 8], true)) {
            [$width, $height] = [$height, $width];
        }

        $dimensions[] = ['width' => $width, 'height' => $height];
    }

    $image->clear();
    respond(true, ['page_count' => $frames, 'dimensions' => $dimensions]);
}

/** @param array<string, mixed> $payload */
function imageNormalize(array $payload): never
{
    $source = regularPath($payload['source'] ?? null);
    $output = outputPath($payload['output'] ?? null);
    $mediaType = $payload['media_type'] ?? null;
    $frame = (int) ($payload['frame'] ?? -1);
    $expectedWidth = (int) ($payload['expected_width'] ?? 0);
    $expectedHeight = (int) ($payload['expected_height'] ?? 0);
    $temporaryLimit = (int) ($payload['temporary_limit'] ?? 0);

    if (! is_string($mediaType) || $frame < 0 || $expectedWidth < 1 || $expectedHeight < 1 || $temporaryLimit < 1) {
        respond(false, code: 'preparation_failed');
    }

    try {
        $manager = imageManager();

        if ($mediaType === 'image/tiff') {
            $sequence = new Imagick($source);
            $sequence->setIteratorIndex($frame);
            $selected = $sequence->getImage();
            $selected->setImagePage(0, 0, 0, 0);
            $selected->setImageFormat('png');
            $input = $selected->getImageBlob();
            $selected->clear();
            $sequence->clear();
        } else {
            $input = file_get_contents($source);
        }

        if (! is_string($input)) {
            respond(false, code: 'unsupported_codec');
        }

        $image = $manager->fromBytes($input)->usingImagick()->orient()->toPng();
        $bytes = $image->toBytes();
        [$width, $height] = $image->dimensions();
    } catch (Throwable) {
        respond(false, code: 'unsupported_codec');
    }

    if ($width !== $expectedWidth || $height !== $expectedHeight) {
        respond(false, code: 'unsupported_codec');
    }

    writePrivate($output, $bytes, $temporaryLimit);
    respond(true, ['width' => $width, 'height' => $height, 'bytes' => strlen($bytes)]);
}

try {
    $request = request();
    $mode = $request['mode'] ?? null;
    $payload = $request['payload'] ?? null;
    $binaries = $request['binaries'] ?? null;
    $timeout = $request['timeout'] ?? null;

    if (! is_string($mode) || ! is_array($payload) || ! is_array($binaries) || ! is_int($timeout) || $timeout < 1) {
        respond(false, code: 'preparation_failed');
    }

    /** @var array<string, string> $binaries */
    match ($mode) {
        'pdf_info' => pdfInfo($payload, $binaries, $timeout),
        'pdf_inventory' => pdfInventory($payload, $binaries, $timeout),
        'pdf_text' => pdfText($payload, $binaries, $timeout),
        'pdf_render' => pdfRender($payload, $binaries, $timeout),
        'image_inspect' => imageInspect($payload),
        'image_normalize' => imageNormalize($payload),
        default => respond(false, code: 'preparation_failed'),
    };
} catch (Throwable) {
    respond(false, code: 'preparation_failed');
}
