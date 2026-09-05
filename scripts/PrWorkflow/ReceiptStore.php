<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Dev\PrWorkflow;

use JsonException;

final readonly class ReceiptStore
{
    public function __construct(
        private string $repositoryRoot,
        private CommandRunner $runner,
    ) {}

    /**
     * @param  array<string, mixed>  $receipt
     */
    public function write(string $sha, array $receipt): string
    {
        $path = $this->path($sha);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new WorkflowException('Unable to create the private verification receipt directory.');
        }

        chmod($directory, 0700);
        $temporary = $path.'.'.getmypid().'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = @fopen($temporary, 'x+b');

        if ($handle === false) {
            throw new WorkflowException('Unable to create a private temporary verification receipt.');
        }

        try {
            chmod($temporary, 0600);
            $contents = json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;

            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new WorkflowException('Unable to write the complete verification receipt.');
            }

            if (function_exists('fsync') && ! fsync($handle)) {
                throw new WorkflowException('Unable to synchronize the verification receipt.');
            }

            fclose($handle);
            $handle = null;

            if (! rename($temporary, $path)) {
                throw new WorkflowException('Unable to atomically publish the verification receipt.');
            }

            chmod($path, 0600);
        } catch (JsonException $exception) {
            throw new WorkflowException('Unable to encode the verification receipt.', previous: $exception);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $sha): array
    {
        $path = $this->path($sha);

        if (! is_file($path) || is_link($path)) {
            throw new WorkflowException('No private verification receipt exists for the approved SHA.');
        }

        $permissions = fileperms($path);

        if ($permissions === false || ($permissions & 0777) !== 0600) {
            throw new WorkflowException('The verification receipt does not have mode 0600.');
        }

        try {
            $receipt = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new WorkflowException('The verification receipt is not valid JSON.', previous: $exception);
        }

        if (! is_array($receipt)) {
            throw new WorkflowException('The verification receipt is not a JSON object.');
        }

        foreach (array_keys($receipt) as $key) {
            if (! is_string($key)) {
                throw new WorkflowException('The verification receipt contains a non-string field.');
            }
        }

        /** @var array<string, mixed> $receipt */
        return $receipt;
    }

    public function path(string $sha): string
    {
        $result = $this->runner->run([
            'git',
            'rev-parse',
            '--git-path',
            'laravel-document-extraction/pr-check/'.$sha.'.json',
        ]);

        if ($result->exitCode !== 0 || trim($result->stdout) === '') {
            throw new WorkflowException('Unable to resolve the Git administrative receipt path.');
        }

        $path = trim($result->stdout);

        if (! str_starts_with($path, '/')) {
            $path = $this->repositoryRoot.'/'.$path;
        }

        return $path;
    }
}
