<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Exceptions;

use Jkudish\DocumentExtraction\Results\ExtractionResult;

final class AiExecutionException extends ExtractionException
{
    public ?ExtractionResult $partialResult = null;

    public function withPartialResult(ExtractionResult $result): self
    {
        $this->partialResult ??= $result;

        return $this;
    }
}
