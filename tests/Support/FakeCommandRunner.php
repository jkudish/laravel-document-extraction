<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use Jkudish\DocumentExtraction\Dev\PrWorkflow\CommandResult;
use Jkudish\DocumentExtraction\Dev\PrWorkflow\CommandRunner;

final class FakeCommandRunner implements CommandRunner
{
    public string $sha = '1111111111111111111111111111111111111111';

    public string $baseSha = '2222222222222222222222222222222222222222';

    public string $remoteBaseSha = '2222222222222222222222222222222222222222';

    public string $base = 'work/3435-foundation';

    public string $status = '';

    public string $origin = 'https://github.com/jkudish/laravel-document-extraction.git';

    public bool $writeMatrix = true;

    public bool $extensionInstalled = true;

    public string $prState = 'OPEN';

    public string $prHead = '1111111111111111111111111111111111111111';

    public string $prBase = 'work/3435-foundation';

    public string $readbackState = 'success';

    public string $readbackContext = 'signoff';

    public string $readbackSha = '1111111111111111111111111111111111111111';

    public string $runtimeSalt = '';

    public ?string $failingCommandContains = null;

    /** @var list<string> */
    public array $headResponses = [];

    /** @var list<array{command: non-empty-list<string>, environment: array<string, string>|null}> */
    public array $calls = [];

    public function __construct(public readonly string $receiptDirectory) {}

    public function run(array $command, ?array $environment = null): CommandResult
    {
        $this->calls[] = ['command' => $command, 'environment' => $environment];
        $joined = implode(' ', $command);

        if ($this->failingCommandContains !== null && str_contains($joined, $this->failingCommandContains)) {
            return new CommandResult(19, '', 'simulated failure');
        }

        if ($command === ['git', 'rev-parse', '--verify', 'HEAD']) {
            return new CommandResult(0, (array_shift($this->headResponses) ?? $this->sha).PHP_EOL);
        }

        if ($command === ['git', 'status', '--porcelain=v1', '--untracked-files=all']) {
            return new CommandResult(0, $this->status);
        }

        if ($command === ['git', 'rev-parse', '--verify', 'origin/'.$this->base]) {
            return new CommandResult(0, $this->baseSha.PHP_EOL);
        }

        if ($command === ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/'.$this->base]) {
            return new CommandResult(0, $this->remoteBaseSha."\trefs/heads/{$this->base}\n");
        }

        if ($command === ['git', 'remote', 'get-url', 'origin']) {
            return new CommandResult(0, $this->origin.PHP_EOL);
        }

        if (count($command) === 4
            && $command[0] === 'git'
            && $command[1] === 'rev-parse'
            && $command[2] === '--git-path') {
            return new CommandResult(0, $this->receiptDirectory.'/'.basename($command[3]).PHP_EOL);
        }

        if (in_array($command[0], ['php8.4', 'php8.5'], true) && ($command[1] ?? null) === '-r') {
            return new CommandResult(0, json_encode([
                'php' => $command[0] === 'php8.4' ? '8.4.25'.$this->runtimeSalt : '8.5.10'.$this->runtimeSalt,
                'platform' => 'Linux',
                'architecture' => 'x86_64',
                'imagick' => '3.8.1',
                'pcov' => '1.0.12',
                'imageMagick' => 'ImageMagick 6.9.11-60',
            ], JSON_THROW_ON_ERROR));
        }

        if ($command === ['composer', '--version', '--no-ansi']) {
            return new CommandResult(0, 'Composer version 2.10.3 2026-08-27 13:34:23'.PHP_EOL);
        }

        if (in_array($command[0], ['pdfimages', 'pdfinfo', 'pdftoppm', 'pdftotext'], true)
            && ($command[1] ?? null) === '-v') {
            return new CommandResult(0, '', $command[0].' version 22.12.0'.PHP_EOL);
        }

        if ($command === ['bash', 'scripts/test-matrix']) {
            if ($this->writeMatrix && is_array($environment) && isset($environment['LDE_MATRIX_EVIDENCE'])) {
                file_put_contents(
                    $environment['LDE_MATRIX_EVIDENCE'],
                    json_encode($this->matrixEvidence(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                );
                chmod($environment['LDE_MATRIX_EVIDENCE'], 0600);
            }

            return new CommandResult(0);
        }

        if ($command === ['gh', 'extension', 'list']) {
            return new CommandResult(0, $this->extensionInstalled ? "basecamp/gh-signoff\tmain\n" : "owner/other\tmain\n");
        }

        if ($command === ['gh', 'pr', 'view', '--json', 'headRefOid,state,baseRefName']) {
            return new CommandResult(0, json_encode([
                'headRefOid' => $this->prHead,
                'state' => $this->prState,
                'baseRefName' => $this->prBase,
            ], JSON_THROW_ON_ERROR));
        }

        if ($command === ['gh', 'signoff', '--commit', $this->sha]) {
            return new CommandResult(0, 'signed');
        }

        if ($command === ['gh', 'api', 'repos/jkudish/laravel-document-extraction/commits/'.$this->sha.'/status']) {
            return new CommandResult(0, json_encode([
                'sha' => $this->readbackSha,
                'statuses' => [[
                    'context' => $this->readbackContext,
                    'state' => $this->readbackState,
                ]],
            ], JSON_THROW_ON_ERROR));
        }

        return new CommandResult(0);
    }

    public function signoffCalls(): int
    {
        return count(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['command'][0] === 'gh'
                && ($call['command'][1] ?? null) === 'signoff',
        ));
    }

    /**
     * @return list<array{command: non-empty-list<string>, environment: array<string, string>|null}>
     */
    public function githubCalls(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['command'][0] === 'gh',
        ));
    }

    /**
     * @return list<array{command: non-empty-list<string>, environment: array<string, string>|null}>
     */
    public function verificationStepCalls(): array
    {
        $calls = [];

        foreach ($this->calls as $call) {
            if ($call['environment'] !== null && isset($call['environment']['LDE_MATRIX_EVIDENCE'])) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * @return array{version: int, cells: list<array<string, mixed>>}
     */
    private function matrixEvidence(): array
    {
        $cells = [];

        foreach (['8.4', '8.5'] as $php) {
            foreach (['minimum', 'current'] as $laravelTarget) {
                $cells[] = [
                    'phpTarget' => $php,
                    'laravelTarget' => $laravelTarget,
                    'php' => $php === '8.4' ? '8.4.25' : '8.5.10',
                    'laravel' => $laravelTarget === 'minimum' ? '13.23.0' : '13.30.1',
                    'laravelAi' => '0.11.2',
                    'pricing' => '0.1.0',
                    'interventionImage' => '4.3.2',
                    'opisJsonSchema' => '2.6.0',
                    'pdfToText' => '1.55.0',
                    'testbench' => '11.2.0',
                    'pest' => '5.1.3',
                    'imagick' => '3.8.1',
                    'pcov' => '1.0.12',
                    'imageApi' => true,
                    'checks' => ['platform' => true, 'pestNoTia' => true, 'larastanLevel' => 10],
                ];
            }
        }

        return ['version' => 1, 'cells' => $cells];
    }
}
