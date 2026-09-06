<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Preparation;

use Composer\Autoload\ClassLoader;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Exceptions\PreparationException;
use JsonException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\ExecutableFinder;

final readonly class WorkerRunner
{
    public function __construct(private Factory $process) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $configuredBinaries
     * @return array<string, mixed>
     */
    public function run(
        string $mode,
        array $payload,
        array $configuredBinaries,
        Deadline $deadline,
        int $operationTimeout,
        int $nativeMemoryBytes,
        int $phpMemoryBytes,
        int $fileSizeLimit,
        string $workspace,
        int $protocolOutputLimit,
    ): array {
        $workspace = realpath($workspace);

        if ($workspace === false || ! is_dir($workspace)) {
            throw PreparationException::make('preparation_failed', 'The private worker workspace is unavailable.');
        }

        $binaries = [];

        foreach ($configuredBinaries as $name => $configured) {
            $binaries[$name] = $this->resolveBinary($configured);
        }

        $worker = realpath(dirname(__DIR__, 2).'/resources/workers/document-preparation.php');
        $autoload = $this->autoloadPath();

        if ($worker === false || ! is_file($worker)) {
            throw PreparationException::make('unsupported_runtime', 'The isolated document worker is unavailable.');
        }

        try {
            $input = json_encode([
                'mode' => $mode,
                'payload' => $payload,
                'binaries' => $binaries,
                'timeout' => $deadline->remainingSeconds($operationTimeout),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw PreparationException::make('preparation_failed', 'The isolated worker request could not be encoded.', $exception);
        }

        $environment = $this->sanitizedEnvironment($workspace);
        $timeout = $deadline->remainingSeconds($operationTimeout + 2);
        $command = [
            $binaries['prlimit'],
            '--as='.$nativeMemoryBytes,
            '--fsize='.$fileSizeLimit,
            '--',
            $binaries['php'],
            '-d',
            'memory_limit='.$phpMemoryBytes,
            '-d',
            'display_errors=stderr',
            '-d',
            'log_errors=0',
            '-d',
            'auto_prepend_file='.$autoload,
            $worker,
        ];

        try {
            $result = $this->process
                ->newPendingProcess()
                ->path($workspace)
                ->env($environment)
                ->input($input)
                ->timeout($timeout)
                ->run($command);
        } catch (ProcessTimedOutException $exception) {
            throw PreparationException::make('parser_timeout', 'A bounded document preparation operation timed out.', $exception);
        } catch (ProcessSignaledException $exception) {
            throw PreparationException::make('resource_limit_exceeded', 'A bounded document preparation worker exceeded its resource limit.', $exception);
        }

        $output = $result->output();

        if (strlen($output) > $protocolOutputLimit) {
            throw PreparationException::make('output_limit_exceeded', 'Document preparation exceeded the configured output byte limit.');
        }

        if (! $result->successful()) {
            throw PreparationException::make('resource_limit_exceeded', 'A bounded document preparation worker failed safely.');
        }

        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw PreparationException::make('preparation_failed', 'The isolated document worker returned an invalid response.', $exception);
        }

        if (! is_array($decoded) || ! is_bool($decoded['ok'] ?? null)) {
            throw PreparationException::make('preparation_failed', 'The isolated document worker returned an invalid response.');
        }

        if ($decoded['ok'] !== true) {
            $code = is_string($decoded['code'] ?? null) ? $decoded['code'] : 'preparation_failed';
            $message = match ($code) {
                'encrypted_pdf' => 'Encrypted PDF documents are not supported.',
                'invalid_pdf' => 'The PDF document is malformed or unsupported.',
                'active_pdf' => 'Active PDF documents are not supported.',
                'page_limit_exceeded' => 'The document exceeds the configured physical page limit.',
                'pixel_limit_exceeded' => 'A document page exceeds the configured decoded pixel limit.',
                'unsupported_codec' => 'The runtime cannot decode the document image format.',
                'animated_image' => 'Animated or multi-frame images are not supported for this format.',
                'output_limit_exceeded' => 'Document preparation exceeded the configured output byte limit.',
                'temporary_limit_exceeded' => 'Document preparation exceeded the configured temporary byte limit.',
                'parser_timeout' => 'A bounded document preparation operation timed out.',
                'resource_limit_exceeded' => 'A bounded document preparation worker exceeded its resource limit.',
                default => 'The document could not be prepared safely.',
            };

            throw PreparationException::make($code, $message);
        }

        $data = $decoded['data'] ?? null;

        if (! is_array($data)) {
            throw PreparationException::make('preparation_failed', 'The isolated document worker returned an invalid response.');
        }

        $deadline->ensureRemaining();

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function resolveBinary(string $configured): string
    {
        if ($configured === '' || str_contains($configured, "\0")) {
            throw PreparationException::make('unsupported_runtime', 'A required document preparation executable is unavailable.');
        }

        $located = str_contains($configured, '/')
            ? $configured
            : (new ExecutableFinder)->find($configured, null, ['/usr/local/bin', '/usr/bin', '/bin']);
        $resolved = is_string($located) ? realpath($located) : false;

        if ($resolved === false || ! is_file($resolved) || ! is_executable($resolved)) {
            throw PreparationException::make('unsupported_runtime', 'A required document preparation executable is unavailable.');
        }

        return $resolved;
    }

    /** @return array<string, string|false> */
    private function sanitizedEnvironment(string $workspace): array
    {
        $environment = [];

        foreach (array_keys($_SERVER + $_ENV + getenv()) as $name) {
            if (is_string($name)) {
                $environment[$name] = false;
            }
        }

        $environment['HOME'] = $workspace;
        $environment['PATH'] = '/usr/local/bin:/usr/bin:/bin';
        $environment['TMPDIR'] = $workspace;
        $environment['MAGICK_TMPDIR'] = $workspace;
        $environment['LANG'] = 'C.UTF-8';
        $environment['LC_ALL'] = 'C.UTF-8';

        return $environment;
    }

    private function autoloadPath(): string
    {
        $source = realpath((string) (new \ReflectionClass(DocumentExtraction::class))->getFileName());

        foreach (ClassLoader::getRegisteredLoaders() as $vendorDirectory => $loader) {
            $loaded = $loader->findFile(DocumentExtraction::class);

            if ($source !== false && is_string($loaded) && realpath($loaded) === $source) {
                $autoload = realpath($vendorDirectory.'/autoload.php');

                if ($autoload !== false) {
                    return $autoload;
                }
            }
        }

        throw PreparationException::make('unsupported_runtime', 'The isolated document worker autoloader is unavailable.');
    }
}
