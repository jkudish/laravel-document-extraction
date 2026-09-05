<?php

declare(strict_types=1);

use Jkudish\DocumentExtraction\Dev\PrWorkflow\CliArguments;
use Jkudish\DocumentExtraction\Dev\PrWorkflow\NativeCommandRunner;
use Jkudish\DocumentExtraction\Dev\PrWorkflow\SafeEnvironment;
use Jkudish\DocumentExtraction\Dev\PrWorkflow\WorkflowException;
use Jkudish\DocumentExtraction\Tests\Support\WorkflowHarness;

function workflowHarness(bool $withToken = true): WorkflowHarness
{
    $path = sys_get_temp_dir().'/lde-workflow-test-'.bin2hex(random_bytes(8));

    if (! mkdir($path, 0700, true)) {
        throw new RuntimeException('Unable to create workflow test directory.');
    }

    return new WorkflowHarness($path, $withToken);
}

function directoryContainsCanary(string $directory, string $canary): bool
{
    if (! is_dir($directory)) {
        return false;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        if ($item->isFile() && ! $item->isLink()) {
            $contents = file_get_contents($item->getPathname());

            if (is_string($contents) && str_contains($contents, $canary)) {
                return true;
            }
        }
    }

    return false;
}

it('writes a private exact-SHA receipt after the complete isolated ordered plan', function (): void {
    $harness = workflowHarness();

    try {
        $result = $harness->check();
        $receipt = $result['receipt'];
        $matrix = $receipt['matrix'];
        $dependencies = $receipt['dependencies'];
        $runtime = $receipt['runtime'];
        assert(is_array($matrix));
        assert(is_array($dependencies));
        assert(is_array($runtime));
        $cells = $matrix['cells'] ?? null;
        assert(is_array($cells));

        expect($result['sha'])->toBe($harness->runner->sha)
            ->and(is_file($result['receiptPath']))->toBeTrue()
            ->and(fileperms($result['receiptPath']) & 0777)->toBe(0600)
            ->and($receipt['base'])->toMatchArray([
                'name' => 'work/3435-foundation',
                'sha' => $harness->runner->baseSha,
            ])
            ->and($cells)->toHaveCount(4)
            ->and($dependencies['packageCount'])->toBeGreaterThan(0)
            ->and($runtime['fingerprint'])->toMatch('/^[a-f0-9]{64}$/');

        $steps = array_map(
            static fn (array $call): string => implode(' ', $call['command']),
            $harness->runner->verificationStepCalls(),
        );

        expect($steps)->toContain(
            'composer install --no-interaction --prefer-dist --no-progress',
            PHP_BINARY.' vendor/bin/phpstan analyse --level=10 --memory-limit=1G --no-progress',
            PHP_BINARY.' vendor/bin/pest --no-tia --colors=never',
            'bash scripts/test-matrix',
            PHP_BINARY.' vendor/bin/pest tests/Unit/PrWorkflowTest.php tests/Unit/FoundationProofSafetyTest.php --no-tia --colors=never',
        );

        foreach ($harness->runner->verificationStepCalls() as $call) {
            expect($call['environment'])->not->toHaveKeys(['GH_TOKEN', 'GH_SIGNOFF_TOKEN', 'OPENAI_API_KEY'])
                ->and($call['environment'])->toHaveKeys(['HOME', 'COMPOSER_HOME', 'LDE_MATRIX_EVIDENCE']);
        }
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('keeps synthetic credentials out of receipts output and generated analysis cache', function (): void {
    $harness = workflowHarness();
    $canaries = array_values(WorkflowHarness::canaries());
    $analysisHome = $harness->temporaryDirectory.'/analysis-home';
    mkdir($analysisHome.'/.composer/cache', 0700, true);
    mkdir($analysisHome.'/.cache', 0700, true);
    mkdir($analysisHome.'/tmp', 0700, true);

    try {
        $result = $harness->check();
        $receipt = (string) file_get_contents($result['receiptPath']);
        $environment = SafeEnvironment::verification(
            $harness->environment,
            $analysisHome,
            $analysisHome.'/matrix.json',
        );
        $analysis = (new NativeCommandRunner(dirname(__DIR__, 2)))->run(
            [PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--level=10', '--memory-limit=1G', '--no-progress'],
            $environment,
        );
        $output = $analysis->stdout.$analysis->stderr;

        expect($analysis->exitCode)->toBe(0)
            ->and(is_dir(dirname(__DIR__, 2).'/.phpstan'))->toBeFalse();

        foreach ($canaries as $canary) {
            expect($receipt)->not->toContain($canary)
                ->and($output)->not->toContain($canary)
                ->and(directoryContainsCanary($analysisHome, $canary))->toBeFalse();
        }
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('removes the dedicated token from default children without stripping ambient Git authentication', function (): void {
    $runner = new NativeCommandRunner(dirname(__DIR__, 2));
    $dedicatedCanary = 'synthetic-native-dedicated-token';
    $gitAskpass = '/synthetic/git-askpass';
    $previousDedicatedToken = getenv('GH_SIGNOFF_TOKEN');
    $previousGitAskpass = getenv('GIT_ASKPASS');

    try {
        putenv('GH_SIGNOFF_TOKEN='.$dedicatedCanary);
        putenv('GIT_ASKPASS='.$gitAskpass);

        $defaultChild = $runner->run([
            PHP_BINARY,
            '-r',
            <<<'PHP'
exit(getenv('GH_SIGNOFF_TOKEN') === false
    && getenv('GIT_ASKPASS') === '/synthetic/git-askpass' ? 0 : 42);
PHP,
        ]);
        $githubEnvironment = SafeEnvironment::github(
            [
                'PATH' => (string) getenv('PATH'),
                'HOME' => (string) getenv('HOME'),
            ],
            $dedicatedCanary,
            'jkudish/laravel-document-extraction',
        );
        $githubChild = $runner->run([
            PHP_BINARY,
            '-r',
            <<<'PHP'
exit(getenv('GH_SIGNOFF_TOKEN') === false
    && getenv('GH_TOKEN') === 'synthetic-native-dedicated-token'
    && getenv('GH_HOST') === 'github.com'
    && getenv('GH_REPO') === 'jkudish/laravel-document-extraction' ? 0 : 43);
PHP,
        ], $githubEnvironment);

        expect($defaultChild->exitCode)->toBe(0)
            ->and($defaultChild->output())->toBe('')
            ->and($githubChild->exitCode)->toBe(0)
            ->and($githubChild->output())->toBe('');
    } finally {
        $previousDedicatedToken === false
            ? putenv('GH_SIGNOFF_TOKEN')
            : putenv('GH_SIGNOFF_TOKEN='.$previousDedicatedToken);
        $previousGitAskpass === false
            ? putenv('GIT_ASKPASS')
            : putenv('GIT_ASKPASS='.$previousGitAskpass);
    }
})->group('pr-workflow');

it('does not issue a receipt for a dirty tree', function (): void {
    $harness = workflowHarness();
    $harness->runner->status = ' M config/extraction.php'.PHP_EOL;

    try {
        expect(fn () => $harness->check())->toThrow(WorkflowException::class, 'clean worktree')
            ->and(glob($harness->temporaryDirectory.'/receipts/*.json') ?: [])->toBe([]);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('does not issue a receipt for a stale base', function (): void {
    $harness = workflowHarness();
    $harness->runner->remoteBaseSha = '3333333333333333333333333333333333333333';

    try {
        expect(fn () => $harness->check())->toThrow(WorkflowException::class, 'is stale')
            ->and(glob($harness->temporaryDirectory.'/receipts/*.json') ?: [])->toBe([]);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('does not issue a receipt when the candidate does not descend from the verified base', function (): void {
    $harness = workflowHarness();
    $harness->runner->candidateDescendsFromBase = false;

    try {
        expect(fn () => $harness->check())->toThrow(WorkflowException::class, 'ancestry check failed')
            ->and(glob($harness->temporaryDirectory.'/receipts/*.json') ?: [])->toBe([]);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('does not issue a receipt after a failed plan step', function (): void {
    $harness = workflowHarness();
    $harness->runner->failingCommandContains = 'vendor/bin/phpstan';

    try {
        expect(fn () => $harness->check())->toThrow(WorkflowException::class, 'larastan failed')
            ->and(glob($harness->temporaryDirectory.'/receipts/*.json') ?: [])->toBe([]);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('invalidates an existing same-SHA receipt before a failing recheck', function (): void {
    $harness = workflowHarness();

    try {
        $result = $harness->check();
        expect(is_file($result['receiptPath']))->toBeTrue();
        $harness->runner->failingCommandContains = 'composer audit';

        expect(fn () => $harness->check())->toThrow(WorkflowException::class, 'composer-audit failed')
            ->and(is_file($result['receiptPath']))->toBeFalse()
            ->and(fn () => $harness->workflow->signoff($harness->runner->sha))
            ->toThrow(WorkflowException::class, 'No private verification receipt')
            ->and($harness->runner->signoffCalls())->toBe(0);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('rejects unknown and duplicate CLI options before workflow execution', function (array $arguments): void {
    /** @var list<string> $arguments */
    $harness = workflowHarness();

    try {
        expect(fn () => CliArguments::parse($arguments))->toThrow(WorkflowException::class)
            ->and($harness->runner->signoffCalls())->toBe(0);
    } finally {
        $harness->remove();
    }
})->with([
    'force option' => [['signoff', '--approved-sha', str_repeat('1', 40), '--force', 'yes']],
    'short force option' => [['signoff', '--approved-sha', str_repeat('1', 40), '-f']],
    'assigned force option' => [['signoff', '--approved-sha', str_repeat('1', 40), '--force=true']],
    'duplicate approval' => [['signoff', '--approved-sha', str_repeat('1', 40), '--approved-sha', str_repeat('1', 40)]],
    'base on signoff' => [['signoff', '--approved-sha', str_repeat('1', 40), '--base', 'main']],
    'option on analyse' => [['analyse', '--force', 'yes']],
])->group('pr-workflow');

it('does not issue a receipt when matrix evidence is missing', function (): void {
    $harness = workflowHarness();
    $harness->runner->writeMatrix = false;

    try {
        expect(fn () => $harness->check())->toThrow(WorkflowException::class, 'did not produce evidence')
            ->and(glob($harness->temporaryDirectory.'/receipts/*.json') ?: [])->toBe([]);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('does not issue a receipt when HEAD drifts during checks', function (): void {
    $harness = workflowHarness();
    $harness->runner->headResponses = [
        $harness->runner->sha,
        '3333333333333333333333333333333333333333',
    ];

    try {
        expect(fn () => $harness->check())->toThrow(WorkflowException::class, 'HEAD changed')
            ->and(glob($harness->temporaryDirectory.'/receipts/*.json') ?: [])->toBe([]);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('posts and reads back only the approved exact SHA with the dedicated token', function (): void {
    $harness = workflowHarness();

    try {
        $harness->check();
        expect($harness->workflow->signoff($harness->runner->sha))->toBe($harness->runner->sha)
            ->and($harness->runner->signoffCalls())->toBe(1);

        foreach ($harness->runner->githubCalls() as $call) {
            expect($call['environment'])->toHaveKey('GH_TOKEN', WorkflowHarness::canaries()['dedicated'])
                ->and($call['environment'])->toHaveKey('GH_HOST', 'github.com')
                ->and($call['environment'])->toHaveKey('GH_REPO', 'jkudish/laravel-document-extraction')
                ->and($call['environment'])->not->toHaveKeys(['GH_SIGNOFF_TOKEN', 'OPENAI_API_KEY']);
        }

        $commands = array_map(
            static fn (array $call): string => implode(' ', $call['command']),
            $harness->runner->githubCalls(),
        );

        expect($commands)->toContain(
            'gh signoff --commit '.$harness->runner->sha,
            'gh api repos/jkudish/laravel-document-extraction/commits/'.$harness->runner->sha.'/status',
        )->not->toContain('gh signoff --force --commit '.$harness->runner->sha);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('rejects malformed or mismatched approval before status mutation', function (string $approved): void {
    $harness = workflowHarness();

    try {
        $harness->check();
        expect(fn () => $harness->workflow->signoff($approved))->toThrow(WorkflowException::class)
            ->and($harness->runner->signoffCalls())->toBe(0);
    } finally {
        $harness->remove();
    }
})->with([
    'abbreviated' => '1111111',
    'uppercase' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
    'different full SHA' => '3333333333333333333333333333333333333333',
])->group('pr-workflow');

it('rejects a missing receipt before status mutation', function (): void {
    $harness = workflowHarness();

    try {
        expect(fn () => $harness->workflow->signoff($harness->runner->sha))
            ->toThrow(WorkflowException::class, 'No private verification receipt')
            ->and($harness->runner->signoffCalls())->toBe(0);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('rejects a dirty tree or non-private receipt before status mutation', function (string $condition): void {
    $harness = workflowHarness();

    try {
        $result = $harness->check();

        if ($condition === 'dirty') {
            $harness->runner->status = '?? caller-file'.PHP_EOL;
        } else {
            chmod($result['receiptPath'], 0644);
        }

        expect(fn () => $harness->workflow->signoff($harness->runner->sha))
            ->toThrow(WorkflowException::class)
            ->and($harness->runner->signoffCalls())->toBe(0);
    } finally {
        $harness->remove();
    }
})->with(['dirty', 'permissions'])->group('pr-workflow');

it('rejects altered receipt evidence before status mutation', function (): void {
    $harness = workflowHarness();

    try {
        $result = $harness->check();
        $receipt = $result['receipt'];
        $matrix = $receipt['matrix'];
        assert(is_array($matrix));
        $cells = $matrix['cells'] ?? null;
        assert(is_array($cells));
        $cell = $cells[0] ?? null;
        assert(is_array($cell));
        $cell['laravel'] = '13.24.0';
        $cells[0] = $cell;
        $matrix['cells'] = $cells;
        $receipt['matrix'] = $matrix;
        file_put_contents($result['receiptPath'], json_encode($receipt, JSON_THROW_ON_ERROR));
        chmod($result['receiptPath'], 0600);

        expect(fn () => $harness->workflow->signoff($harness->runner->sha))
            ->toThrow(WorkflowException::class)
            ->and($harness->runner->signoffCalls())->toBe(0);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('rejects a stale base or runtime before status mutation', function (string $drift): void {
    $harness = workflowHarness();

    try {
        $harness->check();

        if ($drift === 'base') {
            $harness->runner->remoteBaseSha = '3333333333333333333333333333333333333333';
        } else {
            $harness->runner->runtimeSalt = '-drift';
        }

        expect(fn () => $harness->workflow->signoff($harness->runner->sha))
            ->toThrow(WorkflowException::class)
            ->and($harness->runner->signoffCalls())->toBe(0);
    } finally {
        $harness->remove();
    }
})->with(['base', 'runtime'])->group('pr-workflow');

it('rejects missing token or extension before status mutation', function (string $condition): void {
    $harness = workflowHarness($condition !== 'token');

    try {
        $harness->check();
        $harness->runner->extensionInstalled = $condition !== 'extension';

        expect(fn () => $harness->workflow->signoff($harness->runner->sha))
            ->toThrow(WorkflowException::class)
            ->and($harness->runner->signoffCalls())->toBe(0);
    } finally {
        $harness->remove();
    }
})->with(['token', 'extension'])->group('pr-workflow');

it('rejects a nonmatching open pull request before status mutation', function (string $condition): void {
    $harness = workflowHarness();

    try {
        $harness->check();

        match ($condition) {
            'closed' => $harness->runner->prState = 'CLOSED',
            'head' => $harness->runner->prHead = '3333333333333333333333333333333333333333',
            'base' => $harness->runner->prBase = 'main',
            default => throw new RuntimeException('Unknown pull request condition.'),
        };

        expect(fn () => $harness->workflow->signoff($harness->runner->sha))
            ->toThrow(WorkflowException::class, 'does not match')
            ->and($harness->runner->signoffCalls())->toBe(0);
    } finally {
        $harness->remove();
    }
})->with(['closed', 'head', 'base'])->group('pr-workflow');

it('fails closed when a pre-mutation GitHub lookup fails', function (): void {
    $harness = workflowHarness();

    try {
        $harness->check();
        $harness->runner->failingCommandContains = 'gh pr view';
        $harness->runner->failureOutput = implode('-', ['synthetic', 'token', 'bound', 'failure']);

        expect(fn () => $harness->workflow->signoff($harness->runner->sha))
            ->toThrow(WorkflowException::class, 'pull request check failed')
            ->and($harness->runner->signoffCalls())->toBe(0)
            ->and($harness->output->contents)->not->toContain($harness->runner->failureOutput);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('rechecks HEAD immediately before suppressing status mutation', function (): void {
    $harness = workflowHarness();

    try {
        $harness->check();
        $harness->runner->headResponses = [
            $harness->runner->sha,
            '3333333333333333333333333333333333333333',
        ];

        expect(fn () => $harness->workflow->signoff($harness->runner->sha))
            ->toThrow(WorkflowException::class, 'HEAD changed immediately')
            ->and($harness->runner->signoffCalls())->toBe(0);
    } finally {
        $harness->remove();
    }
})->group('pr-workflow');

it('fails when exact-SHA status readback does not confirm success', function (string $condition): void {
    $harness = workflowHarness();

    try {
        $harness->check();

        match ($condition) {
            'sha' => $harness->runner->readbackSha = '3333333333333333333333333333333333333333',
            'context' => $harness->runner->readbackContext = 'other',
            'state' => $harness->runner->readbackState = 'failure',
            default => throw new RuntimeException('Unknown readback condition.'),
        };

        expect(fn () => $harness->workflow->signoff($harness->runner->sha))
            ->toThrow(WorkflowException::class)
            ->and($harness->runner->signoffCalls())->toBe(1);
    } finally {
        $harness->remove();
    }
})->with(['sha', 'context', 'state'])->group('pr-workflow');
