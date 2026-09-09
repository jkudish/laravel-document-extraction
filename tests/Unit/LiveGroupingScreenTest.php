<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Illuminate\Filesystem\Filesystem;
use Jkudish\DocumentExtraction\Dev\LiveGroupingAuthorization;
use Jkudish\DocumentExtraction\Dev\LiveGroupingScreen;
use Jkudish\DocumentExtraction\Dev\PrWorkflow\CommandResult;
use Jkudish\DocumentExtraction\Dev\PrWorkflow\CommandRunner;
use Jkudish\DocumentExtraction\Dev\PrWorkflow\NativeCommandRunner;
use Jkudish\DocumentExtraction\Tests\Support\GroupingBenchmarkCorpus;
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

final class UnavailableLiveCostRunner implements CommandRunner
{
    public int $calls = 0;

    public function __construct(
        private readonly string $root,
        private readonly bool $technicalFailure = false,
    ) {}

    public function run(array $command, ?array $environment = null): CommandResult
    {
        $this->calls++;
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
            || ! is_array($scorecard['trials'][0]['results'][0] ?? null)
            || ! is_array($scorecard['trials'][0]['results'][0]['measurements'] ?? null)
            || ! is_array($scorecard['trials'][0]['results'][0]['measurements'][0] ?? null)) {
            throw new RuntimeException('The generated scorecard cannot be mutated for the unavailable-cost test.');
        }

        $primary = &$scorecard['trials'][0]['results'][0];
        $measurement = &$primary['measurements'][0];
        $measurement['effective_model'] = null;
        $measurement['usage'] = [
            'cached_input_tokens' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ];
        $measurement['pricing'] = [
            'completeness' => 'unavailable',
            'snapshot' => [
                'completeness' => 'unavailable',
                'cost' => null,
                'missing_units' => [],
                'provenance' => null,
                'snapshot' => null,
                'source' => 'unavailable',
            ],
        ];

        if ($this->technicalFailure) {
            $primary['score'] = 0;
            $primary['passed'] = false;
            $primary['measurements'] = [$measurement];
            $scorecard['trials'][0]['results'] = [$primary];
        } else {
            foreach ($scorecard['trials'][0]['results'] as &$scoreResult) {
                if (is_array($scoreResult)
                    && is_array($scoreResult['measurements'] ?? null)
                    && is_array($scoreResult['measurements'][0] ?? null)) {
                    $scoreResult['measurements'][0] = $measurement;
                }
            }

            unset($scoreResult);
        }

        file_put_contents($scorecardPath, json_encode($scorecard, JSON_THROW_ON_ERROR));

        return $this->technicalFailure
            ? new CommandResult(1, $result->stdout, $result->stderr)
            : $result;
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
    return sys_get_temp_dir().'/lde-live-grouping-screen-test-'.getmypid().'.json';
}

/** @return list<string> */
function liveGroupingStageTrialIds(string $stageId): array
{
    $stage = LiveGroupingModels::stage($stageId);
    $trialIds = [];

    foreach (LiveGroupingModels::survivors() as $model) {
        foreach ($stage['fixture_ids'] as $fixtureId) {
            foreach (range(1, $stage['repetitions']) as $repetition) {
                $trialIds[] = $model['id'].'|'.$fixtureId.'|repeat-'.$repetition;
            }
        }
    }

    return $trialIds;
}

/**
 * @param  list<string>  $completed
 * @return list<array{trial: string, scorecard_sha256: string}>
 */
function liveGroupingEvidence(array $completed): array
{
    return array_map(
        static fn (string $trial): array => [
            'trial' => $trial,
            'scorecard_sha256' => hash('sha256', $trial),
        ],
        $completed,
    );
}

/** @param array{id: string, canonical: string, endpoint: string, route: array{provider_name: string, data_region: string, service_tier: ?string}, zdr: bool, reasoning: bool, output_parameter: string, max_price: array{prompt: float, completion: float, image?: float}} $model
 * @param  array<string, mixed>  $keyOverrides
 * @param  array<string, mixed>  $endpointOverrides
 * @return Closure(string, ?string): array<string, mixed>
 */
function liveGroupingApi(
    array $model,
    array $keyOverrides = [],
    array $endpointOverrides = [],
    bool $omitZdr = false,
    bool $lagAllowance = false,
): Closure {
    $keyChecks = 0;

    return static function (string $url, ?string $key) use ($model, $keyOverrides, $endpointOverrides, $omitZdr, $lagAllowance, &$keyChecks): array {
        if ($url === 'https://openrouter.ai/api/v1/key') {
            expect($key)->toBe('synthetic-openrouter-canary');
            $keyChecks++;
            $remaining = $keyOverrides['limit_remaining'] ?? 50;

            if (! $lagAllowance
                && $keyChecks >= 3
                && (is_int($remaining) || is_float($remaining) || is_string($remaining))) {
                $remaining = (string) BigDecimal::of(is_float($remaining) ? (string) $remaining : $remaining)
                    ->minus('0.012345');
            }

            $data = [
                'limit' => 50,
                'limit_reset' => 'monthly',
                'is_free_tier' => false,
                'is_management_key' => false,
                'is_provisioning_key' => false,
                ...$keyOverrides,
            ];
            $data['limit_remaining'] = $remaining;

            return ['data' => $data];
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
                'provider_name' => $model['route']['provider_name'],
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

/**
 * @param  non-empty-list<array{id: string, canonical: string, endpoint: string, route: array{provider_name: string, data_region: string, service_tier: ?string}, zdr: bool, reasoning: bool, output_parameter: string, max_price: array{prompt: float, completion: float, image?: float}}>  $models
 * @param  Closure(int): string  $remaining
 * @param  array<string, mixed>  $endpointOverrides
 * @return Closure(string, ?string): array<string, mixed>
 */
function liveGroupingApis(array $models, Closure $remaining, array $endpointOverrides = []): Closure
{
    $keyChecks = 0;

    return static function (string $url, ?string $key) use ($models, $remaining, $endpointOverrides, &$keyChecks): array {
        if ($url === 'https://openrouter.ai/api/v1/key') {
            $keyChecks++;

            return ['data' => [
                'limit' => 50,
                'limit_remaining' => $remaining($keyChecks),
                'limit_reset' => 'monthly',
                'is_free_tier' => false,
                'is_management_key' => false,
                'is_provisioning_key' => false,
            ]];
        }

        if ($url === 'https://openrouter.ai/api/v1/models') {
            $data = [];

            foreach ($models as $model) {
                $response = liveGroupingApi($model)($url, $key);

                if (! is_array($response['data'] ?? null) || ! is_array($response['data'][0] ?? null)) {
                    throw new RuntimeException('The fake model catalog is malformed.');
                }

                $data[] = $response['data'][0];
            }

            return ['data' => $data];
        }

        if ($url === 'https://openrouter.ai/api/v1/endpoints/zdr') {
            $data = [];

            foreach ($models as $model) {
                $response = liveGroupingApi($model)($url, $key);

                if (! is_array($response['data'] ?? null)) {
                    throw new RuntimeException('The fake ZDR catalog is malformed.');
                }

                $data = array_merge($data, $response['data']);
            }

            return ['data' => $data];
        }

        foreach ($models as $model) {
            if ($url === 'https://openrouter.ai/api/v1/models/'.$model['id'].'/endpoints') {
                return liveGroupingApi($model, endpointOverrides: $endpointOverrides)($url, $key);
            }
        }

        throw new RuntimeException("Unexpected live grouping API request [{$url}].");
    };
}

it('freezes the three-finalist development-repeat and holdout matrices', function (): void {
    $models = LiveGroupingModels::all();
    $ids = array_column($models, 'id');
    $luna = LiveGroupingModels::find('openai/gpt-5.6-luna');

    expect($models)->toHaveCount(15)
        ->and($ids)->not->toContain(
            'bytedance-seed/seed-2.0-mini',
            'moonshotai/kimi-k2.5',
            'google/gemma-3-12b-it',
        )
        ->and(array_column(LiveGroupingModels::survivors(), 'id'))->toBe(LiveGroupingModels::SURVIVOR_IDS)
        ->and(LiveGroupingModels::SURVIVOR_IDS)->toBe([
            'google/gemini-2.5-flash',
            'openai/gpt-5.6-luna',
            'anthropic/claude-haiku-4.5',
        ])
        ->and(LiveGroupingModels::configurations())->toHaveCount(3)
        ->and($luna['endpoint'])->toBe('azure/eu')
        ->and($luna['route']['data_region'])->toBe('global')
        ->and(LiveGroupingModels::fixtureIds())->toBe([
            'single-three-page-document',
            'three-single-page-documents',
            'blank-separator',
            'mixed-document-lengths',
            'non-financial-documents',
        ])
        ->not->toContain('same-issuer-invoices', 'ambiguous-orphan', 'scan-like-raster')
        ->and(LiveGroupingModels::stage(LiveGroupingModels::DEVELOPMENT_STAGE))->toMatchArray([
            'fixture_ids' => LiveGroupingModels::FIXTURE_IDS,
            'fixture_split' => 'prompt-example',
            'repetitions' => 2,
            'max_spend_usd' => 10.0,
        ])
        ->and(LiveGroupingModels::stage(LiveGroupingModels::HOLDOUT_STAGE))->toMatchArray([
            'fixture_ids' => LiveGroupingModels::HOLDOUT_FIXTURE_IDS,
            'fixture_split' => 'holdout',
            'repetitions' => 3,
            'max_spend_usd' => 10.0,
        ]);

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

    expect(fn () => LiveGroupingModels::configurations('qwen/qwen3.8-flash'))
        ->toThrow(InvalidArgumentException::class, 'not approved');
});

it('rejects duplicate and holdout fixtures before any request', function (array $fixtures): void {
    /** @var list<string> $fixtures */
    $fetches = 0;

    expect(fn () => new LiveGroupingScreen(
        dirname(__DIR__, 2),
        new InertLiveGroupingRunner,
        function () use (&$fetches): array {
            $fetches++;

            return [];
        },
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: $fixtures,
    ))->toThrow(RuntimeException::class)
        ->and($fetches)->toBe(0);
})->with([
    'duplicate development fixture' => [[
        'single-three-page-document',
        'single-three-page-document',
    ]],
    'holdout fixture' => [['same-issuer-invoices']],
]);

it('rejects a grouping fixture file that does not match its approved identity', function (array $changes): void {
    /** @var array{file?: string, sha256?: string, size?: int} $changes */
    $fixture = GroupingBenchmarkCorpus::manifest()['fixtures'][0];

    expect(fn () => GroupingBenchmarkCorpus::verifiedFixturePath([...$fixture, ...$changes]))
        ->toThrow(RuntimeException::class, 'does not match its approved manifest identity');
})->with([
    'hash changed' => [['sha256' => str_repeat('0', 64)]],
    'size changed' => [['size' => 1]],
    'path escaped' => [['file' => '../manifest.json']],
]);

it('rejects a canary model that did not survive before any request', function (): void {
    $fetches = 0;

    expect(fn () => new LiveGroupingScreen(
        dirname(__DIR__, 2),
        new InertLiveGroupingRunner,
        function () use (&$fetches): array {
            $fetches++;

            return [];
        },
        [LiveGroupingModels::all()[0]],
        authorizationPath: liveGroupingAuthorizationPath(),
    ))->toThrow(RuntimeException::class, 'unapproved configuration')
        ->and($fetches)->toBe(0);
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
        authorizationPath: liveGroupingAuthorizationPath(),
        stage: LiveGroupingModels::DEVELOPMENT_STAGE,
    );
    $result = $screen->execute([], []);

    expect($result['live'])->toBeFalse()
        ->and($result['runs'])->toBe([])
        ->and($result['output'])->toContain(
            'DRY RUN — no provider calls made',
            'Stage: development-repeats',
            'Repetitions: 2 per model/fixture pair',
            'Calls: 30 paid detector calls',
            'Logical spend cap: $10.00 USD',
        )
        ->and($fetches)->toBe(0);
});

it('renders the frozen holdout as exactly twenty-seven network-free calls', function (): void {
    $fetches = 0;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        new InertLiveGroupingRunner,
        function () use (&$fetches): array {
            $fetches++;

            throw new RuntimeException('Dry-run should not fetch.');
        },
        authorizationPath: liveGroupingAuthorizationPath(),
        stage: LiveGroupingModels::HOLDOUT_STAGE,
    );

    expect($screen->execute([], [])['output'])->toContain(
        'Stage: frozen-holdout',
        'Repetitions: 3 per model/fixture pair',
        'Calls: 27 paid detector calls',
        'same-issuer-invoices',
        'ambiguous-orphan',
        'scan-like-raster',
    )->and($fetches)->toBe(0);
});

it('refuses the frozen holdout before network access without a completed development ledger', function (): void {
    $fetches = 0;
    $missingPrerequisite = sys_get_temp_dir().'/lde-missing-development-ledger-'.getmypid().'.json';
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        new InertLiveGroupingRunner,
        function () use (&$fetches): array {
            $fetches++;

            return [];
        },
        authorizationPath: liveGroupingAuthorizationPath(),
        stage: LiveGroupingModels::HOLDOUT_STAGE,
        prerequisiteAuthorizationPath: $missingPrerequisite,
    );

    expect(fn () => $screen->execute([
        '--live',
        '--confirm='.LiveGroupingModels::HOLDOUT_CONFIRMATION,
    ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
        ->toThrow(RuntimeException::class, 'requires a completed development-repeat authorization ledger')
        ->and($fetches)->toBe(0);
});

it('requires the exact completed development matrix evidence and frozen contract before holdout network access', function (string $fingerprint, bool $complete, bool $includeEvidence): void {
    $root = dirname(__DIR__, 2);
    $prerequisite = sys_get_temp_dir().'/lde-development-ledger-'.getmypid().'.json';
    $development = new LiveGroupingScreen(
        $root,
        new InertLiveGroupingRunner,
        authorizationPath: $prerequisite,
        stage: LiveGroupingModels::DEVELOPMENT_STAGE,
    );
    $trialIds = liveGroupingStageTrialIds(LiveGroupingModels::DEVELOPMENT_STAGE);
    $completedTrials = $complete ? $trialIds : array_slice($trialIds, 0, -1);
    file_put_contents($prerequisite, json_encode([
        'schema_version' => 4,
        'confirmation' => LiveGroupingModels::CONFIRMATION,
        'key_fingerprint' => hash('sha256', 'synthetic-openrouter-canary'),
        'contract_fingerprint' => $fingerprint === 'current'
            ? $development->contractFingerprint()
            : 'sha256:'.str_repeat('0', 64),
        'trial_ids' => $trialIds,
        'initial_remaining' => '50',
        'recorded_spend' => '0.1',
        'admission_spend' => '0.1',
        'completed_trials' => $completedTrials,
        'completed_evidence' => $includeEvidence ? liveGroupingEvidence($completedTrials) : [],
        'pending' => null,
    ], JSON_THROW_ON_ERROR));
    $fetches = 0;
    $holdout = new LiveGroupingScreen(
        $root,
        new InertLiveGroupingRunner,
        function () use (&$fetches): array {
            $fetches++;

            return [];
        },
        authorizationPath: liveGroupingAuthorizationPath(),
        stage: LiveGroupingModels::HOLDOUT_STAGE,
        prerequisiteAuthorizationPath: $prerequisite,
    );

    try {
        expect(fn () => $holdout->execute([
            '--live',
            '--confirm='.LiveGroupingModels::HOLDOUT_CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
            ->toThrow(RuntimeException::class, 'prerequisite is incomplete or its configuration is no longer frozen')
            ->and($fetches)->toBe(0);
    } finally {
        unlink($prerequisite);
    }
})->with([
    'incomplete matrix' => ['current', false, true],
    'stale contract' => ['stale', true, true],
    'missing scorecard evidence' => ['current', true, false],
]);

it('accepts an exact completed frozen development ledger before holdout preflight', function (): void {
    $root = dirname(__DIR__, 2);
    $prerequisite = sys_get_temp_dir().'/lde-development-ledger-'.getmypid().'.json';
    $development = new LiveGroupingScreen(
        $root,
        new InertLiveGroupingRunner,
        authorizationPath: $prerequisite,
        stage: LiveGroupingModels::DEVELOPMENT_STAGE,
    );
    $trialIds = liveGroupingStageTrialIds(LiveGroupingModels::DEVELOPMENT_STAGE);
    file_put_contents($prerequisite, json_encode([
        'schema_version' => 4,
        'confirmation' => LiveGroupingModels::CONFIRMATION,
        'key_fingerprint' => hash('sha256', 'synthetic-openrouter-canary'),
        'contract_fingerprint' => $development->contractFingerprint(),
        'trial_ids' => $trialIds,
        'initial_remaining' => '50',
        'recorded_spend' => '0.1',
        'admission_spend' => '0.1',
        'completed_trials' => $trialIds,
        'completed_evidence' => liveGroupingEvidence($trialIds),
        'pending' => null,
    ], JSON_THROW_ON_ERROR));
    $fetches = 0;
    $holdout = new LiveGroupingScreen(
        $root,
        new InertLiveGroupingRunner,
        function () use (&$fetches): array {
            $fetches++;

            return [];
        },
        authorizationPath: liveGroupingAuthorizationPath(),
        stage: LiveGroupingModels::HOLDOUT_STAGE,
        prerequisiteAuthorizationPath: $prerequisite,
    );

    try {
        expect(fn () => $holdout->execute([
            '--live',
            '--confirm='.LiveGroupingModels::HOLDOUT_CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
            ->toThrow(RuntimeException::class, 'OpenRouter key data must be an object')
            ->and($fetches)->toBe(1);
    } finally {
        unlink($prerequisite);
    }
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
        authorizationPath: liveGroupingAuthorizationPath(),
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

it('rejects direct benchmark invocation with every public selector before inference', function (): void {
    $root = dirname(__DIR__, 2);
    $fixture = GroupingBenchmarkCorpus::manifest()['fixtures'][0];
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $home = sys_get_temp_dir().'/lde-direct-live-grouping-'.bin2hex(random_bytes(8));

    mkdir($home, 0700, true);
    mkdir($home.'/tmp', 0700, true);

    try {
        $result = (new NativeCommandRunner($root))->run([
            PHP_BINARY,
            'vendor/bin/pest',
            'tests/Evals/LiveGroupingBenchmarkTest.php',
            '--no-tia',
            '--evals',
            '--colors=never',
        ], [
            'HOME' => $home,
            'TMPDIR' => $home.'/tmp',
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'PAO_DISABLE' => '1',
            'OPENROUTER_API_KEY' => 'synthetic-openrouter-canary',
            'LDE_LIVE_GROUPING_CONFIRM' => LiveGroupingModels::CONFIRMATION,
            'LDE_LIVE_GROUPING_STAGE' => LiveGroupingModels::DEVELOPMENT_STAGE,
            'LDE_LIVE_GROUPING_MODEL' => LiveGroupingModels::SURVIVOR_IDS[0],
            'LDE_LIVE_GROUPING_FIXTURE' => $fixture['id'],
            'LDE_LIVE_GROUPING_REPETITION' => '1',
            'LDE_LIVE_GROUPING_FIXTURE_SHA256' => $fixture['sha256'],
            'LDE_LIVE_GROUPING_FIXTURE_SIZE' => (string) $fixture['size'],
            'LDE_LIVE_GROUPING_OFFLINE' => '1',
        ]);

        expect($result->exitCode)->not->toBe(0)
            ->and($result->stdout.$result->stderr)->toContain('lacks a private parent capability')
            ->and(glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [])->toBe($before);
    } finally {
        (new Filesystem)->deleteDirectory($home);
    }
});

it('authenticates durable authorization state and rejects altered completion evidence', function (): void {
    $key = str_repeat('a', 64);
    $root = dirname(__DIR__, 2);
    $ledgerPath = LiveGroupingAuthorization::path(
        $root,
        new NativeCommandRunner($root),
        LiveGroupingModels::DEVELOPMENT_STAGE,
    );
    $authorization = LiveGroupingAuthorization::signed([
        'schema_version' => 4,
        'completed_trials' => ['trial-1'],
        'completed_evidence' => liveGroupingEvidence(['trial-1']),
    ], $key);

    expect($ledgerPath)->not->toStartWith($root.'/storage/')
        ->and($ledgerPath)->toContain('/laravel-document-extraction/live-grouping/');

    LiveGroupingAuthorization::assertAuthentic($authorization, $key);
    $altered = $authorization;
    $altered['completed_trials'] = ['trial-1', 'trial-2'];

    expect(fn () => LiveGroupingAuthorization::assertAuthentic($altered, $key))
        ->toThrow(RuntimeException::class, 'is not authentic');
});

it('consumes an authenticated parent capability exactly once', function (): void {
    $directory = sys_get_temp_dir().'/lde-live-capability-'.bin2hex(random_bytes(8));
    $ledger = $directory.'/authorization.json';
    $capability = bin2hex(random_bytes(32));
    $model = LiveGroupingModels::SURVIVOR_IDS[0];
    $fixture = LiveGroupingModels::FIXTURE_IDS[0];
    $trial = $model.'|'.$fixture.'|repeat-1';
    $environment = [
        'LDE_LIVE_GROUPING_STAGE' => LiveGroupingModels::DEVELOPMENT_STAGE,
        'LDE_LIVE_GROUPING_MODEL' => $model,
        'LDE_LIVE_GROUPING_FIXTURE' => $fixture,
        'LDE_LIVE_GROUPING_REPETITION' => '1',
        'LDE_LIVE_GROUPING_TRIAL' => $trial,
        'OPENROUTER_API_KEY' => 'synthetic-openrouter-canary',
        LiveGroupingAuthorization::CAPABILITY_ENV => $capability,
        LiveGroupingAuthorization::LEDGER_ENV => $ledger,
        LiveGroupingAuthorization::AUTHENTICATED_ENV => '1',
        'LDE_LIVE_GROUPING_OFFLINE' => '1',
    ];
    $original = [];

    foreach ($environment as $name => $value) {
        $original[$name] = getenv($name);
        putenv($name.'='.$value);
    }

    try {
        $key = LiveGroupingAuthorization::key($ledger, create: true);
        LiveGroupingAuthorization::write($ledger, LiveGroupingAuthorization::signed([
            'schema_version' => 4,
            'key_fingerprint' => hash('sha256', 'synthetic-openrouter-canary'),
            'pending' => [
                'trial' => $trial,
                'reservation' => '1',
                'capability_hash' => hash('sha256', $capability),
                'claimed' => false,
            ],
        ], $key));

        LiveGroupingAuthorization::claim(dirname(__DIR__, 2));
        $claimed = LiveGroupingAuthorization::read($ledger);
        LiveGroupingAuthorization::assertAuthentic($claimed, $key);
        $pending = $claimed['pending'] ?? null;

        expect($pending)->toBeArray();

        if (! is_array($pending)) {
            throw new RuntimeException('Expected a pending authorization record.');
        }

        expect($pending['claimed'] ?? null)->toBeTrue()
            ->and(fn () => LiveGroupingAuthorization::claim(dirname(__DIR__, 2)))
            ->toThrow(RuntimeException::class, 'invalid or already consumed');
    } finally {
        foreach ($original as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }

        (new Filesystem)->deleteDirectory($directory);
    }
});

it('binds the active document preparation worker into the frozen contract fingerprint', function (): void {
    $root = dirname(__DIR__, 2);
    $copy = sys_get_temp_dir().'/lde-grouping-contract-'.bin2hex(random_bytes(8));
    $paths = array_values(array_unique([
        ...GroupingBenchmarkCorpus::dependencies(),
        'scripts/LiveGroupingAuthorization.php',
        'scripts/LiveGroupingScreen.php',
        'scripts/live-grouping-screen',
        'tests/Evals/LiveGroupingBenchmarkTest.php',
        'tests/Support/LiveGroupingAttemptScorer.php',
        'tests/Support/LiveGroupingModels.php',
        'tests/Support/OpenRouterGenerationMetadata.php',
    ]));

    try {
        foreach ($paths as $path) {
            $target = $copy.'/'.$path;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }

            copy($root.'/'.$path, $target);
        }

        $screen = new LiveGroupingScreen(
            $copy,
            new InertLiveGroupingRunner,
            authorizationPath: $copy.'/authorization.json',
            stage: LiveGroupingModels::DEVELOPMENT_STAGE,
        );
        $before = $screen->contractFingerprint();
        file_put_contents(
            $copy.'/resources/workers/document-preparation.php',
            "\n// Simulated worker drift.\n",
            FILE_APPEND,
        );

        expect($screen->contractFingerprint())->not->toBe($before);
    } finally {
        (new Filesystem)->deleteDirectory($copy);
    }
});

it('rejects unsafe key limits before the inference runner', function (array $keyOverrides): void {
    /** @var array<string, mixed> $keyOverrides */
    $model = LiveGroupingModels::survivors()[0];
    $runner = new InertLiveGroupingRunner;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        $runner,
        liveGroupingApi($model, $keyOverrides),
        [$model],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
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
    'insufficient remaining' => [['limit_remaining' => 3.99]],
    'resetting daily' => [['limit_reset' => 'daily']],
    'free tier' => [['is_free_tier' => true]],
    'management key' => [['is_management_key' => true]],
]);

it('rejects stale route capabilities and prices before the inference runner', function (array $endpointOverrides): void {
    /** @var array<string, mixed> $endpointOverrides */
    $model = LiveGroupingModels::survivors()[0];
    $runner = new InertLiveGroupingRunner;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        $runner,
        liveGroupingApi($model, endpointOverrides: $endpointOverrides),
        [$model],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
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
        'prompt' => '0.000000104',
        'completion' => '0.000000416',
        'overrides' => [['min_prompt_tokens' => 1, 'prompt' => '1']],
    ]]],
    'unexpected image rate' => [['pricing' => ['prompt' => '0.000000104', 'completion' => '0.000000416', 'image' => '1']]],
    'unexpected request rate' => [['pricing' => ['prompt' => '0.000000104', 'completion' => '0.000000416', 'request' => '1']]],
    'unknown pricing unit' => [['pricing' => [
        'prompt' => '0.000000104',
        'completion' => '0.000000416',
        'future_input_cache' => '0.000001',
    ]]],
]);

it('refuses a call whose catalog-derived reservation cannot fit the software budget', function (): void {
    $model = LiveGroupingModels::survivors()[0];
    $runner = new InertLiveGroupingRunner;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        $runner,
        liveGroupingApi($model, endpointOverrides: ['pricing' => [
            'prompt' => '0.000000104',
            'completion' => '0.000000416',
            'image' => '0.0000003',
            'input_cache_write_1h' => '0.000011',
        ]]),
        [$model],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
    );

    expect(fn () => $screen->execute([
        '--live',
        '--confirm='.LiveGroupingModels::CONFIRMATION,
    ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
        ->toThrow(RuntimeException::class, 'cannot fit within the approved USD admission budget')
        ->and($runner->calls)->toBe(0);
});

it('rejects a route that is no longer ZDR before the inference runner', function (): void {
    $model = LiveGroupingModels::find('openai/gpt-5.6-luna');
    $runner = new InertLiveGroupingRunner;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        $runner,
        liveGroupingApi($model, omitZdr: true),
        [$model],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
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
        authorizationPath: liveGroupingAuthorizationPath(),
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
    $model = LiveGroupingModels::survivors()[0];
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $screen = new LiveGroupingScreen(
        $root,
        new NativeCommandRunner($root),
        liveGroupingApi($model),
        [$model],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: [LiveGroupingModels::fixtureIds()[0]],
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
            ->and($run['fixture'])->toBe(LiveGroupingModels::fixtureIds()[0])
            ->and($run['cost_usd'])->toBe('0.012345')
            ->and($run['cost_source'])->toBe('provider_reported')
            ->and($run['attempts'])->toBe(2)
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

it('uses immediate key allowance depletion when the observer cannot expose response pricing', function (): void {
    $root = dirname(__DIR__, 2);
    $model = LiveGroupingModels::survivors()[0];
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $runner = new UnavailableLiveCostRunner($root);
    $screen = new LiveGroupingScreen(
        $root,
        $runner,
        liveGroupingApi($model),
        [$model],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: [LiveGroupingModels::fixtureIds()[0]],
    );

    try {
        $result = $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']);

        expect($result['runs'][0]['cost_usd'])->toBe('0.012345')
            ->and($result['runs'][0]['cost_source'])->toBe('key_allowance_change');
    } finally {
        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];

        foreach (array_diff($after, $before) as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
});

it('uses validated provider cost while key allowance reporting lags', function (): void {
    $root = dirname(__DIR__, 2);
    $model = LiveGroupingModels::survivors()[0];
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $screen = new LiveGroupingScreen(
        $root,
        new NativeCommandRunner($root),
        liveGroupingApi($model, lagAllowance: true),
        [$model],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: [LiveGroupingModels::fixtureIds()[0]],
    );

    try {
        $result = $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']);

        expect($result['runs'][0]['cost_usd'])->toBe('0.012345')
            ->and($result['runs'][0]['cost_source'])->toBe('provider_reported')
            ->and($result['output'])->toContain('reconciled spend was $0.012345');
    } finally {
        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];

        foreach (array_diff($after, $before) as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
});

it("does not attribute a delayed allowance change to a later call's reservation", function (): void {
    $root = dirname(__DIR__, 2);
    [$first, $second] = array_slice(LiveGroupingModels::survivors(), 0, 2);
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $screen = new LiveGroupingScreen(
        $root,
        new NativeCommandRunner($root),
        liveGroupingApis(
            [$first, $second],
            static fn (int $check): string => $check >= 5 ? '49.8' : '50',
        ),
        [$first, $second],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: [LiveGroupingModels::fixtureIds()[0]],
    );

    try {
        $result = $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']);

        expect($result['runs'])->toHaveCount(2)
            ->and($result['runs'][1]['cost_usd'])->toBe('0.012345')
            ->and($result['runs'][1]['cost_source'])->toBe('provider_reported')
            ->and($result['output'])->toContain('key allowance change $0.2');
    } finally {
        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];

        foreach (array_diff($after, $before) as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
});

it('retains the full reservation when a successful call has no authoritative cost', function (): void {
    $root = dirname(__DIR__, 2);
    $models = LiveGroupingModels::survivors();
    [$first, $second] = [$models[1], $models[2]];
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $runner = new UnavailableLiveCostRunner($root);
    $screen = new LiveGroupingScreen(
        $root,
        $runner,
        liveGroupingApis(
            [$first, $second],
            static fn (int $check): string => (string) BigDecimal::of('50')
                ->minus((string) (intdiv($check - 1, 2) * 0.001)),
            ['pricing' => [
                'prompt' => '0.000000104',
                'completion' => '0.000000416',
                'input_cache_write_1h' => '0.0000049',
            ]],
        ),
        [$first, $second],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
    );

    try {
        expect(fn () => $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
            ->toThrow(RuntimeException::class, 'cannot fit within the approved USD admission budget')
            ->and($runner->calls)->toBe(2);

        $authorization = json_decode(
            (string) file_get_contents(liveGroupingAuthorizationPath()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($authorization) || ! is_string($authorization['admission_spend'] ?? null)) {
            throw new RuntimeException('The authorization ledger did not decode to an object.');
        }

        expect(BigDecimal::of($authorization['admission_spend'])->isEqualTo('9.800425984'))->toBeTrue()
            ->and($authorization['completed_trials'] ?? null)->toBe([
                $first['id'].'|'.LiveGroupingModels::fixtureIds()[0],
                $first['id'].'|'.LiveGroupingModels::fixtureIds()[1],
            ])
            ->and(array_key_exists('pending', $authorization))->toBeTrue()
            ->and($authorization['pending'])->toBeNull();
    } finally {
        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];

        foreach (array_diff($after, $before) as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
});

it('retains the full reservation for a technical failure with no observed charge', function (): void {
    $root = dirname(__DIR__, 2);
    $models = LiveGroupingModels::survivors();
    [$first, $second] = [$models[1], $models[2]];
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $runner = new UnavailableLiveCostRunner($root, technicalFailure: true);
    $screen = new LiveGroupingScreen(
        $root,
        $runner,
        liveGroupingApis(
            [$first, $second],
            static fn (): string => '50',
            ['pricing' => [
                'prompt' => '0.000000104',
                'completion' => '0.000000416',
                'input_cache_write_1h' => '0.0000049',
            ]],
        ),
        [$first, $second],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
    );

    try {
        expect(fn () => $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
            ->toThrow(RuntimeException::class, 'cannot fit within the approved USD admission budget')
            ->and($runner->calls)->toBe(2);

        $authorization = json_decode(
            (string) file_get_contents(liveGroupingAuthorizationPath()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($authorization) || ! is_string($authorization['admission_spend'] ?? null)) {
            throw new RuntimeException('The authorization ledger did not decode to an object.');
        }

        expect($authorization['recorded_spend'] ?? null)->toBe('0')
            ->and(BigDecimal::of($authorization['admission_spend'])->isEqualTo('9.800425984'))->toBeTrue();
    } finally {
        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];

        foreach (array_diff($after, $before) as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
});

it('preserves admission reservations across a resumed run', function (): void {
    $root = dirname(__DIR__, 2);
    $models = LiveGroupingModels::survivors();
    [$first, $second] = [$models[1], $models[2]];
    $trialIds = collect([$first, $second])
        ->flatMap(static fn (array $model): array => array_map(
            static fn (string $fixture): string => $model['id'].'|'.$fixture,
            LiveGroupingModels::fixtureIds(),
        ))
        ->values()
        ->all();
    $runner = new InertLiveGroupingRunner;
    $screen = new LiveGroupingScreen(
        $root,
        $runner,
        liveGroupingApis(
            [$first, $second],
            static fn (): string => '49.999',
            ['pricing' => [
                'prompt' => '0.000000104',
                'completion' => '0.000000416',
                'input_cache_write_1h' => '0.0000049',
            ]],
        ),
        [$first, $second],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
    );
    $completed = array_slice($trialIds, 0, 2);
    file_put_contents(liveGroupingAuthorizationPath(), json_encode([
        'schema_version' => 4,
        'confirmation' => LiveGroupingModels::CONFIRMATION,
        'key_fingerprint' => hash('sha256', 'synthetic-openrouter-canary'),
        'contract_fingerprint' => $screen->contractFingerprint(),
        'trial_ids' => $trialIds,
        'initial_remaining' => '50',
        'recorded_spend' => '0.001',
        'admission_spend' => '9.800425984',
        'completed_trials' => $completed,
        'completed_evidence' => liveGroupingEvidence($completed),
        'pending' => null,
    ], JSON_THROW_ON_ERROR));

    expect(fn () => $screen->execute([
        '--live',
        '--confirm='.LiveGroupingModels::CONFIRMATION,
    ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
        ->toThrow(RuntimeException::class, 'cannot fit within the approved USD admission budget')
        ->and($runner->calls)->toBe(0);
});

it('records one bounded technical failure and continues without inventing model evidence', function (): void {
    $root = dirname(__DIR__, 2);
    $model = LiveGroupingModels::survivors()[0];
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $runner = new UnavailableLiveCostRunner($root, technicalFailure: true);
    $screen = new LiveGroupingScreen(
        $root,
        $runner,
        liveGroupingApi($model),
        [$model],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: [LiveGroupingModels::fixtureIds()[0]],
    );

    try {
        $result = $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']);

        expect($result['runs'][0]['status'])->toBe('technical_failure')
            ->and($result['runs'][0]['cost_usd'])->toBe('0.012345')
            ->and($result['runs'][0]['attempts'])->toBe(1);
    } finally {
        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];

        foreach (array_diff($after, $before) as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
});

it('resumes a clean completed prefix against the original allowance baseline', function (): void {
    $root = dirname(__DIR__, 2);
    [$first, $second] = array_slice(LiveGroupingModels::survivors(), 0, 2);
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $screen = new LiveGroupingScreen(
        $root,
        new NativeCommandRunner($root),
        liveGroupingApi($second, keyOverrides: ['limit_remaining' => 49.99]),
        [$first, $second],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: [LiveGroupingModels::fixtureIds()[0]],
    );
    $completed = [$first['id'].'|'.LiveGroupingModels::fixtureIds()[0]];
    $authorization = [
        'schema_version' => 4,
        'confirmation' => LiveGroupingModels::CONFIRMATION,
        'key_fingerprint' => hash('sha256', 'synthetic-openrouter-canary'),
        'contract_fingerprint' => $screen->contractFingerprint(),
        'trial_ids' => [
            $first['id'].'|'.LiveGroupingModels::fixtureIds()[0],
            $second['id'].'|'.LiveGroupingModels::fixtureIds()[0],
        ],
        'initial_remaining' => '50',
        'recorded_spend' => '0.012345',
        'admission_spend' => '0.012345',
        'completed_trials' => $completed,
        'completed_evidence' => liveGroupingEvidence($completed),
        'pending' => null,
    ];
    file_put_contents(
        liveGroupingAuthorizationPath(),
        json_encode($authorization, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
    );

    try {
        $result = $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']);

        expect($result['output'])->toContain('Validated 2 paid detector calls')
            ->and($result['runs'])->toHaveCount(1)
            ->and($result['runs'][0]['model'])->toBe($second['id']);
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

/** @param list<string> $completed */
it('rejects duplicate or reordered completed trial identities when resuming', function (array $completed): void {
    /** @var list<string> $completed */
    [$first, $second] = array_slice(LiveGroupingModels::survivors(), 0, 2);
    $fixture = LiveGroupingModels::fixtureIds()[0];
    $trialIds = [$first['id'].'|'.$fixture, $second['id'].'|'.$fixture];
    $runner = new InertLiveGroupingRunner;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        $runner,
        liveGroupingApi($first, keyOverrides: ['limit_remaining' => '49.999']),
        [$first, $second],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: [$fixture],
    );

    file_put_contents(liveGroupingAuthorizationPath(), json_encode([
        'schema_version' => 4,
        'confirmation' => LiveGroupingModels::CONFIRMATION,
        'key_fingerprint' => hash('sha256', 'synthetic-openrouter-canary'),
        'contract_fingerprint' => $screen->contractFingerprint(),
        'trial_ids' => $trialIds,
        'initial_remaining' => '50',
        'recorded_spend' => '0.001',
        'admission_spend' => '0.001',
        'completed_trials' => $completed,
        'completed_evidence' => liveGroupingEvidence($completed),
        'pending' => null,
    ], JSON_THROW_ON_ERROR));

    expect(fn () => $screen->execute([
        '--live',
        '--confirm='.LiveGroupingModels::CONFIRMATION,
    ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
        ->toThrow(RuntimeException::class, 'ledger is invalid')
        ->and($runner->calls)->toBe(0);
})->with([
    'reordered' => fn (): array => [
        LiveGroupingModels::survivors()[1]['id'].'|'.LiveGroupingModels::fixtureIds()[0],
    ],
    'duplicate' => fn (): array => [
        LiveGroupingModels::survivors()[0]['id'].'|'.LiveGroupingModels::fixtureIds()[0],
        LiveGroupingModels::survivors()[0]['id'].'|'.LiveGroupingModels::fixtureIds()[0],
    ],
]);

it('rejects a resumed ledger whose bound screen contract changed', function (): void {
    $model = LiveGroupingModels::survivors()[0];
    $fixture = LiveGroupingModels::fixtureIds()[0];
    $runner = new InertLiveGroupingRunner;
    $screen = new LiveGroupingScreen(
        dirname(__DIR__, 2),
        $runner,
        liveGroupingApi($model, keyOverrides: ['limit_remaining' => '49.999']),
        [$model],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: [$fixture],
    );

    file_put_contents(liveGroupingAuthorizationPath(), json_encode([
        'schema_version' => 4,
        'confirmation' => LiveGroupingModels::CONFIRMATION,
        'key_fingerprint' => hash('sha256', 'synthetic-openrouter-canary'),
        'contract_fingerprint' => 'sha256:'.str_repeat('0', 64),
        'trial_ids' => [$model['id'].'|'.$fixture],
        'initial_remaining' => '50',
        'recorded_spend' => '0.001',
        'admission_spend' => '0.001',
        'completed_trials' => [],
        'completed_evidence' => [],
        'pending' => null,
    ], JSON_THROW_ON_ERROR));

    expect(fn () => $screen->execute([
        '--live',
        '--confirm='.LiveGroupingModels::CONFIRMATION,
    ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
        ->toThrow(RuntimeException::class, 'ledger is invalid')
        ->and($runner->calls)->toBe(0);
});

it('removes private replay when a completed child trial is rejected', function (): void {
    $root = dirname(__DIR__, 2);
    $model = LiveGroupingModels::survivors()[0];
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
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: [LiveGroupingModels::fixtureIds()[0]],
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
    $model = LiveGroupingModels::survivors()[0];
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
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: [LiveGroupingModels::fixtureIds()[0]],
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

it('rejects detached scorer measurements and mismatched private replay output', function (string $mutation): void {
    $root = dirname(__DIR__, 2);
    $model = LiveGroupingModels::survivors()[0];
    $before = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
    $runner = new class($root, $mutation) implements CommandRunner
    {
        public function __construct(
            private readonly string $root,
            private readonly string $mutation,
        ) {}

        public function run(array $command, ?array $environment = null): CommandResult
        {
            $before = glob($this->root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
            $result = (new NativeCommandRunner($this->root))->run($command, $environment);
            $after = glob($this->root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
            $created = array_values(array_diff($after, $before));

            if ($this->mutation === 'measurement') {
                $path = $created[0].'/scorecard.json';
                $scorecard = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($scorecard)
                    || ! is_array($scorecard['trials'] ?? null)
                    || ! is_array($scorecard['trials'][0] ?? null)
                    || ! is_array($scorecard['trials'][0]['results'] ?? null)
                    || ! is_array($scorecard['trials'][0]['results'][1] ?? null)
                    || ! is_array($scorecard['trials'][0]['results'][1]['measurements'] ?? null)
                    || ! is_array($scorecard['trials'][0]['results'][1]['measurements'][0] ?? null)) {
                    throw new RuntimeException('The scorecard cannot be mutated for detached-evidence proof.');
                }

                $scorecard['trials'][0]['results'][1]['measurements'][0]['mode'] = 'simulated';
                file_put_contents($path, json_encode($scorecard, JSON_THROW_ON_ERROR));
            } else {
                $path = $created[0].'/replay.private.json';
                $replay = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $output = is_array($replay) && is_array($replay['trials'] ?? null) && is_array($replay['trials'][0] ?? null)
                    ? ($replay['trials'][0]['output'] ?? null)
                    : null;
                $cost = is_array($output) ? ($output['cost'] ?? null) : null;
                $currencies = is_array($cost) ? ($cost['known_by_currency'] ?? null) : null;
                $usd = is_array($currencies) ? ($currencies['USD'] ?? null) : null;

                if (! is_array($replay)
                    || ! is_array($replay['trials'] ?? null)
                    || ! is_array($replay['trials'][0] ?? null)
                    || ! is_array($output)
                    || ! is_array($cost)
                    || ! is_array($currencies)
                    || ! is_array($usd)) {
                    throw new RuntimeException('The replay cannot be mutated for detached-evidence proof.');
                }

                if ($this->mutation === 'replay groups') {
                    $output['groups'] = [[1], [2], [3]];
                } else {
                    $usd['amount'] = '999';
                    $currencies['USD'] = $usd;
                    $cost['known_by_currency'] = $currencies;
                    $output['cost'] = $cost;
                }

                $replay['trials'][0]['output'] = $output;
                file_put_contents($path, json_encode($replay, JSON_THROW_ON_ERROR));
            }

            return $result;
        }
    };
    $screen = new LiveGroupingScreen(
        $root,
        $runner,
        liveGroupingApi($model),
        [$model],
        offlineInference: true,
        authorizationPath: liveGroupingAuthorizationPath(),
        fixtureIds: [LiveGroupingModels::fixtureIds()[0]],
    );

    try {
        expect(fn () => $screen->execute([
            '--live',
            '--confirm='.LiveGroupingModels::CONFIRMATION,
        ], ['OPENROUTER_API_KEY' => 'synthetic-openrouter-canary']))
            ->toThrow(RuntimeException::class);

        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];
        $created = array_values(array_diff($after, $before));

        expect($created)->toHaveCount(1)
            ->and(is_file($created[0].'/replay.private.json'))->toBeFalse();
    } finally {
        $after = glob($root.'/storage/app/ai-evals/runs/*', GLOB_ONLYDIR) ?: [];

        foreach (array_diff($after, $before) as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
})->with(['measurement', 'replay groups', 'replay cost']);

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
        'openrouter_route' => [
            'model' => 'qwen/qwen3.8-flash-20260826',
            'provider_name' => 'Alibaba',
            'data_region' => 'global',
            'service_tier' => null,
            'provider_attempts' => 1,
            'fallbacks_disabled' => true,
        ],
    ], JSON_THROW_ON_ERROR);

    expect((new LiveGroupingAttemptScorer)->score('', $output)->score)->toBe(0.0);
})->with([
    'letters' => ['not a decimal'],
    'non-finite string' => ['NaN'],
    'wrong type' => [[]],
]);

it('validates effective identity and route for a priced detector with no extractable groups', function (string $effective, int $providerAttempts, float $expected): void {
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
        'openrouter_route' => [
            'model' => 'qwen/qwen3.8-flash-20260826',
            'provider_name' => 'Alibaba',
            'data_region' => 'global',
            'service_tier' => null,
            'provider_attempts' => $providerAttempts,
            'fallbacks_disabled' => true,
        ],
    ], JSON_THROW_ON_ERROR);

    expect((new LiveGroupingAttemptScorer)->score('', $output)->score)->toBe($expected);
})->with([
    'requested alias' => ['qwen/qwen3.8-flash', 1, 1.0],
    'pinned canonical model' => ['qwen/qwen3.8-flash-20260826', 1, 1.0],
    'unexpected model' => ['other/model', 1, 0.0],
    'fallback attempt' => ['qwen/qwen3.8-flash', 2, 0.0],
]);
