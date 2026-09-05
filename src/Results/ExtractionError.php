<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Results;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/** @implements Arrayable<string, bool|list<int>|string|null> */
final readonly class ExtractionError implements Arrayable
{
    /** @var list<int> */
    public array $pages;

    /** @param array<mixed> $pages */
    public function __construct(
        public string $code,
        public string $message,
        array $pages = [],
        public ?string $path = null,
        public bool $retryable = false,
    ) {
        if (trim($code) === '' || trim($message) === '') {
            throw new InvalidArgumentException('Extraction errors require a stable code and safe message.');
        }

        $normalized = [];

        foreach ($pages as $page) {
            if (! is_int($page) || $page < 1 || isset($normalized[$page])) {
                throw new InvalidArgumentException('Extraction error pages must be unique positive integers.');
            }

            $normalized[$page] = true;
        }

        $values = array_keys($normalized);
        sort($values, SORT_NUMERIC);
        $this->pages = $values;
    }

    /** @return array{code: string, message: string, pages: list<int>, path: ?string, retryable: bool} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'pages' => $this->pages,
            'path' => $this->path,
            'retryable' => $this->retryable,
        ];
    }
}
