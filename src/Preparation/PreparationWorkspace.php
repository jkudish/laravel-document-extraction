<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Preparation;

use FilesystemIterator;
use Jkudish\DocumentExtraction\Exceptions\PreparationException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PreparationWorkspace
{
    private bool $cleaned = false;

    private function __construct(
        public readonly string $path,
        private readonly int $sourceBytes,
        private readonly int $limit,
    ) {}

    public static function create(string $sourcePath, int $sourceBytes, int $limit): self
    {
        $parent = realpath(dirname($sourcePath));

        if ($parent === false) {
            throw PreparationException::make('preparation_failed', 'The private preparation parent directory is unavailable.');
        }

        $path = $parent.'/preparation-'.bin2hex(random_bytes(12));

        if (! mkdir($path, 0700) || ! chmod($path, 0700)) {
            @rmdir($path);

            throw PreparationException::make('preparation_failed', 'A private preparation workspace could not be created.');
        }

        return new self($path, $sourceBytes, $limit);
    }

    public function outputPath(string $name): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name) !== 1) {
            throw PreparationException::make('preparation_failed', 'An invalid private derivative name was requested.');
        }

        return $this->path.'/'.$name;
    }

    public function remainingBytes(): int
    {
        $remaining = $this->limit - $this->activeBytes();

        if ($remaining < 1) {
            throw PreparationException::make('temporary_limit_exceeded', 'Document preparation exceeded the configured temporary byte limit.');
        }

        return $remaining;
    }

    public function enforceBudget(): void
    {
        if ($this->activeBytes() > $this->limit) {
            throw PreparationException::make('temporary_limit_exceeded', 'Document preparation exceeded the configured temporary byte limit.');
        }
    }

    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        clearstatcache(true);

        if (file_exists($this->path) || is_link($this->path)) {
            if (! is_dir($this->path) || realpath($this->path) !== $this->path) {
                throw PreparationException::make('cleanup_failed', 'Private preparation cleanup encountered a replaced workspace path.');
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                /** @var SplFileInfo $item */
                $removed = $item->isDir() && ! $item->isLink()
                    ? @rmdir($item->getPathname())
                    : @unlink($item->getPathname());

                if (! $removed) {
                    throw PreparationException::make('cleanup_failed', 'Private preparation cleanup could not be completed.');
                }
            }

            if (! @rmdir($this->path)) {
                throw PreparationException::make('cleanup_failed', 'Private preparation cleanup could not be completed.');
            }
        }

        $this->cleaned = true;
    }

    private function activeBytes(): int
    {
        $bytes = $this->sourceBytes;

        if (! is_dir($this->path)) {
            return $bytes;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isFile() && ! $item->isLink()) {
                $bytes += $item->getSize();
            }
        }

        return $bytes;
    }
}
