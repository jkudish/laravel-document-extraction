<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Source;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Jkudish\DocumentExtraction\Exceptions\SourceException;
use Throwable;

final readonly class SourceInput
{
    private function __construct(
        public SourceType $type,
        private mixed $value,
        private ?string $disk = null,
        public ?string $mimeTypeHint = null,
    ) {}

    public static function path(string $path): self
    {
        return new self(SourceType::Path, $path);
    }

    public static function storage(string $path, ?string $disk): self
    {
        return new self(SourceType::Storage, $path, $disk);
    }

    public static function upload(UploadedFile $file): self
    {
        return new self(SourceType::Upload, $file, mimeTypeHint: self::normalizeMimeType($file->getClientMimeType()));
    }

    public static function stream(mixed $stream, ?string $mimeType): self
    {
        return new self(SourceType::Stream, $stream, mimeTypeHint: self::normalizeMimeType($mimeType));
    }

    public static function contents(string $contents, ?string $mimeType): self
    {
        return new self(SourceType::String, $contents, mimeTypeHint: self::normalizeMimeType($mimeType));
    }

    public function reference(): ?string
    {
        return match ($this->type) {
            SourceType::Path, SourceType::Storage => is_string($this->value) ? $this->value : null,
            SourceType::Upload => $this->value instanceof UploadedFile ? $this->value->getClientOriginalName() : null,
            SourceType::Stream, SourceType::String => null,
        };
    }

    public function knownSize(): ?int
    {
        return $this->type === SourceType::String && is_string($this->value)
            ? strlen($this->value)
            : null;
    }

    /** @return array{resource, bool} */
    public function open(FilesystemFactory $filesystems): array
    {
        return match ($this->type) {
            SourceType::Path => [$this->openRegularFile($this->stringValue()), true],
            SourceType::Storage => $this->openStorage($filesystems),
            SourceType::Upload => [$this->openUpload(), true],
            SourceType::Stream => [$this->readableStream($this->value), false],
            SourceType::String => [$this->openString(), true],
        };
    }

    /** @return array{resource, true} */
    private function openStorage(FilesystemFactory $filesystems): array
    {
        try {
            $stream = $filesystems->disk($this->disk)->readStream($this->stringValue());
        } catch (Throwable $exception) {
            throw SourceException::make('invalid_source', 'The storage source could not be opened for reading.', $exception);
        }

        try {
            return [$this->readableStream($stream), true];
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $exception;
        }
    }

    /** @return resource */
    private function openUpload()
    {
        if (! $this->value instanceof UploadedFile || ! $this->value->isValid()) {
            throw SourceException::make('invalid_source', 'The uploaded source is not a valid readable upload.');
        }

        return $this->openRegularFile($this->value->getPathname());
    }

    /** @return resource */
    private function openRegularFile(string $path)
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1) {
            throw SourceException::make('invalid_source', 'The local source must be a regular readable file path.');
        }

        $resolved = realpath($path);
        $stat = $resolved === false ? false : @stat($resolved);

        if ($resolved === false || ! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ! is_readable($resolved)) {
            throw SourceException::make('invalid_source', 'The local source must be a regular readable file path.');
        }

        $stream = @fopen($resolved, 'rb');

        if (! is_resource($stream)) {
            throw SourceException::make('invalid_source', 'The local source could not be opened for reading.');
        }

        $openedStat = fstat($stream);

        if (! is_array($openedStat) || ($openedStat['mode'] & 0170000) !== 0100000) {
            fclose($stream);

            throw SourceException::make('invalid_source', 'The local source must remain a regular file when opened.');
        }

        return $stream;
    }

    /** @return resource */
    private function readableStream(mixed $stream)
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw SourceException::make('invalid_source', 'The stream source must be an open readable PHP stream.');
        }

        $metadata = stream_get_meta_data($stream);
        $mode = $metadata['mode'];

        if (($mode[0] ?? '') !== 'r' && ! str_contains($mode, '+')) {
            throw SourceException::make('invalid_source', 'The stream source must be open for reading.');
        }

        return $stream;
    }

    /** @return resource */
    private function openString()
    {
        $stream = fopen('php://memory', 'w+b');

        if (! is_resource($stream)) {
            throw SourceException::make('invalid_source', 'The string source could not be prepared for reading.');
        }

        $contents = $this->stringValue();
        $offset = 0;

        while ($offset < strlen($contents)) {
            $written = fwrite($stream, substr($contents, $offset, 8192));

            if (! is_int($written) || $written < 1) {
                fclose($stream);

                throw SourceException::make('invalid_source', 'The string source could not be prepared for reading.');
            }

            $offset += $written;
        }

        rewind($stream);

        return $stream;
    }

    private function stringValue(): string
    {
        if (! is_string($this->value)) {
            throw SourceException::make('invalid_source', 'The source has an invalid internal representation.');
        }

        return $this->value;
    }

    private static function normalizeMimeType(?string $mimeType): ?string
    {
        if ($mimeType === null) {
            return null;
        }

        $normalized = strtolower(trim(explode(';', $mimeType, 2)[0]));

        if (preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~', $normalized) !== 1) {
            throw ConfigurationException::make('invalid_mime_type', 'The MIME type hint is malformed.');
        }

        return $normalized;
    }
}
