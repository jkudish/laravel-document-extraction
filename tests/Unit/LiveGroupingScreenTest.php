<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Jkudish\DocumentExtraction\Dev\LiveGroupingScreen;
use Jkudish\DocumentExtraction\Dev\PrWorkflow\CommandResult;
use Jkudish\DocumentExtraction\Dev\PrWorkflow\CommandRunner;
use Jkudish\DocumentExtraction\Dev\PrWorkflow\NativeCommandRunner;
use Jkudish\DocumentExtraction\Tests\Support\LiveGroupingAttemptScorer;
use Jkudish\DocumentExtraction\Tests\Support\LiveGroupingModels;

final class InertLiveGroupingRunner implements CommandRunner
{
    public int $calls = 0;

    public function run(array $command, ?array $environment = null): CommandResult
    {
        $this->calls++;

        return new CommandResult(99, '', 'The runner should not have been called.');
    }
}

beforeEach(function (): void {
    if (is_file(liveGroupingAuthorizationPath())) {
        unlink(liveGroupingAuthorizationPath());
    }
});

afterEach(function (): void {
    if (is_file(liveGroupingAuthorizationPath())) {
        unlink(liveGroupingAuthorizationPath());
    }
});

function liveGroupingAuthorizationPath(): string
{
    return dirname(__DIR__, 2).'/storage/app/ai-evals/live-grouping-screen-v1.json';
}

/** @param array{id: string, canonical: string, endpoint: string, zdr: bool, reasoning: bool, output_parameter: string, max_price: array{prompt: float, completion: float, image?: float}} $model
 * @param  array<string, mixed>  $keyOverrides
 * @param  array<string, mixed>  $endpointOverrides
 * @return Closure(string, ?string): array<string, mixed>
 */
function liveGroupingApi(
    array $model,
    array $keyOverrides = [],
    array $endpointOverrides = [],
    bool $omitZdr = false,
): Closure {
    return static function (string $url, ?string $key) use ($model, $keyOverrides, $endpointOverrides, $omitZdr): array {
        if ($url === 'https://openrouter.ai/api/v1/key') {
            expect($key)->toBe('synthetic-openrouter-canary');

            return ['data' => [
                'limit' => 50,
                'limit_remaining' => 50,
                'limit_reset' => 'monthly',
                'is_free_tier' => false,
                'is_management_key' => false,
                'is_provisioning_key' => false,
                ...$keyOverrides,
            ]];
        }

        expect($key)->toBeNull();

        if ($url === 'https://openrouter.ai/api/v1/models') {
            return ['data' => [[
                'id' => $model['id'],
                'canonical_slug' => $model['canonical'],
                'architecture' => [
                    'input_modalities' => ['text', 'image'],
                    'output_modalities' => ['text'],
                ],
                'supported_parameters' => ['response_format', 'structured_outputs', $model['output_parameter']],
            ]]];
        }

        if ($url === 'https://openrouter.ai/api/v1/endpoints/zdr') {
            return ['data' => $model['zdr'] && ! $omitZdr ? [[
                'model_id' => $model['id'],
                'tag' => $model['endpoint'],
            ]] : []];
        }

        if ($url === 'https://openrouter.ai/api/v1/models/'.$model['id'].'/endpoints') {
            $pricing = [
                'prompt' => (string) BigDecimal::of((string) $model['max_price']['prompt'])->multipliedBy('0.000001'),
                'completion' => (string) BigDecimal::of((string) $model['max_price']['completion'])->multipliedBy('0.000001'),
            ];

            if (isset($model['max_price']['image'])) {
                $pricing['image'] = (string) BigDecimal::of((string) $model['max_price']['image']);
            }

            return ['data' => ['endpoints' => [[
                'model_id' => $model['id'],
                'tag' => $model['endpoint'],
                'status' => 0,
                'context_length' => 1_000_000,
                'supported_parameters' => ['response_format', 'structured_outputs', $model['output_parameter']],
                'pricing' => $pricing,
                ...$endpointOverrides,
            ]]]];
        }

        throw new RuntimeException('Unexpected fake OpenRouter URL.');
    };
}

it('freezes exactly the fifteen approved models and safe request options', function (): void {
    $models = LiveGroupingModels::all();
    $ids = array_column($models, 'id');

    expect($models)->toHaveCount(15)
        ->and($ids)->not->toContain(
            'bytedance-seed/seed-2.0-mini',
            'moonshotai/kimi-k2.5',
            'google/gemma-3-12b-it',
        )
        ->and(LiveGroupingModels::configurations())->toHaveCount(15);

    foreach ($models as $model) {
        $options = LiveGroupingModels::options($model)['openrouter'];

        expect($options[$model['output_parameter']])->toBe(512)
            ->and($options['provider'])->toMatchArray([
                'only' => [$model['endpoint']],
                'allow_fallbacks' => false,
                'require_parameters' => true,
                'data_collection' => 'deny',
                'zdr' => $model['zdr'],
            ]);
    }
});

it('defaults to a network-free dry run', function (): void {
    $fetches = 0;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        new InertLiveGroupingRunner,
        function () use (&$fetches): array {
            $fetches++;

            throw new RuntimeException('Dry-run should not fetch.');
        },
    );
    $result = $screen->execute([], []);

    expect($result['live'])->toBeFalse()
        ->and($result['runs'])->toBe([])
        ->and($result['output'])->toContain(
            'DRY RUN — no provider calls made',
            'Calls: 15 paid detector calls',
            'Logical spend cap: $5.00 USD',
        )
        ->and($fetches)->toBe(0);
});

it('refuses live execution without the exact confirmation or key', function (array $arguments, array $environment): void {
    /** @var list<string> $arguments */
    /** @var array<string, string> $environment */
    $fetches = 0;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        new InertLiveGroupingRunner,
        function () use (&$fetches): array {
            $fetches++;

            return [];
        },
    );

    expect(fn () => $screen->execute($arguments, $environment))->toThrow(RuntimeException::class)
        ->and($fetches)->toBe(0);
})->with([
    'live only' => [['--live'], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']],
    'wrong confirmation' => [['--live', '--confirm=wrong'], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']],
    'duplicate confirmation' => [[
        '--live',
        '--confirm='.LiveGroupingModels::CONFIRMATION,
        '--confirm='.LiveGroupingModels::CONFIRMATION,
    ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']],
    'missing key' => [[
        '--live',
        '--confirm='.LiveGroupingModels::CONFIRMATION,
    ], []],
]);

it('rejects unsafe key limits before the inference runner', function (array $keyOverrides): void {
    /** @var array<string, mixed> $keyOverrides */
    $model = LiveGroupingModels::all()[0];
    $runner = new InertLiveGroupingRunner;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        $runner,
        liveGroupingApi($model, $keyOverrides),
        [$model],
        offlineInference: true,
    );

    expect(fn () => $screen->execute([
        '--live',
        '--confirm='.LiveGroupingModels::CONFIRMATION,
    ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
        ->toThrow(RuntimeException::class)
        ->and($runner->calls)->toBe(0);
})->with([
    'unlimited' => [['limit' => null]],
    'oversized' => [['limit' => 50.01, 'limit_remaining' => 50.01]],
    'insufficient remaining' => [['limit_remaining' => 4.99]],
    'resetting daily' => [['limit_reset' => 'daily']],
    'free tier' => [['is_free_tier' => true]],
    'management key' => [['is_management_key' => true]],
]);

it('rejects stale route capabilities and prices before the inference runner', function (array $endpointOverrides): void {
    /** @var array<string, mixed> $endpointOverrides */
    $model = LiveGroupingModels::all()[0];
    $runner = new InertLiveGroupingRunner;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        $runner,
        liveGroupingApi($model, endpointOverrides: $endpointOverrides),
        [$model],
        offlineInference: true,
    );

    expect(fn () => $screen->execute([
        '--live',
        '--confirm='.LiveGroupingModels::CONFIRMATION,
    ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
        ->toThrow(RuntimeException::class)
        ->and($runner->calls)->toBe(0);
})->with([
    'unavailable endpoint' => [['status' => 1]],
    'missing structured output' => [['supported_parameters' => ['response_format']]],
    'missing output token parameter' => [['supported_parameters' => ['response_format', 'structured_outputs']]],
    'missing context length' => [['context_length' => null]],
    'excessive prompt rate' => [['pricing' => ['prompt' => '1', 'completion' => '0.00000047']]],
    'excessive override prompt rate' => [['pricing' => [
        'prompt' => '0.00000015',
        'completion' => '0.00000047',
        'overrides' => [['min_prompt_tokens' => 1, 'prompt' => '1']],
    ]]],
    'unexpected image rate' => [['pricing' => ['prompt' => '0.00000015', 'completion' => '0.00000047', 'image' => '1']]],
    'unexpected request rate' => [['pricing' => ['prompt' => '0.00000015', 'completion' => '0.00000047', 'request' => '1']]],
]);

it('refuses a call whose catalog-derived reservation cannot fit the software budget', function (): void {
    $model = LiveGroupingModels::all()[0];
    $runner = new InertLiveGroupingRunner;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        $runner,
        liveGroupingApi($model, endpointOverrides: ['pricing' => [
            'prompt' => '0.00000015',
            'completion' => '0.00000047',
            'input_cache_write_1h' => '0.000006',
        ]]),
        [$model],
        offlineInference: true,
    );

    expect(fn () => $screen->execute([
        '--live',
        '--confirm='.LiveGroupingModels::CONFIRMATION,
    ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
        ->toThrow(RuntimeException::class, 'cannot fit within the approved USD admission budget')
        ->and($runner->calls)->toBe(0);
});

it('rejects a route that is no longer ZDR before the inference runner', function (): void {
    $model = LiveGroupingModels::find('qwen/qwen2.5-vl-72b-instruct');
    $runner = new InertLiveGroupingRunner;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        $runner,
        liveGroupingApi($model, omitZdr: true),
        [$model],
        offlineInference: true,
    );

    expect(fn () => $screen->execute([
        '--live',
        '--confirm='.LiveGroupingModels::CONFIRMATION,
    ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
        ->toThrow(RuntimeException::class, 'is no longer ZDR')
        ->and($runner->calls)->toBe(0);
});

it('refuses a concurrent screen before any preflight request', function (): void {
    $root = dirname(__DIR__, 2);
    $lockPath = sys_get_temp_dir().'/lde-live-grouping-'.hash('sha256', $root).'.lock';
    $lock = fopen($lockPath, 'c+');

    if ($lock === false) {
        throw new RuntimeException('Unable to create the concurrency-test lock.');
    }

    flock($lock, LOCK_EX | LOCK_NB);
    $fetches = 0;
    $screen = new LiveGroupingScreen(
        $root,
        new InertLiveGroupingRunner,
        function () use (&$fetches): array {
            $fetches++;

            return [];
        },
    );

    try {
        expect(fn () => $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
            ->toThrow(RuntimeException::class, 'already running')
            ->and($fetches)->toBe(0);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        unlink($lockPath);
    }
});

it('runs one approved detector trial offline and removes private replay after full validation', function (): void {
    $root = dirname(__DIR__, 2);
    $model = LiveGroupingModels::all()[0];
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $screen = new LiveGroupingScreen(
        $root,
        new NativeCommandRunner($root),
        liveGroupingApi($model),
        [$model],
        offlineInference: true,
    );

    try {
        $result = $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']);
        $run = $result['runs'][0];

        expect($result['live'])->toBeTrue()
            ->and($result['output'])->toContain('Validated 1 paid detector calls')
            ->and($run['model'])->toBe($model['id'])
            ->and($run['cost_usd'])->toBe('0.012345')
            ->and($run['attempts'])->toBe(4)
            ->and(is_file($run['scorecard']))->toBeTrue()
            ->and(is_file(dirname($run['scorecard']).'/replay.private.json'))->toBeFalse()
            ->and(fn () => $screen->execute([
                '--live',
                '--confirm='.LiveGroupingModels::CONFIRMATION,
            ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
            ->toThrow(RuntimeException::class, 'already been consumed');
    } finally {
        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];

        foreach (array_diff($after, $before) as $directory) {
            $files = glob($directory.'/*') ?: [];

            foreach ($files as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
});

it('removes private replay when a completed child trial is rejected', function (): void {
    $root = dirname(__DIR__, 2);
    $model = LiveGroupingModels::all()[0];
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $runner = new class($root) implements CommandRunner
    {
        public function __construct(private readonly string $root) {}

        public function run(array $command, ?array $environment = null): CommandResult
        {
            $result = (new NativeCommandRunner($this->root))->run($command, $environment);

            return new CommandResult(99, $result->stdout, $result->stderr);
        }
    };
    $screen = new LiveGroupingScreen(
        $root,
        $runner,
        liveGroupingApi($model),
        [$model],
        offlineInference: true,
    );

    try {
        expect(fn () => $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
            ->toThrow(RuntimeException::class, 'failed before producing one complete scorecard');

        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
        $created = array_values(array_diff($after, $before));

        expect($created)->toHaveCount(1)
            ->and(is_file($created[0].'/replay.private.json'))->toBeFalse()
            ->and(fn () => $screen->execute([
                '--live',
                '--confirm='.LiveGroupingModels::CONFIRMATION,
            ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
            ->toThrow(RuntimeException::class, 'unresolved paid call');
    } finally {
        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];

        foreach (array_diff($after, $before) as $directory) {
            $files = glob($directory.'/*') ?: [];

            foreach ($files as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
});

it('rejects an unexpected scorer set and removes its private replay', function (): void {
    $root = dirname(__DIR__, 2);
    $model = LiveGroupingModels::all()[0];
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $runner = new class($root) implements CommandRunner
    {
        public function __construct(private readonly string $root) {}

        public function run(array $command, ?array $environment = null): CommandResult
        {
            $before = glob($this->root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
            $result = (new NativeCommandRunner($this->root))->run($command, $environment);
            $after = glob($this->root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
            $created = array_values(array_diff($after, $before));
            $scorecardPath = $created[0].'/scorecard.json';
            $scorecard = json_decode((string) file_get_contents($scorecardPath), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($scorecard)
                || ! is_array($scorecard['trials'] ?? null)
                || ! is_array($scorecard['trials'][0] ?? null)
                || ! is_array($scorecard['trials'][0]['results'] ?? null)
                || ! is_array($scorecard['trials'][0]['results'][1] ?? null)) {
                throw new RuntimeException('The generated scorecard cannot be mutated for the scorer-set test.');
            }

            $scorecard['trials'][0]['results'][1]['scorer'] = 'unexpected-scorer';
            file_put_contents($scorecardPath, json_encode($scorecard, JSON_THROW_ON_ERROR));

            return $result;
        }
    };
    $screen = new LiveGroupingScreen(
        $root,
        $runner,
        liveGroupingApi($model),
        [$model],
        offlineInference: true,
    );

    try {
        expect(fn () => $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
            ->toThrow(RuntimeException::class, 'does not match the approved configuration');

        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
        $created = array_values(array_diff($after, $before));

        expect($created)->toHaveCount(1)
            ->and(is_file($created[0].'/replay.private.json'))->toBeFalse();
    } finally {
        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];

        foreach (array_diff($after, $before) as $directory) {
            $files = glob($directory.'/*') ?: [];

            foreach ($files as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
});

it('rejects malformed live price evidence without throwing', function (mixed $amount): void {
    $output = json_encode([
        'calls' => [
            [
                'reference' => 'invocation:1',
                'ordinal' => 1,
                'stage' => 'detection',
                'mode' => 'live',
                'requested_identity' => ['provider' => 'openrouter', 'model' => 'qwen/qwen3.8-flash'],
                'effective_identity' => ['provider' => 'openrouter', 'model' => 'qwen/qwen3.8-flash'],
                'cost_quote' => ['cost' => ['amount' => $amount, 'currency' => 'USD']],
            ],
            [
                'reference' => 'invocation:2',
                'ordinal' => 2,
                'stage' => 'extraction',
                'mode' => 'simulated',
                'requested_identity' => ['provider' => 'openrouter', 'model' => 'qwen/qwen3.8-flash'],
                'effective_identity' => ['provider' => 'openrouter', 'model' => 'qwen/qwen3.8-flash'],
                'cost_quote' => null,
            ],
        ],
        'cost' => [
            'known_by_currency' => ['USD' => ['amount' => $amount, 'currency' => 'USD']],
            'unpriced_calls' => ['invocation:2'],
            'complete' => false,
            'mode' => 'mixed',
        ],
    ], JSON_THROW_ON_ERROR);

    expect((new LiveGroupingAttemptScorer)->score('', $output)->score)->toBe(0.0);
})->with([
    'letters' => ['not a decimal'],
    'non-finite string' => ['NaN'],
    'wrong type' => [[]],
]);

it('validates effective identity for a priced detector with no extractable groups', function (string $effective, float $expected): void {
    $output = json_encode([
        'calls' => [[
            'reference' => 'invocation:1',
            'ordinal' => 1,
            'stage' => 'detection',
            'mode' => 'live',
            'requested_identity' => ['provider' => 'openrouter', 'model' => 'qwen/qwen3.8-flash'],
            'effective_identity' => ['provider' => 'openrouter', 'model' => $effective],
            'cost_quote' => ['cost' => ['amount' => '0.012345', 'currency' => 'USD']],
        ]],
        'cost' => [
            'known_by_currency' => ['USD' => ['amount' => '0.012345', 'currency' => 'USD']],
            'unpriced_calls' => [],
            'complete' => true,
            'mode' => 'live',
        ],
    ], JSON_THROW_ON_ERROR);

    expect((new LiveGroupingAttemptScorer)->score('', $output)->score)->toBe($expected);
})->with([
    'requested alias' => ['qwen/qwen3.8-flash', 1.0],
    'pinned canonical model' => ['qwen/qwen3.8-flash-20260826', 1.0],
    'unexpected model' => ['other/model', 0.0],
]);
