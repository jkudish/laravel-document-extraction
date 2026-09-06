<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Exceptions;

use Jkudish\DocumentExtraction\Results\ExtractionResult;
use RuntimeException;
use Throwable;

abstract class ExtractionException extends RuntimeException
{
    public ?ExtractionResult $partialResult = null;

    final protected function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function make(string $errorCode, string $message, ?Throwable $previous = null): static
    {
        return new static($errorCode, $message, $previous);
    }

    public function withPartialResult(ExtractionResult $result): static
    {
        $this->partialResult ??= $result;

        return $this;
    }
}
