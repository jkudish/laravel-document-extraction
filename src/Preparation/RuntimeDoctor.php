<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Preparation;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Image;
use Throwable;

final readonly class RuntimeDoctor
{
    public function __construct(
        private Repository $config,
        private WorkerRunner $worker,
        private Factory $process,
    ) {}

    /** @return array<string, array{ready: bool, message: string}> */
    public function check(): array
    {
        $checks = [];
        $checks['platform'] = $this->result(
            PHP_OS_FAMILY === 'Linux',
            PHP_OS_FAMILY === 'Linux' ? 'Linux OS containment is available.' : 'Linux with util-linux prlimit is required.',
        );

        foreach (['fileinfo', 'imagick', 'mbstring', 'xmlreader'] as $extension) {
            $checks['extension.'.$extension] = $this->result(
                extension_loaded($extension),
                extension_loaded($extension) ? "{$extension} is loaded." : "{$extension} is required.",
            );
        }

        $preparation = $this->config->get('extraction.preparation');
        $limits = $this->config->get('extraction.limits');

        if (! is_array($preparation) || ! is_array($preparation['binaries'] ?? null) || ! is_array($limits)) {
            $checks['configuration'] = $this->result(false, 'Preparation configuration is incomplete.');

            return $checks;
        }

        $requiredLimits = [
            'source_bytes',
            'physical_pages',
            'decoded_pixels_per_page',
            'parser_process_timeout',
            'ai_attempt_timeout',
            'invocation_deadline',
            'retained_output_bytes',
            'temporary_bytes',
            'ai_attempts',
            'inline_attachment_bytes',
        ];
        $configurationReady = $this->positive($preparation['render_dpi'] ?? null)
            && $this->greaterThan($preparation['native_memory_bytes'] ?? null, $preparation['php_memory_bytes'] ?? null)
            && $this->positiveValues($limits, $requiredLimits);
        $checks['configuration'] = $this->result(
            $configurationReady,
            $configurationReady ? 'Preparation limits are valid.' : 'Preparation limits are incomplete or invalid.',
        );

        foreach (['pdfinfo', 'pdfimages', 'pdftoppm', 'pdftotext', 'prlimit', 'php'] as $name) {
            $configured = $preparation['binaries'][$name] ?? null;
            $ready = false;

            if (is_string($configured)) {
                try {
                    $binary = $this->worker->resolveBinary($configured);
                    $arguments = $name === 'prlimit' ? ['--version'] : ['-v'];
                    $ready = $this->probe([$binary, ...$arguments]);
                } catch (Throwable) {
                    $ready = false;
                }
            }

            $checks['binary.'.$name] = $this->result(
                $ready,
                $ready ? "{$name} is executable." : "{$name} is missing or unusable.",
            );
        }

        $checks['image.driver'] = $this->imageApiCheck();
        $formats = class_exists(\Imagick::class) ? \Imagick::queryFormats() : [];
        $requiredFormats = [
            'jpeg' => ['JPEG'],
            'png' => ['PNG'],
            'webp' => ['WEBP'],
            'tiff' => ['TIFF'],
            'heic-heif' => ['HEIC'],
            'bmp' => ['BMP'],
            'avif' => ['AVIF', 'HEIC'],
            'gif' => ['GIF'],
        ];

        foreach ($requiredFormats as $name => $alternatives) {
            $ready = array_intersect($alternatives, $formats) !== [];
            $checks['codec.'.$name] = $this->result(
                $ready,
                $ready ? "{$name} decoding is advertised by Imagick." : "{$name} decoding is unavailable.",
            );
        }

        return $checks;
    }

    /** @param list<string> $command */
    private function probe(array $command): bool
    {
        $environment = [];

        foreach (array_keys(getenv()) as $name) {
            $environment[$name] = false;
        }

        $environment['HOME'] = '/nonexistent';
        $environment['PATH'] = '/usr/local/bin:/usr/bin:/bin';
        $environment['LANG'] = 'C.UTF-8';
        $environment['LC_ALL'] = 'C.UTF-8';

        try {
            return $this->process
                ->newPendingProcess()
                ->env($environment)
                ->timeout(5)
                ->run($command)
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{ready: bool, message: string} */
    private function imageApiCheck(): array
    {
        try {
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
            $ready = is_string($png)
                && Image::getDefaultDriver() === 'imagick'
                && Image::fromBytes($png)->usingImagick()->orient()->toPng()->width() === 1;
        } catch (Throwable) {
            $ready = false;
        }

        return $this->result(
            $ready,
            $ready ? 'Illuminate Image uses the Imagick driver.' : 'Illuminate Image with the Imagick driver is unavailable.',
        );
    }

    /** @return array{ready: bool, message: string} */
    private function result(bool $ready, string $message): array
    {
        return ['ready' => $ready, 'message' => $message];
    }

    private function positive(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function greaterThan(mixed $greater, mixed $lesser): bool
    {
        return is_int($greater) && is_int($lesser) && $greater > $lesser && $lesser > 0;
    }

    /**
     * @param  array<mixed>  $values
     * @param  list<string>  $keys
     */
    private function positiveValues(array $values, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! $this->positive($values[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
