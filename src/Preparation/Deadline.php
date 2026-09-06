<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Preparation;

use Jkudish\DocumentExtraction\Exceptions\PreparationException;

final readonly class Deadline
{
    private function __construct(private int $expiresAtNanoseconds) {}

    public static function afterSeconds(int $seconds): self
    {
        return new self(hrtime(true) + ($seconds * 1_000_000_000));
    }

    public function remainingSeconds(int $maximum): int
    {
        $remaining = $this->expiresAtNanoseconds - hrtime(true);

        if ($remaining <= 0) {
            throw PreparationException::make(
                'invocation_deadline_exceeded',
                'The document preparation deadline was exceeded.',
            );
        }

        return max(1, min($maximum, (int) ceil($remaining / 1_000_000_000)));
    }

    public function ensureRemaining(): void
    {
        $this->remainingSeconds(PHP_INT_MAX);
    }
}
