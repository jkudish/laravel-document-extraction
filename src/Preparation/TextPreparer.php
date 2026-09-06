<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Preparation;

use Jkudish\DocumentExtraction\Exceptions\PreparationException;
use XMLReader;

final class TextPreparer
{
    public function prepare(string $path, string $mediaType, int $outputLimit, Deadline $deadline): string
    {
        $size = filesize($path);

        if (! is_int($size) || $size > $outputLimit) {
            throw PreparationException::make('output_limit_exceeded', 'Document preparation exceeded the configured output byte limit.');
        }

        $deadline->ensureRemaining();
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw PreparationException::make('invalid_text', 'The text document could not be read safely.');
        }

        $contents = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw PreparationException::make('invalid_text', 'The text document must contain valid UTF-8.');
        }

        if (strlen($contents) > $outputLimit) {
            throw PreparationException::make('output_limit_exceeded', 'Document preparation exceeded the configured output byte limit.');
        }

        match ($mediaType) {
            'application/json' => $this->validateJson($contents),
            'application/xml', 'text/xml' => $this->validateXml($contents, $deadline),
            'text/plain', 'text/csv', 'text/html' => null,
            default => throw PreparationException::make('unsupported_format', 'The text document format is not supported.'),
        };

        $deadline->ensureRemaining();

        return $contents;
    }

    private function validateJson(string $contents): void
    {
        if (! json_validate($contents)) {
            throw PreparationException::make('invalid_json', 'The JSON document is not syntactically valid.');
        }
    }

    private function validateXml(string $contents, Deadline $deadline): void
    {
        if (preg_match('/<!DOCTYPE\b/i', $contents) === 1 || preg_match('/<!ENTITY\b/i', $contents) === 1) {
            throw PreparationException::make('unsafe_xml', 'XML document type and entity declarations are not supported.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;

        try {
            $valid = $reader->XML($contents, null, LIBXML_NONET | LIBXML_COMPACT);

            if ($valid) {
                $nodes = 0;

                while ($reader->read()) {
                    if (++$nodes % 1024 === 0) {
                        $deadline->ensureRemaining();
                    }
                }
            }

            $errors = libxml_get_errors();

            if (! $valid || $errors !== []) {
                throw PreparationException::make('invalid_xml', 'The XML document is not syntactically valid.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
