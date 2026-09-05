<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Dev\PrWorkflow;

use RuntimeException;

final readonly class NativeCommandRunner implements CommandRunner
{
    public function __construct(private string $repositoryRoot) {}

    public function run(array $command, ?array $environment = null): CommandResult
    {
        $stdoutPath = tempnam(sys_get_temp_dir(), 'lde-pr-stdout-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'lde-pr-stderr-');

        if ($stdoutPath === false || $stderrPath === false) {
            throw new RuntimeException('Unable to create private command output files.');
        }

        chmod($stdoutPath, 0600);
        chmod($stderrPath, 0600);

        try {
            $pipes = [];
            $process = proc_open(
                $command,
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $stdoutPath, 'w'],
                    2 => ['file', $stderrPath, 'w'],
                ],
                $pipes,
                $this->repositoryRoot,
                $environment,
                ['bypass_shell' => true],
            );

            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start verification command.');
            }

            $exitCode = proc_close($process);

            return new CommandResult(
                $exitCode,
                (string) file_get_contents($stdoutPath),
                (string) file_get_contents($stderrPath),
            );
        } finally {
            @unlink($stdoutPath);
            @unlink($stderrPath);
        }
    }
}
