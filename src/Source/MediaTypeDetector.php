<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Source;

use finfo;
use Jkudish\DocumentExtraction\Exceptions\SourceException;

final class MediaTypeDetector
{
    /** @var list<string> */
    private const array SUPPORTED = [
        'application/json',
        'application/pdf',
        'application/xml',
        'image/avif',
        'image/bmp',
        'image/gif',
        'image/heic',
        'image/heif',
        'image/jpeg',
        'image/png',
        'image/tiff',
        'image/webp',
        'text/csv',
        'text/html',
        'text/plain',
        'text/xml',
    ];

    /** @var list<string> */
    private const array AMBIGUOUS_TEXT_HINTS = [
        'application/json',
        'application/xml',
        'text/csv',
        'text/html',
        'text/plain',
        'text/xml',
    ];

    public function detect(string $path, ?string $hint, bool $containsBinaryControl): string
    {
        $prefix = file_get_contents($path, false, null, 0, 8192);

        if (! is_string($prefix)) {
            throw SourceException::make('invalid_source', 'The private source snapshot could not be inspected.');
        }

        if (str_starts_with($prefix, '%PDF-')) {
            return 'application/pdf';
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $detected = is_string($detected) ? strtolower($detected) : 'application/octet-stream';
        $detected = match ($detected) {
            'application/x-empty' => 'text/plain',
            'application/x-json' => 'application/json',
            'application/x-ndjson' => 'application/json',
            'application/x-xml' => 'application/xml',
            'image/x-ms-bmp', 'image/x-bmp' => 'image/bmp',
            default => $detected,
        };

        if (in_array($detected, self::AMBIGUOUS_TEXT_HINTS, true) || str_starts_with($detected, 'text/')) {
            if ($containsBinaryControl) {
                throw SourceException::make('unsupported_format', 'The source bytes do not identify a supported document format.');
            }

            if ($hint !== null && in_array($hint, self::AMBIGUOUS_TEXT_HINTS, true)) {
                return $hint;
            }

            return in_array($detected, self::SUPPORTED, true) ? $detected : 'text/plain';
        }

        if (in_array($detected, self::SUPPORTED, true)) {
            return $detected;
        }

        throw SourceException::make('unsupported_format', 'The source bytes do not identify a supported document format.');
    }
}
