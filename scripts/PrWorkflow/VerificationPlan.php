<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Dev\PrWorkflow;

use JsonException;

final readonly class VerificationPlan
{
    public function __construct(private string $repositoryRoot) {}

    /**
     * @return non-empty-list<array{id: string, command: non-empty-list<string>}>
     */
    public function steps(): array
    {
        return [
            ['id' => 'composer-install', 'command' => ['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress']],
            ['id' => 'composer-validate', 'command' => ['composer', 'validate', '--strict']],
            ['id' => 'composer-platform', 'command' => ['composer', 'check-platform-reqs']],
            ['id' => 'composer-audit', 'command' => ['composer', 'audit', '--locked', '--no-interaction']],
            ['id' => 'pint', 'command' => ['{php}', 'vendor/bin/pint', '--test']],
            ['id' => 'larastan', 'command' => ['{php}', 'vendor/bin/phpstan', 'analyse', '--level=10', '--memory-limit=1G', '--no-progress']],
            ['id' => 'pest-full', 'command' => ['{php}', 'vendor/bin/pest', '--no-tia', '--colors=never']],
            ['id' => 'tia-proof', 'command' => ['bash', 'scripts/prove-tia']],
            ['id' => 'pao-proof', 'command' => ['bash', 'scripts/prove-pao']],
            ['id' => 'compatibility-matrix', 'command' => ['bash', 'scripts/test-matrix']],
            ['id' => 'safeguards', 'command' => ['{php}', 'vendor/bin/pest', 'tests/Unit/PrWorkflowTest.php', 'tests/Unit/FoundationProofSafetyTest.php', '--no-tia', '--colors=never']],
            ['id' => 'shell-syntax', 'command' => ['bash', '-n', '.agents/setup', '.agents/resume', 'scripts/prove-tia', 'scripts/prove-pao', 'scripts/test-matrix']],
        ];
    }

    /**
     * @return array{php: string, laravel: string, matrix: array{php: list<string>, laravel: list<string>}, authoritativeTests: string}
     */
    public function runtimePolicy(): array
    {
        $composer = $this->readComposerJson();
        $requirements = $composer['require'] ?? null;

        if (! is_array($requirements)) {
            throw new WorkflowException('composer.json does not contain a require map.');
        }

        $php = $requirements['php'] ?? null;
        $laravel = $requirements['illuminate/support'] ?? null;

        if (! is_string($php) || ! is_string($laravel)) {
            throw new WorkflowException('composer.json does not declare the PHP and Laravel support policy.');
        }

        return [
            'php' => $php,
            'laravel' => $laravel,
            'matrix' => [
                'php' => ['8.4', '8.5'],
                'laravel' => ['13.23.0', '^13.23'],
            ],
            'authoritativeTests' => '--no-tia',
        ];
    }

    public function id(): string
    {
        return hash('sha256', $this->canonicalJson([
            'steps' => $this->steps(),
            'runtimePolicy' => $this->runtimePolicy(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(): array
    {
        try {
            $composer = json_decode(
                (string) file_get_contents($this->repositoryRoot.'/composer.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new WorkflowException('composer.json is not valid JSON.', previous: $exception);
        }

        if (! is_array($composer)) {
            throw new WorkflowException('composer.json is not a JSON object.');
        }

        foreach (array_keys($composer) as $key) {
            if (! is_string($key)) {
                throw new WorkflowException('composer.json contains a non-string root field.');
            }
        }

        /** @var array<string, mixed> $composer */
        return $composer;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalJson(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new WorkflowException('Unable to encode the verification plan.', previous: $exception);
        }
    }
}
