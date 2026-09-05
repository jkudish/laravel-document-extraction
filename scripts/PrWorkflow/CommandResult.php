<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Dev\PrWorkflow;

final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
    ) {}

    public function output(): string
    {
        return trim($this->stdout.$this->stderr);
    }
}
