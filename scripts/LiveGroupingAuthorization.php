<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Dev;

use Jkudish\DocumentExtraction\Dev\PrWorkflow\CommandRunner;
use Jkudish\DocumentExtraction\Tests\Support\LiveGroupingModels;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class LiveGroupingAuthorization
{
    public const string CAPABILITY_ENV = 'LDE_LIVE_GROUPING_CAPABILITY';

    public const string LEDGER_ENV = 'LDE_LIVE_GROUPING_AUTHORIZATION';

    public const string AUTHENTICATED_ENV = 'LDE_LIVE_GROUPING_AUTHENTICATED';

    public static function path(string $repositoryRoot, CommandRunner $runner, string $stage): string
    {
        $result = $runner->run([
            'git',
            'rev-parse',
            '--git-path',
            self::relativePath($stage),
        ]);

        if ($result->exitCode !== 0 || trim($result->stdout) === '') {
            throw new RuntimeException('Unable to resolve the private live grouping authorization path.');
        }

        $path = trim($result->stdout);

        return str_starts_with($path, '/') ? $path : $repositoryRoot.'/'.$path;
    }

    /** @param array<string, mixed> $authorization
     * @return array<string, mixed>
     */
    public static function signed(array $authorization, string $key): array
    {
        unset($authorization['authentication']);
        $authorization['authentication'] = hash_hmac('sha256', self::encoded($authorization), $key);

        return $authorization;
    }

    /** @param array<string, mixed> $authorization */
    public static function assertAuthentic(array $authorization, string $key): void
    {
        $authentication = $authorization['authentication'] ?? null;
        unset($authorization['authentication']);

        if (! is_string($authentication)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $authentication)
            || ! hash_equals($authentication, hash_hmac('sha256', self::encoded($authorization), $key))) {
            throw new RuntimeException('The live grouping authorization ledger is not authentic.');
        }
    }

    public static function key(string $ledgerPath, bool $create): string
    {
        $path = dirname($ledgerPath).'/authority.key';

        if (! is_file($path)) {
            if (! $create || is_link($path)) {
                throw new RuntimeException('The private live grouping authorization key is unavailable.');
            }

            $directory = dirname($path);

            if ((! is_dir($directory) && ! mkdir($directory, 0700, true)) || is_link($directory)) {
                throw new RuntimeException('Unable to create the private live grouping authorization directory.');
            }

            $handle = @fopen($path, 'x+b');

            if ($handle === false) {
                throw new RuntimeException('Unable to create the private live grouping authorization key.');
            }

            $persisted = false;

            try {
                $contents = bin2hex(random_bytes(32)).PHP_EOL;

                if (! chmod($path, 0600)
                    || fwrite($handle, $contents) !== strlen($contents)
                    || ! fflush($handle)
                    || (function_exists('fsync') && ! fsync($handle))) {
                    throw new RuntimeException('Unable to persist the private live grouping authorization key.');
                }

                $persisted = true;
            } finally {
                fclose($handle);

                if (! $persisted && is_file($path) && ! is_link($path)) {
                    unlink($path);
                }
            }
        }

        $permissions = is_file($path) ? fileperms($path) : false;

        if (is_link($path)
            || ! is_file($path)
            || $permissions === false
            || ($permissions & 0777) !== 0600) {
            throw new RuntimeException('The private live grouping authorization key is unsafe.');
        }

        $key = trim((string) file_get_contents($path));

        if (! preg_match('/\A[0-9a-f]{64}\z/', $key)) {
            throw new RuntimeException('The private live grouping authorization key is invalid.');
        }

        return $key;
    }

    public static function claim(string $repositoryRoot): void
    {
        $stageId = getenv('LDE_LIVE_GROUPING_STAGE');
        $model = getenv('LDE_LIVE_GROUPING_MODEL');
        $fixture = getenv('LDE_LIVE_GROUPING_FIXTURE');
        $repetition = getenv('LDE_LIVE_GROUPING_REPETITION');
        $trial = getenv('LDE_LIVE_GROUPING_TRIAL');
        $key = getenv('OPENROUTER_API_KEY');
        $capability = getenv(self::CAPABILITY_ENV);
        $path = getenv(self::LEDGER_ENV);
        $authenticated = getenv(self::AUTHENTICATED_ENV) === '1';
        $offline = getenv('LDE_LIVE_GROUPING_OFFLINE') === '1';

        if (! is_string($stageId)
            || ! is_string($model)
            || ! is_string($fixture)
            || ! is_string($repetition)
            || ! ctype_digit($repetition)
            || ! is_string($trial)
            || $trial === ''
            || ! is_string($key)
            || $key === ''
            || ! is_string($capability)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $capability)
            || ! is_string($path)
            || $path === '') {
            throw new RuntimeException('The live grouping benchmark lacks a private parent capability.');
        }

        $stage = LiveGroupingModels::stage($stageId);
        $expectedTrial = $model.'|'.$fixture.'|repeat-'.$repetition;

        if (! $offline) {
            if (! $authenticated
                || ! hash_equals(self::nativePath($repositoryRoot, $stageId), $path)
                || ! in_array($model, LiveGroupingModels::SURVIVOR_IDS, true)
                || ! in_array($fixture, $stage['fixture_ids'], true)
                || (int) $repetition < 1
                || (int) $repetition > $stage['repetitions']
                || ! hash_equals($expectedTrial, $trial)) {
                throw new RuntimeException('The live grouping benchmark lacks an authenticated parent authorization.');
            }
        }

        $authorization = self::read($path, requirePrivateMode: $authenticated);

        if ($authenticated) {
            self::assertAuthentic($authorization, self::key($path, false));
        }

        $pending = $authorization['pending'] ?? null;
        if (! is_array($pending)
            || array_is_list($pending)
            || ($pending['trial'] ?? null) !== $trial
            || ($pending['capability_hash'] ?? null) !== hash('sha256', $capability)
            || ($pending['claimed'] ?? null) !== false
            || ($authorization['key_fingerprint'] ?? null) !== hash('sha256', $key)) {
            throw new RuntimeException('The live grouping benchmark parent capability is invalid or already consumed.');
        }

        $authorization['pending']['claimed'] = true;

        if ($authenticated) {
            $authorization = self::signed($authorization, self::key($path, false));
        }

        self::write($path, $authorization);
    }

    /** @return array<string, mixed> */
    public static function read(string $path, bool $requirePrivateMode = true): array
    {
        $permissions = is_file($path) ? fileperms($path) : false;

        if (is_link($path)
            || ! is_file($path)
            || ($requirePrivateMode && ($permissions === false || ($permissions & 0777) !== 0600))) {
            throw new RuntimeException('The private live grouping authorization ledger is unavailable or unsafe.');
        }

        try {
            $authorization = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The private live grouping authorization ledger is invalid JSON.', previous: $exception);
        }

        if (! is_array($authorization) || array_is_list($authorization)) {
            throw new RuntimeException('The private live grouping authorization ledger must be an object.');
        }

        /** @var array<string, mixed> $authorization */
        return $authorization;
    }

    /** @param array<string, mixed> $authorization */
    public static function write(string $path, array $authorization): void
    {
        $directory = dirname($path);

        if ((! is_dir($directory) && ! mkdir($directory, 0700, true))
            || is_link($directory)
            || is_link($path)) {
            throw new RuntimeException('Unable to create the private live grouping authorization ledger.');
        }

        $temporary = $path.'.'.bin2hex(random_bytes(8));

        try {
            $contents = json_encode($authorization, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;

            if (file_put_contents($temporary, $contents, LOCK_EX) === false
                || ! chmod($temporary, 0600)
                || ! rename($temporary, $path)) {
                throw new RuntimeException('Unable to persist the live grouping authorization ledger.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function nativePath(string $repositoryRoot, string $stage): string
    {
        $process = new Process(['git', 'rev-parse', '--git-path', self::relativePath($stage)], $repositoryRoot);
        $process->run();
        $path = trim($process->getOutput());

        if (! $process->isSuccessful() || $path === '') {
            throw new RuntimeException('Unable to resolve the authenticated live grouping authorization path.');
        }

        return str_starts_with($path, '/') ? $path : $repositoryRoot.'/'.$path;
    }

    private static function relativePath(string $stage): string
    {
        $filename = basename(LiveGroupingModels::stage($stage)['authorization_file']);

        return 'laravel-document-extraction/live-grouping/'.$filename;
    }

    /** @param array<string, mixed> $value */
    private static function encoded(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to authenticate the live grouping authorization ledger.', previous: $exception);
        }
    }
}
