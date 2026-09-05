<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Results;

use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class DocumentResult
{
    /** @var Collection<int, int>|null */
    public ?Collection $pages;

    /**
     * @param  iterable<int, mixed>|null  $pages
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        ?iterable $pages = null,
        public ?array $data = null,
        public ?string $text = null,
        private bool $complete = true,
        public ?ExtractionError $error = null,
    ) {
        if ($data !== null && $text !== null) {
            throw new InvalidArgumentException('A document result cannot contain both structured data and text.');
        }

        if ($error !== null && $complete) {
            throw new InvalidArgumentException('A document result with a technical error cannot be complete.');
        }

        if ($error !== null && $data !== null) {
            throw new InvalidArgumentException('A failed structured document cannot retain unvalidated data.');
        }

        if ($complete && $data === null && $text === null) {
            throw new InvalidArgumentException('A complete document result requires structured data or text.');
        }

        $this->pages = $pages === null ? null : self::pages($pages);
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    /** @param iterable<int, mixed> $pages
     * @return Collection<int, int>
     */
    private static function pages(iterable $pages): Collection
    {
        $normalized = [];

        foreach ($pages as $page) {
            if (! is_int($page) || $page < 1 || isset($normalized[$page])) {
                throw new InvalidArgumentException('Document pages must be unique positive integers.');
            }

            $normalized[$page] = true;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Paginated document provenance requires at least one original page.');
        }

        $values = array_keys($normalized);
        sort($values, SORT_NUMERIC);

        /** @var Collection<int, int> $pageCollection */
        $pageCollection = collect($values)->values();

        return $pageCollection;
    }
}
