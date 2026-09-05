<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Jkudish\DocumentExtraction\Exceptions\ExtractionException;
use Jkudish\DocumentExtraction\Source\SourceInput;
use Jkudish\DocumentExtraction\Source\SourceSnapshot;
use Jkudish\DocumentExtraction\Tests\Support\RecordingDocumentExtraction;

function sourceRecorder(): RecordingDocumentExtraction
{
    return new RecordingDocumentExtraction(
        app(Repository::class),
        app(FilesystemFactory::class),
        app(Container::class),
    );
}

function temporarySource(string $contents, string $suffix = '.txt'): string
{
    $path = sys_get_temp_dir().'/lde-source-test-'.bin2hex(random_bytes(8)).$suffix;
    file_put_contents($path, $contents);

    return $path;
}

it('snapshots local paths from actual bytes without changing the original', function (): void {
    $contents = "path source\n";
    $path = temporarySource($contents, '.pdf');
    $extraction = sourceRecorder();

    try {
        $result = $extraction->fromPath($path)->withoutAi()->text();
        $snapshot = $extraction->snapshots[0];

        expect($result->sourceSha256)->toBe(hash('sha256', $contents))
            ->and($result->mediaType)->toBe('text/plain')
            ->and($snapshot['contents'])->toBe($contents)
            ->and($snapshot['size'])->toBe(strlen($contents))
            ->and($snapshot['directoryMode'])->toBe(0700)
            ->and($snapshot['fileMode'])->toBe(0600)
            ->and(is_file($snapshot['path']))->toBeFalse()
            ->and(file_get_contents($path))->toBe($contents);
    } finally {
        @unlink($path);
    }
});

it('snapshots storage streams and leaves the remote object untouched', function (): void {
    Storage::fake('remote');
    Storage::disk('remote')->put('inbox/document.bin', 'storage source');
    $extraction = sourceRecorder();

    $result = $extraction->fromStorage('inbox/document.bin', 'remote')->withoutAi()->text();

    expect($result->sourceSha256)->toBe(hash('sha256', 'storage source'))
        ->and($extraction->snapshots[0]['contents'])->toBe('storage source')
        ->and(is_file($extraction->snapshots[0]['path']))->toBeFalse()
        ->and(Storage::disk('remote')->get('inbox/document.bin'))->toBe('storage source');
});

it('snapshots uploads by bytes without trusting client metadata or moving the upload', function (): void {
    $contents = "upload source\n";
    $path = temporarySource($contents, '.png');
    $upload = new UploadedFile($path, 'invoice.png', 'image/png', null, true);
    $extraction = sourceRecorder();

    try {
        $result = $extraction->fromUpload($upload)->withoutAi()->text();

        expect($result->sourceSha256)->toBe(hash('sha256', $contents))
            ->and($result->mediaType)->toBe('text/plain')
            ->and($extraction->snapshots[0]['contents'])->toBe($contents)
            ->and(is_file($extraction->snapshots[0]['path']))->toBeFalse()
            ->and(file_get_contents($upload->getPathname()))->toBe($contents);
    } finally {
        @unlink($path);
    }
});

it('reads caller streams from the current position without rewinding or closing them', function (): void {
    $stream = fopen('php://temp', 'w+b');
    assert(is_resource($stream));
    fwrite($stream, 'prefix-stream source');
    rewind($stream);
    fread($stream, strlen('prefix-'));
    $extraction = sourceRecorder();

    try {
        $result = $extraction->fromStream($stream, 'text/plain')->withoutAi()->text();

        expect($result->sourceSha256)->toBe(hash('sha256', 'stream source'))
            ->and($extraction->snapshots[0]['contents'])->toBe('stream source')
            ->and(is_resource($stream))->toBeTrue()
            ->and(feof($stream))->toBeTrue()
            ->and(is_file($extraction->snapshots[0]['path']))->toBeFalse();
    } finally {
        fclose($stream);
    }
});

it('spools nonseekable streams without taking ownership', function (): void {
    $stream = popen("printf 'nonseekable source'", 'r');
    assert(is_resource($stream));
    $extraction = sourceRecorder();

    try {
        $extraction->fromStream($stream, 'text/plain')->withoutAi()->text();

        expect($extraction->snapshots[0]['contents'])->toBe('nonseekable source')
            ->and(is_resource($stream))->toBeTrue();
    } finally {
        pclose($stream);
    }
});

it('snapshots strings with truthful unpaginated provenance', function (): void {
    $extraction = sourceRecorder();

    $result = $extraction->fromString('{"invoice":1}', 'application/json')->withoutAi()->text();
    $document = $result->documents->first();
    assert($document !== null);

    expect($result->sourceSha256)->toBe(hash('sha256', '{"invoice":1}'))
        ->and($result->mediaType)->toBe('application/json')
        ->and($result->pageCount)->toBeNull()
        ->and($document->pages)->toBeNull()
        ->and($result->pages)->toBeEmpty()
        ->and(is_file($extraction->snapshots[0]['path']))->toBeFalse();
});

it('enforces source and temporary byte caps while cleaning owned temporary files', function (string $limit): void {
    config()->set('extraction.limits.'.$limit, 4);
    $extraction = sourceRecorder();
    $before = glob(sys_get_temp_dir().'/laravel-document-extraction-*') ?: [];

    try {
        $extraction->fromString('12345', 'text/plain')->withoutAi()->text();
        throw new RuntimeException('Expected the bounded snapshot to reject the source.');
    } catch (ExtractionException $exception) {
        expect($exception->errorCode)->toBe('limit_exceeded')
            ->and($extraction->snapshots)->toBe([])
            ->and(glob(sys_get_temp_dir().'/laravel-document-extraction-*') ?: [])->toBe($before);
    }
})->with(['source_bytes', 'temporary_bytes']);

it('applies the byte bound to every source input without taking ownership', function (): void {
    config()->set('extraction.limits.source_bytes', 4);
    Storage::fake('bounded');
    Storage::disk('bounded')->put('source.txt', '12345');
    $path = temporarySource('12345');
    $uploadPath = temporarySource('12345');
    $upload = new UploadedFile($uploadPath, 'source.txt', 'text/plain', null, true);
    $stream = fopen('php://temp', 'w+b');
    assert(is_resource($stream));
    fwrite($stream, '12345');
    rewind($stream);
    $before = glob(sys_get_temp_dir().'/laravel-document-extraction-*') ?: [];

    $attempts = [
        'path' => fn () => sourceRecorder()->fromPath($path)->withoutAi()->text(),
        'storage' => fn () => sourceRecorder()->fromStorage('source.txt', 'bounded')->withoutAi()->text(),
        'upload' => fn () => sourceRecorder()->fromUpload($upload)->withoutAi()->text(),
        'stream' => fn () => sourceRecorder()->fromStream($stream)->withoutAi()->text(),
        'string' => fn () => sourceRecorder()->fromString('12345')->withoutAi()->text(),
    ];

    try {
        foreach ($attempts as $attempt) {
            expect($attempt)->toThrow(ExtractionException::class, 'byte limit');
        }

        expect(file_get_contents($path))->toBe('12345')
            ->and(file_get_contents($upload->getPathname()))->toBe('12345')
            ->and(Storage::disk('bounded')->get('source.txt'))->toBe('12345')
            ->and(is_resource($stream))->toBeTrue()
            ->and(glob(sys_get_temp_dir().'/laravel-document-extraction-*') ?: [])->toBe($before);
    } finally {
        fclose($stream);
        @unlink($path);
        @unlink($uploadPath);
    }
});

it('rejects binary bytes even when names and MIME hints claim text', function (): void {
    $binary = str_repeat('apparently textual prefix ', 400)."\0hidden binary";
    $path = temporarySource($binary, '.txt');
    $upload = new UploadedFile($path, 'source.txt', 'text/plain', null, true);

    try {
        expect(fn () => sourceRecorder()->fromUpload($upload)->withoutAi()->text())
            ->toThrow(ExtractionException::class, 'supported document format')
            ->and(file_get_contents($path))->toBe($binary);
    } finally {
        @unlink($path);
    }
});

it('cleans the private snapshot when downstream processing throws', function (): void {
    $extraction = sourceRecorder();
    $extraction->failDuringProcessing = true;

    expect(fn () => $extraction->fromString('failure source')->withoutAi()->text())
        ->toThrow(RuntimeException::class, 'Simulated downstream failure.');

    expect($extraction->snapshots)->toHaveCount(1)
        ->and(is_file($extraction->snapshots[0]['path']))->toBeFalse();
});

it('rejects closed and write-only streams before creating a snapshot', function (Closure $streamFactory): void {
    $stream = $streamFactory();
    $extraction = sourceRecorder();

    try {
        expect(fn () => $extraction->fromStream($stream)->text())
            ->toThrow(ExtractionException::class)
            ->and($extraction->snapshots)->toBe([]);
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
})->with([
    'closed' => [function () {
        $stream = fopen('php://temp', 'r');
        assert(is_resource($stream));
        fclose($stream);

        return $stream;
    }],
    'write only' => [fn () => fopen('/dev/null', 'w')],
]);

it('rejects URL wrappers directories and special local files safely', function (): void {
    $directory = sys_get_temp_dir().'/lde-source-directory-'.bin2hex(random_bytes(8));
    $fifo = sys_get_temp_dir().'/lde-source-fifo-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    posix_mkfifo($fifo, 0600);
    $extraction = sourceRecorder();

    try {
        foreach (['php://memory', $directory, $fifo] as $unsafe) {
            try {
                $extraction->fromPath($unsafe)->text();
                throw new RuntimeException('Expected an unsafe local path to be rejected.');
            } catch (ExtractionException $exception) {
                expect($exception->errorCode)->toBe('invalid_source')
                    ->and($exception->getMessage())->not->toContain($unsafe);
            }
        }
    } finally {
        @unlink($fifo);
        @rmdir($directory);
    }
});

it('reports failed snapshot cleanup and permits retry after the filesystem is repaired', function (): void {
    $snapshot = SourceSnapshot::capture(
        SourceInput::contents('private source', 'text/plain'),
        app(FilesystemFactory::class),
        100,
        100,
    );
    $directory = dirname($snapshot->path);
    // A parser derivative still present prevents removing the owned directory.
    $derivative = $directory.'/remaining-derivative';
    file_put_contents($derivative, 'private derivative');

    try {
        expect(fn () => $snapshot->cleanup())->toThrow(ExtractionException::class, 'cleanup')
            ->and(is_file($snapshot->path))->toBeFalse()
            ->and(is_dir($directory))->toBeTrue()
            ->and(is_file($derivative))->toBeTrue();

        unlink($derivative);
        $snapshot->cleanup();
        $snapshot->cleanup();

        expect(is_dir($directory))->toBeFalse();
    } finally {
        @unlink($derivative);
        @unlink($snapshot->path);
        @rmdir($directory);
    }
});
