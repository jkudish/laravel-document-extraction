<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use RuntimeException;

final class InvalidAiOutputException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $path = null,
    ) {
        parent::__construct($message);
    }
}
