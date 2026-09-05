<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Dev\PrWorkflow;

interface CommandRunner
{
    /**
     * @param  non-empty-list<string>  $command
     * @param  array<string, string>|null  $environment
     */
    public function run(array $command, ?array $environment = null): CommandResult;
}
