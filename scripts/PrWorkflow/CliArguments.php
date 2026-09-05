<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Dev\PrWorkflow;

final class CliArguments
{
    /**
     * @param  list<string>  $arguments
     * @return array{operation: 'analyse'|'check'|'signoff', options: array<string, string>}
     */
    public static function parse(array $arguments): array
    {
        $operation = array_shift($arguments);
        $allowed = match ($operation) {
            'analyse' => [],
            'check' => ['base'],
            'signoff' => ['approved-sha'],
            default => throw new WorkflowException('Unknown PR workflow operation.'),
        };
        $options = [];
        $delimiterSeen = false;

        while ($arguments !== []) {
            $name = array_shift($arguments);

            if ($name === '--' && ! $delimiterSeen) {
                $delimiterSeen = true;

                continue;
            }

            if (preg_match('/^--([a-z][a-z-]*)$/D', $name, $matches) !== 1) {
                throw new WorkflowException('Every workflow option must use --name value syntax.');
            }

            $key = $matches[1];

            if (! in_array($key, $allowed, true)) {
                throw new WorkflowException('Unknown --'.$key.' option for '.$operation.'.');
            }

            if (array_key_exists($key, $options)) {
                throw new WorkflowException('Duplicate --'.$key.' option.');
            }

            $value = array_shift($arguments);

            if (! is_string($value) || $value === '' || str_starts_with($value, '--')) {
                throw new WorkflowException('--'.$key.' requires one non-option value.');
            }

            $options[$key] = $value;
        }

        if ($operation === 'signoff' && ! isset($options['approved-sha'])) {
            throw new WorkflowException('pr:signoff requires --approved-sha with a full SHA.');
        }

        return ['operation' => $operation, 'options' => $options];
    }
}
