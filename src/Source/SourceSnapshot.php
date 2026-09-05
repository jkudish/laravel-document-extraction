<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Source;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Jkudish\DocumentExtraction\Exceptions\SourceException;
use Throwable;

final class SourceSnapshot
{
    private bool $cleaned = false;

    private function __construct(
        public readonly string $path,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $mediaType,
        public readonly ?int $pageCount = null,
    ) {}

    public static function capture(
        SourceInput $source,
        FilesystemFactory $filesystems,
        int $sourceByteLimit,
        int $temporaryByteLimit,
    ): self {
        $directory = sys_get_temp_dir().'/laravel-document-extraction-'.bin2hex(random_bytes(16));
        $limit = min($sourceByteLimit, $temporaryByteLimit);
        $knownSize = $source->knownSize();

        if ($knownSize !== null && $knownSize > $limit) {
            throw SourceException::make('limit_exceeded', 'The source exceeds the configured byte limit.');
        }

        if (! mkdir($directory, 0700)) {
            throw SourceException::make('invalid_source', 'A private source snapshot directory could not be created.');
        }

        if (! chmod($directory, 0700)) {
            @rmdir($directory);

            throw SourceException::make('invalid_source', 'A private source snapshot directory could not be secured.');
        }

        $path = $directory.'/source';
        $destination = null;
        $input = null;
        $ownsInput = false;

        try {
            $destination = fopen($path, 'x+b');

            if (! is_resource($destination) || ! chmod($path, 0600)) {
                throw SourceException::make('invalid_source', 'A private source snapshot could not be created.');
            }

            [$input, $ownsInput] = $source->open($filesystems);
            $hash = hash_init('sha256');
            $size = 0;
            $containsBinaryControl = false;

            while (! feof($input)) {
                $chunk = @fread($input, 8192);

                if (! is_string($chunk)) {
                    throw SourceException::make('invalid_source', 'The source stream could not be read.');
                }

                if ($chunk === '') {
                    if (feof($input)) {
                        break;
                    }

                    throw SourceException::make('invalid_source', 'The source stream stopped before reaching EOF.');
                }

                $size += strlen($chunk);

                if ($size > $limit) {
                    throw SourceException::make('limit_exceeded', 'The source exceeds the configured byte limit.');
                }

                hash_update($hash, $chunk);
                $containsBinaryControl = $containsBinaryControl
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $chunk) === 1;
                self::write($destination, $chunk);
            }

            if (! fflush($destination)) {
                throw SourceException::make('invalid_source', 'The private source snapshot could not be finalized.');
            }

            if (! fclose($destination)) {
                throw SourceException::make('invalid_source', 'The private source snapshot could not be finalized.');
            }

            $destination = null;

            if ($ownsInput) {
                fclose($input);
                $input = null;
            }

            $mediaType = (new MediaTypeDetector)->detect($path, $source->mimeTypeHint, $containsBinaryControl);

            return new self($path, hash_final($hash), $size, $mediaType);
        } catch (Throwable $exception) {
            if (is_resource($destination)) {
                fclose($destination);
            }

            if ($ownsInput && is_resource($input)) {
                fclose($input);
            }

            self::removeSnapshot($path);

            throw $exception;
        }
    }

    public function isPaginated(): bool
    {
        return $this->mediaType === 'application/pdf' || str_starts_with($this->mediaType, 'image/');
    }

    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        self::removeSnapshot($this->path);
        $this->cleaned = true;
    }

    public function __destruct()
    {
        try {
            $this->cleanup();
        } catch (SourceException) {
            // Explicit cleanup reports failure; destruction can only retry best-effort.
        }
    }

    private static function removeSnapshot(string $path): void
    {
        @unlink($path);
        $directory = dirname($path);

        if (! @rmdir($directory) && is_dir($directory)) {
            throw SourceException::make('cleanup_failed', 'Private source snapshot cleanup could not be completed.');
        }
    }

    /** @param resource $destination */
    private static function write($destination, string $contents): void
    {
        $offset = 0;

        while ($offset < strlen($contents)) {
            $written = @fwrite($destination, substr($contents, $offset));

            if (! is_int($written) || $written < 1) {
                throw SourceException::make('invalid_source', 'The private source snapshot could not be written.');
            }

            $offset += $written;
        }
    }
}
