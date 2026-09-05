<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Dev\PrWorkflow;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class Workflow
{
    private const RECEIPT_VERSION = 1;

    private const MATRIX_VERSION = 1;

    private const SHA_PATTERN = '/^[a-f0-9]{40}$/D';

    /** @var array<string, string> */
    private array $environment;

    /** @var callable(): string */
    private $clock;

    /** @var callable(string, bool): void */
    private $output;

    private readonly VerificationPlan $plan;

    private readonly ReceiptStore $receipts;

    /**
     * @param  array<string, string>|null  $environment
     * @param  (callable(): string)|null  $clock
     * @param  (callable(string, bool): void)|null  $output
     */
    public function __construct(
        private readonly string $repositoryRoot,
        private readonly CommandRunner $runner,
        ?array $environment = null,
        ?callable $clock = null,
        private readonly bool $emitOutput = true,
        ?callable $output = null,
    ) {
        $this->environment = $environment ?? $this->ambientEnvironment();
        $this->clock = $clock ?? static fn (): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $this->output = $output ?? static function (string $contents, bool $stderr): void {
            fwrite($stderr ? STDERR : STDOUT, $contents);
        };
        $this->plan = new VerificationPlan($repositoryRoot);
        $this->receipts = new ReceiptStore($repositoryRoot, $runner);
    }

    /**
     * @return array{sha: string, receiptPath: string, receipt: array<string, mixed>}
     */
    public function check(string $base = 'main'): array
    {
        $this->assertBaseName($base);
        $sha = $this->head();
        $this->receipts->invalidate($sha);
        $this->assertClean();
        $baseEvidence = $this->freshBase($base);
        $repository = $this->repositoryEvidence();
        $temporaryHome = $this->createTemporaryHome();
        $matrixPath = $temporaryHome.'/matrix.json';
        $verificationEnvironment = SafeEnvironment::verification($this->environment, $temporaryHome, $matrixPath);

        try {
            foreach ($this->plan->steps() as $step) {
                $command = array_map(
                    static fn (string $argument): string => $argument === '{php}' ? PHP_BINARY : $argument,
                    $step['command'],
                );

                /** @var non-empty-list<string> $command */
                $this->runRequired($command, $verificationEnvironment, 'Verification step '.$step['id'], true);
            }

            $matrix = $this->readMatrixEvidence($matrixPath);
            $dependencies = $this->dependencyEvidence();
            $runtime = $this->runtimeEvidence($verificationEnvironment);

            $this->assertClean();

            if ($this->head() !== $sha) {
                throw new WorkflowException('HEAD changed while the verification plan was running.');
            }

            $receipt = [
                'version' => self::RECEIPT_VERSION,
                'success' => true,
                'sha' => $sha,
                'completedAt' => ($this->clock)(),
                'repository' => $repository,
                'base' => $baseEvidence,
                'plan' => [
                    'id' => $this->plan->id(),
                    'steps' => $this->plan->steps(),
                    'runtimePolicy' => $this->plan->runtimePolicy(),
                ],
                'runtime' => $runtime,
                'dependencies' => $dependencies,
                'matrix' => $matrix,
            ];
            $receipt['evidenceId'] = $this->evidenceId($receipt);
            $path = $this->receipts->write($sha, $receipt);

            return ['sha' => $sha, 'receiptPath' => $path, 'receipt' => $receipt];
        } finally {
            $this->removeDirectory($temporaryHome);
        }
    }

    public function signoff(string $approvedSha): string
    {
        $this->assertSha($approvedSha, 'The approved SHA');
        $sha = $this->head();

        if ($sha !== $approvedSha) {
            throw new WorkflowException('The explicitly approved SHA does not match current HEAD.');
        }

        $this->assertClean();
        $receipt = $this->receipts->read($approvedSha);
        $this->validateReceiptShape($receipt, $approvedSha);

        $base = $receipt['base'];
        $repository = $receipt['repository'];

        if (! is_array($base) || ! is_array($repository)) {
            throw new WorkflowException('The verification receipt has invalid repository evidence.');
        }

        /** @var array<string, mixed> $base */
        /** @var array<string, mixed> $repository */
        $baseName = $this->requiredString($base, 'name', 'receipt base');
        $this->assertBaseName($baseName);

        if ($this->freshBase($baseName) !== $base) {
            throw new WorkflowException('The verified remote base has changed; run pr:check again.');
        }

        if ($this->repositoryEvidence() !== $repository) {
            throw new WorkflowException('The repository remote changed after verification.');
        }

        $temporaryHome = $this->createTemporaryHome();

        try {
            $runtimeEnvironment = SafeEnvironment::verification($this->environment, $temporaryHome, $temporaryHome.'/unused-matrix.json');

            if ($this->runtimeEvidence($runtimeEnvironment) !== $receipt['runtime']) {
                throw new WorkflowException('The verification runtime changed; run pr:check again.');
            }

            if ($this->dependencyEvidence() !== $receipt['dependencies']) {
                throw new WorkflowException('Composer lock evidence changed; run pr:check again.');
            }
        } finally {
            $this->removeDirectory($temporaryHome);
        }

        $token = $this->environment['GH_SIGNOFF_TOKEN'] ?? null;

        if (! is_string($token) || trim($token) === '') {
            throw new WorkflowException('GH_SIGNOFF_TOKEN is required for exact-SHA signoff.');
        }

        $slug = $this->requiredString($repository, 'slug', 'receipt repository');
        $githubEnvironment = SafeEnvironment::github($this->environment, $token, $slug);
        $extension = $this->runRequired(['gh', 'extension', 'list'], $githubEnvironment, 'GitHub signoff extension check');

        if (preg_match('/(^|\s)basecamp\/gh-signoff(?:\s|$)/m', $extension->stdout) !== 1) {
            throw new WorkflowException('The basecamp/gh-signoff extension is not installed.');
        }

        $pr = $this->decodeObject(
            $this->runRequired(
                ['gh', 'pr', 'view', '--json', 'headRefOid,state,baseRefName'],
                $githubEnvironment,
                'Open pull request check',
            )->stdout,
            'pull request response',
        );

        if (($pr['state'] ?? null) !== 'OPEN'
            || ($pr['headRefOid'] ?? null) !== $approvedSha
            || ($pr['baseRefName'] ?? null) !== $baseName) {
            throw new WorkflowException('The open pull request does not match the approved SHA and verified base.');
        }

        $this->assertClean();

        if ($this->head() !== $approvedSha) {
            throw new WorkflowException('HEAD changed immediately before signoff.');
        }

        $this->runRequired(
            ['gh', 'signoff', '--commit', $approvedSha],
            $githubEnvironment,
            'Exact-SHA signoff',
        );

        $readback = $this->decodeObject(
            $this->runRequired(
                ['gh', 'api', 'repos/'.$slug.'/commits/'.$approvedSha.'/status'],
                $githubEnvironment,
                'Exact-SHA signoff readback',
            )->stdout,
            'commit status response',
        );
        $this->assertSuccessfulSignoffStatus($readback, $approvedSha);

        return $approvedSha;
    }

    /**
     * Run Larastan with the same secret-free disposable environment used by pr:check.
     */
    public function analyse(): CommandResult
    {
        $temporaryHome = $this->createTemporaryHome();

        try {
            return $this->runRequired(
                [PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--memory-limit=1G', '--no-progress'],
                SafeEnvironment::verification($this->environment, $temporaryHome, $temporaryHome.'/unused-matrix.json'),
                'Larastan analysis',
                true,
            );
        } finally {
            $this->removeDirectory($temporaryHome);
        }
    }

    /**
     * @return array<string, string>
     */
    private function ambientEnvironment(): array
    {
        return getenv();
    }

    private function createTemporaryHome(): string
    {
        $path = sys_get_temp_dir().'/lde-pr-check-'.bin2hex(random_bytes(12));

        if (! mkdir($path, 0700, true)) {
            throw new WorkflowException('Unable to create an isolated verification home.');
        }

        foreach (['.composer/cache', '.cache', 'tmp'] as $directory) {
            if (! mkdir($path.'/'.$directory, 0700, true)) {
                throw new WorkflowException('Unable to prepare the isolated verification home.');
            }
        }

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.'/'.$item;

            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }

    private function head(): string
    {
        $result = $this->runRequired(['git', 'rev-parse', '--verify', 'HEAD'], null, 'HEAD resolution');
        $sha = trim($result->stdout);
        $this->assertSha($sha, 'Current HEAD');

        return $sha;
    }

    private function assertClean(): void
    {
        $result = $this->runRequired(
            ['git', 'status', '--porcelain=v1', '--untracked-files=all'],
            null,
            'Worktree cleanliness check',
        );

        if (trim($result->stdout) !== '') {
            throw new WorkflowException('Verification and signoff require a clean worktree including untracked files.');
        }
    }

    /**
     * @return array{name: string, localRef: string, remoteRef: string, sha: string}
     */
    private function freshBase(string $base): array
    {
        $localRef = 'origin/'.$base;
        $remoteRef = 'refs/heads/'.$base;
        $local = trim($this->runRequired(
            ['git', 'rev-parse', '--verify', $localRef],
            null,
            'Local base resolution',
        )->stdout);
        $this->assertSha($local, 'Local base SHA');
        $remoteOutput = trim($this->runRequired(
            ['git', 'ls-remote', '--exit-code', 'origin', $remoteRef],
            null,
            'Remote base freshness check',
        )->stdout);
        $lines = $remoteOutput === '' ? [] : preg_split('/\R/', $remoteOutput);

        if (! is_array($lines) || count($lines) !== 1) {
            throw new WorkflowException('The remote base did not resolve to exactly one branch SHA.');
        }

        $columns = preg_split('/\s+/', trim($lines[0]));

        if (! is_array($columns) || count($columns) !== 2 || $columns[1] !== $remoteRef) {
            throw new WorkflowException('The remote base response did not match the requested branch.');
        }

        $remote = $columns[0];
        $this->assertSha($remote, 'Remote base SHA');

        if ($local !== $remote) {
            throw new WorkflowException('origin/'.$base.' is stale; fetch the remote base before verification.');
        }

        $this->runRequired(
            ['git', 'merge-base', '--is-ancestor', $remote, 'HEAD'],
            null,
            'Candidate ancestry check',
        );

        return ['name' => $base, 'localRef' => $localRef, 'remoteRef' => $remoteRef, 'sha' => $remote];
    }

    /**
     * @return array{slug: string, remote: string}
     */
    private function repositoryEvidence(): array
    {
        $remote = trim($this->runRequired(
            ['git', 'remote', 'get-url', 'origin'],
            null,
            'Repository origin resolution',
        )->stdout);
        $patterns = [
            '#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?$#D',
            '#^git@github\.com:([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?$#D',
            '#^ssh://git@github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?$#D',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $remote, $matches) === 1) {
                return ['slug' => $matches[1], 'remote' => $remote];
            }
        }

        throw new WorkflowException('origin must be an explicit GitHub repository URL.');
    }

    /**
     * @return array{lockSha256: string, contentHash: string, packageCount: int, packagesHash: string}
     */
    private function dependencyEvidence(): array
    {
        $path = $this->repositoryRoot.'/composer.lock';
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new WorkflowException('composer.lock is unavailable.');
        }

        $lock = $this->decodeObject($contents, 'composer.lock');
        $contentHash = $lock['content-hash'] ?? null;
        $packages = array_merge(
            is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
            is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [],
        );

        if (! is_string($contentHash)) {
            throw new WorkflowException('composer.lock has no content hash.');
        }

        $identities = [];

        foreach ($packages as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['version'] ?? null)) {
                throw new WorkflowException('composer.lock contains invalid package evidence.');
            }

            $dist = is_array($package['dist'] ?? null) ? $package['dist'] : [];
            $source = is_array($package['source'] ?? null) ? $package['source'] : [];
            $identities[] = [
                'name' => $package['name'],
                'version' => $package['version'],
                'reference' => is_string($dist['reference'] ?? null)
                    ? $dist['reference']
                    : (is_string($source['reference'] ?? null) ? $source['reference'] : null),
            ];
        }

        usort($identities, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        return [
            'lockSha256' => hash('sha256', $contents),
            'contentHash' => $contentHash,
            'packageCount' => count($identities),
            'packagesHash' => hash('sha256', $this->canonicalJson($identities)),
        ];
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, mixed>
     */
    private function runtimeEvidence(array $environment): array
    {
        $phpScript = <<<'PHP'
echo json_encode([
    'php' => PHP_VERSION,
    'platform' => PHP_OS_FAMILY,
    'architecture' => php_uname('m'),
    'imagick' => phpversion('imagick') ?: 'missing',
    'pcov' => phpversion('pcov') ?: 'missing',
    'imageMagick' => class_exists('Imagick') ? Imagick::getVersion()['versionString'] : 'missing',
], JSON_THROW_ON_ERROR);
PHP;
        $php = [];

        foreach (['8.4', '8.5'] as $version) {
            $php[$version] = $this->decodeObject(
                $this->runRequired(['php'.$version, '-r', $phpScript], $environment, 'PHP '.$version.' runtime probe')->stdout,
                'PHP '.$version.' runtime evidence',
            );
        }

        $composerOutput = $this->runRequired(
            ['composer', '--version', '--no-ansi'],
            $environment,
            'Composer runtime probe',
        )->output();

        if (preg_match('/Composer version ([0-9]+\.[0-9]+\.[0-9]+)/', $composerOutput, $matches) !== 1) {
            throw new WorkflowException('Composer returned an unrecognized version.');
        }

        $native = [];

        foreach (['pdfimages', 'pdfinfo', 'pdftoppm', 'pdftotext'] as $command) {
            $output = $this->runRequired([$command, '-v'], $environment, $command.' runtime probe')->output();

            if ($output === '') {
                throw new WorkflowException($command.' returned no version evidence.');
            }

            $native[$command] = $output;
        }

        $runtime = [
            'php' => $php,
            'composer' => $matches[1],
            'native' => $native,
        ];
        $runtime['fingerprint'] = hash('sha256', $this->canonicalJson($runtime));

        return $runtime;
    }

    /**
     * @return array{version: int, cells: list<array<string, mixed>>}
     */
    private function readMatrixEvidence(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            throw new WorkflowException('The compatibility matrix did not produce evidence.');
        }

        $matrix = $this->decodeObject($contents, 'compatibility matrix evidence');

        if (array_keys($matrix) !== ['version', 'cells']
            || $matrix['version'] !== self::MATRIX_VERSION
            || ! is_array($matrix['cells'])) {
            throw new WorkflowException('The compatibility matrix evidence has an invalid schema.');
        }

        $expected = ['8.4/minimum', '8.4/current', '8.5/minimum', '8.5/current'];
        $actual = [];

        foreach ($matrix['cells'] as $cell) {
            if (! is_array($cell)) {
                throw new WorkflowException('The compatibility matrix contains an invalid cell.');
            }

            /** @var array<string, mixed> $cell */
            $this->assertMatrixCell($cell);
            $actual[] = $this->requiredString($cell, 'phpTarget', 'matrix cell').'/'.$this->requiredString($cell, 'laravelTarget', 'matrix cell');
        }

        if ($actual !== $expected) {
            throw new WorkflowException('The compatibility matrix did not execute all four cells in order.');
        }

        /** @var list<array<string, mixed>> $cells */
        $cells = $matrix['cells'];

        return ['version' => self::MATRIX_VERSION, 'cells' => $cells];
    }

    /**
     * @param  array<string, mixed>  $cell
     */
    private function assertMatrixCell(array $cell): void
    {
        $keys = [
            'phpTarget', 'laravelTarget', 'php', 'laravel', 'laravelAi', 'pricing',
            'interventionImage', 'opisJsonSchema', 'pdfToText', 'testbench', 'pest',
            'imagick', 'pcov', 'imageApi', 'checks',
        ];

        if (array_keys($cell) !== $keys) {
            throw new WorkflowException('The compatibility matrix cell has unexpected fields.');
        }

        foreach (array_diff($keys, ['checks', 'imageApi']) as $key) {
            if (! is_string($cell[$key]) || $cell[$key] === '') {
                throw new WorkflowException('The compatibility matrix cell has invalid '.$key.' evidence.');
            }
        }

        $phpTarget = $this->requiredString($cell, 'phpTarget', 'matrix cell');
        $php = $this->requiredString($cell, 'php', 'matrix cell');
        $laravelTarget = $this->requiredString($cell, 'laravelTarget', 'matrix cell');
        $laravel = $this->requiredString($cell, 'laravel', 'matrix cell');

        if (! in_array($phpTarget, ['8.4', '8.5'], true)
            || ! str_starts_with($php, $phpTarget.'.')
            || ! in_array($laravelTarget, ['minimum', 'current'], true)
            || ! is_bool($cell['imageApi'])
            || $cell['imageApi'] !== true
            || $cell['checks'] !== ['platform' => true, 'pestNoTia' => true, 'larastanLevel' => 10]) {
            throw new WorkflowException('The compatibility matrix cell does not satisfy the runtime policy.');
        }

        if (($laravelTarget === 'minimum' && $laravel !== '13.23.0')
            || ($laravelTarget === 'current'
                && (! version_compare($laravel, '13.23.0', '>=') || ! version_compare($laravel, '14.0.0', '<')))) {
            throw new WorkflowException('The compatibility matrix cell has invalid Laravel version evidence.');
        }
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private function validateReceiptShape(array $receipt, string $sha): void
    {
        $expectedKeys = [
            'version', 'success', 'sha', 'completedAt', 'repository', 'base', 'plan',
            'runtime', 'dependencies', 'matrix', 'evidenceId',
        ];

        if (array_keys($receipt) !== $expectedKeys
            || $receipt['version'] !== self::RECEIPT_VERSION
            || $receipt['success'] !== true
            || $receipt['sha'] !== $sha
            || ! is_string($receipt['completedAt'])
            || DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $receipt['completedAt'], new DateTimeZone('UTC')) === false) {
            throw new WorkflowException('The verification receipt has an invalid schema or candidate identity.');
        }

        $expectedPlan = [
            'id' => $this->plan->id(),
            'steps' => $this->plan->steps(),
            'runtimePolicy' => $this->plan->runtimePolicy(),
        ];

        if ($receipt['plan'] !== $expectedPlan) {
            throw new WorkflowException('The verification plan changed; run pr:check again.');
        }

        if (! is_array($receipt['matrix'])) {
            throw new WorkflowException('The verification receipt has no matrix evidence.');
        }

        /** @var array<string, mixed> $matrix */
        $matrix = $receipt['matrix'];
        $this->validateEmbeddedMatrix($matrix);

        if (! is_string($receipt['evidenceId'])
            || preg_match('/^[a-f0-9]{64}$/D', $receipt['evidenceId']) !== 1
            || ! hash_equals($receipt['evidenceId'], $this->evidenceId($receipt))) {
            throw new WorkflowException('The verification receipt evidence has been altered.');
        }
    }

    /**
     * @param  array<string, mixed>  $matrix
     */
    private function validateEmbeddedMatrix(array $matrix): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lde-matrix-validate-');

        if ($path === false) {
            throw new WorkflowException('Unable to validate matrix evidence.');
        }

        try {
            file_put_contents($path, $this->canonicalJson($matrix));
            $this->readMatrixEvidence($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private function evidenceId(array $receipt): string
    {
        return hash('sha256', $this->canonicalJson([
            'sha' => $receipt['sha'] ?? null,
            'repository' => $receipt['repository'] ?? null,
            'base' => $receipt['base'] ?? null,
            'plan' => $receipt['plan'] ?? null,
            'runtime' => $receipt['runtime'] ?? null,
            'dependencies' => $receipt['dependencies'] ?? null,
            'matrix' => $receipt['matrix'] ?? null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function assertSuccessfulSignoffStatus(array $status, string $sha): void
    {
        if (($status['sha'] ?? null) !== $sha || ! is_array($status['statuses'] ?? null)) {
            throw new WorkflowException('GitHub returned status evidence for the wrong commit.');
        }

        foreach ($status['statuses'] as $entry) {
            if (is_array($entry)
                && is_string($entry['context'] ?? null)
                && strtolower($entry['context']) === 'signoff') {
                if (($entry['state'] ?? null) !== 'success') {
                    throw new WorkflowException('The exact-SHA signoff status is not successful.');
                }

                return;
            }
        }

        throw new WorkflowException('GitHub did not return the exact-SHA signoff context.');
    }

    private function assertSha(string $sha, string $label): void
    {
        if (preg_match(self::SHA_PATTERN, $sha) !== 1) {
            throw new WorkflowException($label.' must be a full lowercase 40-character SHA.');
        }
    }

    private function assertBaseName(string $base): void
    {
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#D', $base) !== 1
            || str_contains($base, '..')
            || str_contains($base, '@{')
            || str_ends_with($base, '/')
            || str_ends_with($base, '.lock')) {
            throw new WorkflowException('The base branch name is unsafe.');
        }
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function requiredString(array $object, string $key, string $label): string
    {
        $value = $object[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new WorkflowException('The '.$label.' has invalid '.$key.' evidence.');
        }

        return $value;
    }

    /**
     * @param  non-empty-list<string>  $command
     * @param  array<string, string>|null  $environment
     */
    private function runRequired(
        array $command,
        ?array $environment,
        string $label,
        bool $publishOutput = false,
    ): CommandResult {
        $result = $this->runner->run($command, $environment);

        if ($this->emitOutput && $publishOutput) {
            if ($result->stdout !== '') {
                ($this->output)($result->stdout, false);
            }

            if ($result->stderr !== '') {
                ($this->output)($result->stderr, true);
            }
        }

        if ($result->exitCode !== 0) {
            throw new WorkflowException($label.' failed with exit code '.$result->exitCode.'.');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json, string $label): array
    {
        try {
            $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new WorkflowException('The '.$label.' is not valid JSON.', previous: $exception);
        }

        if (! is_array($value)) {
            throw new WorkflowException('The '.$label.' is not a JSON object.');
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                throw new WorkflowException('The '.$label.' contains a non-string field.');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private function canonicalJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new WorkflowException('Unable to encode verification evidence.', previous: $exception);
        }
    }
}
