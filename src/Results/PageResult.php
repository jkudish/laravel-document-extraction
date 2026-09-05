<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Results;

use InvalidArgumentException;

final readonly class PageResult
{
    public function __construct(
        public int $page,
        public ?string $text = null,
        private bool $complete = true,
        public ?ExtractionError $error = null,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('A page result requires a positive original page number.');
        }

        if ($error !== null && $complete) {
            throw new InvalidArgumentException('A page result with a technical error cannot be complete.');
        }

        if ($complete && $text === null) {
            throw new InvalidArgumentException('A complete page result requires text, including an empty string for a blank page.');
        }
    }

    public function complete(): bool
    {
        return $this->complete;
    }
}
