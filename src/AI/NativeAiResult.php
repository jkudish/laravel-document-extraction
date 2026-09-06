<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

final readonly class NativeAiResult
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public ?string $text = null,
        public ?array $data = null,
    ) {}
}
