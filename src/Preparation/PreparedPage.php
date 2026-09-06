<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Preparation;

final readonly class PreparedPage
{
    public function __construct(
        public int $page,
        public ?string $text,
        public ?string $visualPath,
        public bool $needsOcr,
        public ?int $visualBytes = null,
    ) {}
}
