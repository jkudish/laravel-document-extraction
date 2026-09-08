<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Dev;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Jkudish\DocumentExtraction\Dev\PrWorkflow\CommandRunner;
use Jkudish\DocumentExtraction\Tests\Support\GroupingMetric;
use Jkudish\DocumentExtraction\Tests\Support\LiveGroupingModels;
use Jkudish\PestAiBenchmarks\Runs\StableScorecardValidator;
use RuntimeException;
use Throwable;

/**
 * @phpstan-import-type LiveModel from LiveGroupingModels
 *
 * @phpstan-type LiveAuthorization array{schema_version: 2, confirmation: string, key_fingerprint: string, model_ids: list<string>, initial_remaining: string, recorded_spend: string, admission_spend: string, completed_models: list<string>, pending: array{model: string, reservation: string}|null}
 */
final class LiveGroupingScreen
{
    /** @var Closure(string, ?string): array<string, mixed> */
    private Closure $fetch;

    /** @var list<LiveModel> */
    private array $models;

    private readonly string $authorizationFile;

    /**
     * @param  Closure(string, ?string): array<string, mixed>|null  $fetch
     * @param  list<LiveModel>|null  $models
     */
    public function __construct(
        private readonly string $repositoryRoot,
        private readonly CommandRunner $runner,
        ?Closure $fetch = null,
        ?array $models = null,
        private readonly bool $offlineInference = false,
        ?string $authorizationPath = null,
    ) {
        $this->fetch = $fetch ?? $this->request(...);
        $this->models = $models ?? LiveGroupingModels::all();
        $this->authorizationFile = $authorizationPath
            ?? $this->repositoryRoot.'/storage/app/ai-evals/live-grouping-screen-v1.json';

        if ($this->models === [] || count(array_unique(array_column($this->models, 'id'))) !== count($this->models)) {
            throw new RuntimeException('The live grouping screen requires unique approved models.');
        }

        foreach ($this->models as $model) {
            if (LiveGroupingModels::find($model['id']) !== $model) {
                throw new RuntimeException('The live grouping screen contains an unapproved configuration.');
            }
        }
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     * @return array{live: bool, output: string, runs: list<array{model: string, endpoint: string, cost_usd: string, cost_source: string, status: string, attempts: int, scorecard: string}>}
     */
    public function execute(array $arguments, array $environment): array
    {
        if ($arguments === []) {
            return ['live' => false, 'output' => $this->plan(), 'runs' => []];
        }

        $expected = ['--live', '--confirm='.LiveGroupingModels::CONFIRMATION];
        $supplied = array_values(array_unique($arguments));
        sort($expected);
        sort($supplied);

        if ($supplied !== $expected || count($arguments) !== count($expected)) {
            throw new RuntimeException(
                'Live execution requires exactly --live --confirm='.LiveGroupingModels::CONFIRMATION.'.',
            );
        }

        $key = $environment['OPENROUTER_API_KEY'] ?? '';

        if (trim($key) === '') {
            throw new RuntimeException('OPENROUTER_API_KEY is required for live execution.');
        }

        $lock = $this->acquireLock();
        $currentRemaining = $this->validateKey($key, requireFullCap: ! is_file($this->authorizationPath()));
        $authorization = $this->authorization($key, $currentRemaining);
        $initialRemaining = BigDecimal::of($authorization['initial_remaining']);
        $summaries = [];
        $recordedSpend = BigDecimal::of($authorization['recorded_spend']);
        $admissionSpend = BigDecimal::of($authorization['admission_spend']);
        $completed = count($authorization['completed_models']);

        foreach (array_slice($this->models, $completed) as $model) {
            $reservation = $this->validateCatalog($model);
            $remaining = $this->validateKey($key, requireFullCap: false);
            $spent = $initialRemaining->minus($remaining);
            $budgetedSpend = $admissionSpend->isGreaterThan($spent) ? $admissionSpend : $spent;

            if ($spent->isNegative()
                || $remaining->isLessThan($reservation)
                || $budgetedSpend->plus($reservation)->isGreaterThan(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))) {
                throw new RuntimeException('The next live grouping trial cannot fit within the approved USD admission budget.');
            }

            $authorization['pending'] = [
                'model' => $model['id'],
                'reservation' => (string) $reservation,
            ];
            $this->writeAuthorization($authorization);

            $before = $this->runDirectories();
            $home = sys_get_temp_dir().'/lde-live-grouping-'.bin2hex(random_bytes(8));

            if (! mkdir($home, 0700, true) || ! mkdir($home.'/tmp', 0700, true)) {
                throw new RuntimeException('Unable to create the private live grouping environment.');
            }

            try {
                $childEnvironment = [
                    'HOME' => $home,
                    'TMPDIR' => $home.'/tmp',
                    'PATH' => '/usr/local/bin:/usr/bin:/bin',
                    'PAO_DISABLE' => '1',
                    'OPENROUTER_API_KEY' => $key,
                    'LDE_LIVE_GROUPING_CONFIRM' => LiveGroupingModels::CONFIRMATION,
                    'LDE_LIVE_GROUPING_MODEL' => $model['id'],
                ];

                if ($this->offlineInference) {
                    $childEnvironment['LDE_LIVE_GROUPING_OFFLINE'] = '1';
                }

                $result = $this->runner->run([
                    PHP_BINARY,
                    'vendor/bin/pest',
                    'tests/Evals/LiveGroupingBenchmarkTest.php',
                    '--no-tia',
                    '--evals',
                    '--colors=never',
                ], $childEnvironment);
            } finally {
                $this->removeDirectory($home);
            }

            $created = array_values(array_diff($this->runDirectories(), $before));

            if (count($created) !== 1) {
                $this->removePrivateReplays($created);

                throw new RuntimeException('The live grouping trial failed before producing one complete scorecard.');
            }

            $runDirectory = $this->ownedRunDirectory($created[0]);

            try {
                $summary = $this->validateRun($runDirectory, $model);

                if ($result->exitCode !== 0 && ! $summary['technical_failure']) {
                    throw new RuntimeException('The live grouping trial failed before producing one complete scorecard.');
                }
            } finally {
                $this->removePrivateReplay($runDirectory);
            }

            $afterRemaining = $this->validateKey($key, requireFullCap: false);
            $allowanceCost = $remaining->minus($afterRemaining);
            $providerCost = $summary['provider_cost_usd'] === null
                ? BigDecimal::zero()
                : BigDecimal::of($summary['provider_cost_usd']);
            $trialCost = $providerCost->isPositive() ? $providerCost : $allowanceCost;
            $observedSpend = $initialRemaining->minus($afterRemaining);

            if ((! $summary['technical_failure'] && $trialCost->isLessThanOrEqualTo(BigDecimal::zero()))
                || $allowanceCost->isNegative()
                || $providerCost->isGreaterThan($reservation)
                || $observedSpend->isGreaterThan(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))) {
                throw new RuntimeException('The live grouping trial exceeded its catalog-derived cost reservation.');
            }

            if ($providerCost->isPositive()) {
                $recordedSpend = $recordedSpend->plus($providerCost);
                $admissionSpend = $admissionSpend->plus($providerCost);
            } else {
                $admissionSpend = $admissionSpend->plus($reservation);
            }

            if ($observedSpend->isGreaterThan($recordedSpend)) {
                $recordedSpend = $observedSpend;
            }

            $authorization['completed_models'][] = $model['id'];
            $authorization['recorded_spend'] = (string) $recordedSpend;
            $authorization['admission_spend'] = (string) $admissionSpend;
            $authorization['pending'] = null;
            $this->writeAuthorization($authorization);

            if ($recordedSpend->isGreaterThan(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))
                || $admissionSpend->isGreaterThan(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))) {
                throw new RuntimeException('The live grouping screen exceeded its approved USD spend cap.');
            }

            $summaries[] = [
                'model' => $summary['model'],
                'endpoint' => $summary['endpoint'],
                'cost_usd' => (string) $trialCost,
                'cost_source' => match (true) {
                    $trialCost->isZero() => 'no_observed_charge',
                    $providerCost->isPositive() => 'provider_reported',
                    default => 'key_allowance_change',
                },
                'status' => $summary['technical_failure'] ? 'technical_failure' : 'measured',
                'attempts' => $summary['attempts'],
                'scorecard' => $runDirectory.'/scorecard.json',
            ];
        }

        $finalRemaining = $this->validateKey($key, requireFullCap: false);
        $spent = $initialRemaining->minus($finalRemaining);

        if ($spent->isNegative()
            || $spent->isGreaterThan(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))
            || $recordedSpend->isGreaterThan(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))
            || $admissionSpend->isGreaterThan(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))) {
            throw new RuntimeException('The live grouping screen exceeded its approved USD spend cap.');
        }

        return [
            'live' => true,
            'output' => sprintf(
                'Validated %d paid detector calls; reconciled spend was $%s (key allowance change $%s).',
                count($authorization['completed_models']),
                (string) $recordedSpend,
                (string) $spent,
            ),
            'runs' => $summaries,
        ];
    }

    public function plan(): string
    {
        $lines = [
            'DRY RUN — no provider calls made',
            sprintf('Fixture: %s (%s)', LiveGroupingModels::FIXTURE_ID, LiveGroupingModels::FIXTURE_FILE),
            sprintf('Calls: %d paid detector calls; grouped extraction/OCR simulated', count($this->models)),
            sprintf('Logical spend cap: $%.2f USD', LiveGroupingModels::MAX_SPEND_USD),
            'Routes:',
        ];

        foreach ($this->models as $model) {
            $lines[] = sprintf(
                '- %s @ %s (fallbacks off, parameters required, data collection denied, ZDR %s)',
                $model['id'],
                $model['endpoint'],
                $model['zdr'] ? 'required' : 'not advertised',
            );
        }

        $lines[] = 'To execute: scripts/live-grouping-screen --live --confirm='.LiveGroupingModels::CONFIRMATION;

        return implode(PHP_EOL, $lines);
    }

    private function validateKey(string $key, bool $requireFullCap = true): BigDecimal
    {
        $response = ($this->fetch)('https://openrouter.ai/api/v1/key', $key);
        $data = $this->object($response['data'] ?? null, 'OpenRouter key data');
        $limit = $this->decimal($data['limit'] ?? null, 'OpenRouter key limit');
        $remaining = $this->decimal($data['limit_remaining'] ?? null, 'OpenRouter key remaining allowance');

        if ($limit->isLessThanOrEqualTo(BigDecimal::zero())
            || $limit->isGreaterThan(BigDecimal::of((string) LiveGroupingModels::MAX_KEY_LIMIT_USD))
            || $remaining->isLessThanOrEqualTo(BigDecimal::zero())
            || ($requireFullCap && $remaining->isLessThan(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD)))
            || $remaining->isGreaterThan($limit)
            || ($data['limit_reset'] ?? null) !== 'monthly'
            || ($data['is_free_tier'] ?? null) !== false
            || ($data['is_management_key'] ?? false) !== false
            || ($data['is_provisioning_key'] ?? false) !== false) {
            throw new RuntimeException('The OpenRouter key does not have the required finite monthly allowance.');
        }

        return $remaining;
    }

    /** @param LiveModel $model */
    private function validateCatalog(array $model): BigDecimal
    {
        $catalogResponse = ($this->fetch)('https://openrouter.ai/api/v1/models', null);
        $catalog = $this->list($catalogResponse['data'] ?? null, 'OpenRouter model catalog');
        $zdrResponse = ($this->fetch)('https://openrouter.ai/api/v1/endpoints/zdr', null);
        $zdrEndpoints = $this->list($zdrResponse['data'] ?? null, 'OpenRouter ZDR endpoints');
        $catalogModel = $this->findObject($catalog, 'id', $model['id'], 'OpenRouter model');
        $architecture = $this->object($catalogModel['architecture'] ?? null, 'OpenRouter model architecture');
        $modelParameters = $this->strings($catalogModel['supported_parameters'] ?? null, 'model parameters');

        if (($catalogModel['canonical_slug'] ?? null) !== $model['canonical']
            || ! in_array('image', $this->strings($architecture['input_modalities'] ?? null, 'input modalities'), true)
            || ! in_array('text', $this->strings($architecture['output_modalities'] ?? null, 'output modalities'), true)
            || ! in_array($model['output_parameter'], $modelParameters, true)
            || ! $this->supportsStructuredOutput($modelParameters)) {
            throw new RuntimeException("OpenRouter model [{$model['id']}] no longer supports the pinned request.");
        }

        $endpointResponse = ($this->fetch)(
            'https://openrouter.ai/api/v1/models/'.$model['id'].'/endpoints',
            null,
        );
        $endpointData = $this->object($endpointResponse['data'] ?? null, 'OpenRouter endpoint data');
        $endpoints = $this->list($endpointData['endpoints'] ?? null, 'OpenRouter endpoints');
        $endpoint = $this->findObject($endpoints, 'tag', $model['endpoint'], 'OpenRouter endpoint');
        $parameters = $this->strings($endpoint['supported_parameters'] ?? null, 'endpoint parameters');

        if (($endpoint['model_id'] ?? null) !== $model['id']
            || ($endpoint['status'] ?? null) !== 0
            || ! in_array($model['output_parameter'], $parameters, true)
            || ! $this->supportsStructuredOutput($parameters)) {
            throw new RuntimeException("OpenRouter endpoint [{$model['id']} @ {$model['endpoint']}] is unavailable or incompatible.");
        }

        $this->validatePrices($endpoint, $model);

        if ($model['zdr'] && ! $this->containsEndpoint($zdrEndpoints, $model['id'], $model['endpoint'])) {
            throw new RuntimeException("OpenRouter endpoint [{$model['id']} @ {$model['endpoint']}] is no longer ZDR.");
        }

        return $this->maximumCallCost($endpoint);
    }

    /** @param array<string, mixed> $endpoint
     * @param  LiveModel  $model
     */
    private function validatePrices(array $endpoint, array $model): void
    {
        $pricing = $this->object($endpoint['pricing'] ?? null, 'OpenRouter endpoint pricing');

        foreach (['prompt', 'completion'] as $unit) {
            $actual = $this->maximumUnitPrice($pricing, [$unit], "endpoint {$unit} price")->multipliedBy('1000000');

            if ($actual->isNegative() || $actual->isGreaterThan(BigDecimal::of((string) $model['max_price'][$unit]))) {
                throw new RuntimeException("OpenRouter endpoint [{$model['id']} @ {$model['endpoint']}] exceeds its {$unit} price ceiling.");
            }
        }

        if (isset($model['max_price']['image'])) {
            $actual = $this->maximumUnitPrice($pricing, ['image'], 'endpoint image price');

            if ($actual->isNegative() || $actual->isGreaterThan(BigDecimal::of((string) $model['max_price']['image']))) {
                throw new RuntimeException("OpenRouter endpoint [{$model['id']} @ {$model['endpoint']}] exceeds its image price ceiling.");
            }
        } elseif (! $this->maximumUnitPrice($pricing, ['image'], 'endpoint image price', required: false)->isZero()) {
            throw new RuntimeException("OpenRouter endpoint [{$model['id']} @ {$model['endpoint']}] added an image price.");
        }

        if (! $this->maximumUnitPrice($pricing, ['request'], 'endpoint request price', required: false)->isZero()) {
            throw new RuntimeException("OpenRouter endpoint [{$model['id']} @ {$model['endpoint']}] added a request price.");
        }
    }

    /** @param array<string, mixed> $endpoint */
    private function maximumCallCost(array $endpoint): BigDecimal
    {
        $contextLength = $endpoint['context_length'] ?? null;

        if (! is_int($contextLength) || $contextLength <= 0) {
            throw new RuntimeException('The OpenRouter endpoint lacks a finite positive context length.');
        }

        $pricing = $this->object($endpoint['pricing'] ?? null, 'OpenRouter endpoint pricing');
        $inputRate = $this->maximumUnitPrice(
            $pricing,
            ['prompt', 'image_token', 'input_cache_read', 'input_cache_write', 'input_cache_write_1h'],
            'endpoint input price',
        );
        $completionRate = $this->maximumUnitPrice($pricing, ['completion'], 'endpoint completion price');
        $reasoningRate = $this->maximumUnitPrice(
            $pricing,
            ['internal_reasoning'],
            'endpoint reasoning price',
            required: false,
        );
        $imageRate = $this->maximumUnitPrice($pricing, ['image'], 'endpoint image price', required: false);

        return $inputRate->multipliedBy($contextLength)
            ->plus($completionRate->plus($reasoningRate)->multipliedBy(LiveGroupingModels::MAX_OUTPUT_TOKENS))
            ->plus($imageRate->multipliedBy(6));
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @param  non-empty-list<string>  $units
     */
    private function maximumUnitPrice(array $pricing, array $units, string $label, bool $required = true): BigDecimal
    {
        $prices = [];

        foreach ([$pricing, ...$this->pricingOverrides($pricing)] as $candidate) {
            foreach ($units as $unit) {
                if (array_key_exists($unit, $candidate)) {
                    $price = $this->decimal($candidate[$unit], $label);

                    if ($price->isNegative()) {
                        throw new RuntimeException("The {$label} must not be negative.");
                    }

                    $prices[] = $price;
                }
            }
        }

        if ($prices === []) {
            if ($required) {
                throw new RuntimeException("The {$label} is missing.");
            }

            return BigDecimal::zero();
        }

        return array_reduce(
            $prices,
            static fn (BigDecimal $maximum, BigDecimal $price): BigDecimal => $price->isGreaterThan($maximum) ? $price : $maximum,
            BigDecimal::zero(),
        );
    }

    /** @param array<string, mixed> $pricing
     * @return list<array<string, mixed>>
     */
    private function pricingOverrides(array $pricing): array
    {
        if (! array_key_exists('overrides', $pricing)) {
            return [];
        }

        return array_map(
            fn (mixed $override): array => $this->object($override, 'endpoint pricing override'),
            $this->list($pricing['overrides'], 'endpoint pricing overrides'),
        );
    }

    /** @param LiveModel $model
     * @return array{model: string, endpoint: string, provider_cost_usd: ?string, technical_failure: bool, attempts: int}
     */
    private function validateRun(string $runDirectory, array $model): array
    {
        $scorecard = $this->jsonObject($runDirectory.'/scorecard.json');
        StableScorecardValidator::assert($scorecard);
        $trials = $this->list($scorecard['trials'] ?? null, 'scorecard trials');
        $context = $this->object($scorecard['context'] ?? null, 'scorecard context');

        if (($scorecard['benchmark'] ?? null) !== LiveGroupingModels::BENCHMARK
            || ($context['screen'] ?? null) !== 'openrouter-live-grouping-canary-v1'
            || ($context['fixture'] ?? null) !== LiveGroupingModels::FIXTURE_ID
            || count($trials) !== 1) {
            throw new RuntimeException('The live grouping scorecard has an unexpected identity.');
        }

        $trial = $this->object($trials[0], 'scorecard trial');
        $results = $this->list($trial['results'] ?? null, 'trial results');
        $primary = $this->object($results[0] ?? null, 'primary result');

        if (($trial['configuration'] ?? null) !== $model['id'].' @ '.$model['endpoint']) {
            throw new RuntimeException('The live grouping scorecard does not match the approved configuration.');
        }

        if (($primary['scorer'] ?? null) === 'pest:test'
            && ($primary['passed'] ?? null) === false
            && ($primary['score'] ?? null) === 0
            && count($results) === 1) {
            $measurements = $this->list($primary['measurements'] ?? null, 'target measurements');

            if (count($measurements) !== 1) {
                throw new RuntimeException('The failed live grouping trial has unexpected attempt evidence.');
            }

            $this->validateMeasurement($measurements[0], $model, 'live', allowUnavailable: true);

            return [
                'model' => $model['id'],
                'endpoint' => $model['endpoint'],
                'provider_cost_usd' => null,
                'technical_failure' => true,
                'attempts' => 1,
            ];
        }

        $expectedScorers = [
            'pest:test',
            ...array_map(static fn (GroupingMetric $metric): string => $metric->value, GroupingMetric::cases()),
            'live-detector-attempt-integrity',
        ];
        $scorers = array_map(
            fn (mixed $result): mixed => $this->object($result, 'trial result')['scorer'] ?? null,
            $results,
        );

        if ($scorers !== $expectedScorers) {
            throw new RuntimeException('The live grouping scorecard does not match the approved configuration.');
        }

        $integrity = $this->object($results[count($results) - 1], 'attempt-integrity result');
        $integrityScore = $integrity['score'] ?? null;
        $measurements = $this->list($primary['measurements'] ?? null, 'target measurements');

        if (($primary['scorer'] ?? null) !== 'pest:test'
            || ($primary['passed'] ?? null) !== true
            || ($integrity['scorer'] ?? null) !== 'live-detector-attempt-integrity'
            || ($integrity['passed'] ?? null) !== true
            || (! is_int($integrityScore) && ! is_float($integrityScore))
            || (float) $integrityScore !== 1.0
            || $measurements === []) {
            throw new RuntimeException('The live grouping trial or its attempt-integrity scorer failed.');
        }

        $providerCost = null;

        foreach ($measurements as $index => $rawMeasurement) {
            $measurement = $this->validateMeasurement(
                $rawMeasurement,
                $model,
                $index === 0 ? 'live' : 'simulated',
                allowUnavailable: $index === 0,
            );

            if ($index === 0) {
                $providerCost = $this->liveCost($measurement);
            } elseif (($measurement['mode'] ?? null) !== 'simulated'
                || ($this->object($measurement['pricing'] ?? null, 'simulated pricing')['completeness'] ?? null) !== 'unavailable') {
                throw new RuntimeException('A grouped extraction measurement was not simulated and unpriced.');
            }
        }

        if (! is_file($runDirectory.'/replay.private.json')) {
            throw new RuntimeException('The private live replay is missing.');
        }

        return [
            'model' => $model['id'],
            'endpoint' => $model['endpoint'],
            'provider_cost_usd' => $providerCost,
            'technical_failure' => false,
            'attempts' => count($measurements),
        ];
    }

    /** @param LiveModel $model
     * @return array<string, mixed>
     */
    private function validateMeasurement(
        mixed $rawMeasurement,
        array $model,
        string $expectedMode,
        bool $allowUnavailable,
    ): array {
        $measurement = $this->object($rawMeasurement, 'target measurement');
        $requested = $this->object($measurement['requested_model'] ?? null, 'requested model');
        $effective = $measurement['effective_model'] ?? null;

        if (($measurement['component'] ?? null) !== 'target'
            || ($measurement['mode'] ?? null) !== $expectedMode
            || $requested !== ['provider' => 'openrouter', 'model' => $model['id']]
            || (! $allowUnavailable && $effective === null)
            || ($effective !== null && (
                ! is_array($effective)
                || array_is_list($effective)
                || ($effective['provider'] ?? null) !== 'openrouter'
                || ! in_array($effective['model'] ?? null, [$model['id'], $model['canonical']], true)
            ))) {
            throw new RuntimeException('A target measurement has an unexpected identity.');
        }

        return $measurement;
    }

    /** @param array<string, mixed> $measurement */
    private function liveCost(array $measurement): ?string
    {
        $pricing = $this->object($measurement['pricing'] ?? null, 'live pricing');
        $snapshot = $this->object($pricing['snapshot'] ?? null, 'live pricing snapshot');
        $cost = $snapshot['cost'] ?? null;

        if (($measurement['mode'] ?? null) === 'live'
            && ($pricing['completeness'] ?? null) === 'unavailable'
            && ($snapshot['source'] ?? null) === 'unavailable'
            && $cost === null) {
            return null;
        }

        $money = $this->object($cost, 'live cost');
        $amount = $money['amount'] ?? null;

        if (($measurement['mode'] ?? null) !== 'live'
            || ($pricing['completeness'] ?? null) !== 'complete'
            || ($snapshot['source'] ?? null) !== 'provider_reported'
            || ! is_string($amount)
            || ($money['currency'] ?? null) !== 'USD'
            || BigDecimal::of($amount)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new RuntimeException('The detector lacks positive provider-reported USD cost evidence.');
        }

        return $amount;
    }

    /** @param list<mixed> $endpoints */
    private function containsEndpoint(array $endpoints, string $model, string $tag): bool
    {
        foreach ($endpoints as $endpoint) {
            if (is_array($endpoint)
                && ($endpoint['model_id'] ?? null) === $model
                && ($endpoint['tag'] ?? null) === $tag) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $parameters */
    private function supportsStructuredOutput(array $parameters): bool
    {
        return in_array('response_format', $parameters, true)
            && in_array('structured_outputs', $parameters, true);
    }

    /**
     * @param  list<mixed>  $items
     * @return array<string, mixed>
     */
    private function findObject(array $items, string $field, string $value, string $label): array
    {
        foreach ($items as $item) {
            if (is_array($item) && ($item[$field] ?? null) === $value) {
                return $this->object($item, $label);
            }
        }

        throw new RuntimeException("The {$label} [{$value}] was not found.");
    }

    /** @return list<string> */
    private function strings(mixed $value, string $label): array
    {
        $items = $this->list($value, $label);

        foreach ($items as $item) {
            if (! is_string($item)) {
                throw new RuntimeException("The {$label} must contain strings.");
            }
        }

        /** @var list<string> $items */
        return $items;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $label): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException("The {$label} must be an object.");
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException("The {$label} must use string keys.");
            }

            $object[$key] = $item;
        }

        return $object;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("The {$label} must be a list.");
        }

        return $value;
    }

    private function decimal(mixed $value, string $label): BigDecimal
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new RuntimeException("The {$label} must be a finite decimal.");
        }

        try {
            return BigDecimal::of(is_float($value) ? (string) $value : $value);
        } catch (Throwable $exception) {
            throw new RuntimeException("The {$label} must be a finite decimal.", previous: $exception);
        }
    }

    /** @return LiveAuthorization */
    private function authorization(string $key, BigDecimal $currentRemaining): array
    {
        $path = $this->authorizationPath();

        if (is_link($path)) {
            throw new RuntimeException('The live grouping authorization ledger must not be a symbolic link.');
        }

        if (! is_file($path)) {
            $authorization = [
                'schema_version' => 2,
                'confirmation' => LiveGroupingModels::CONFIRMATION,
                'key_fingerprint' => hash('sha256', $key),
                'model_ids' => array_column($this->models, 'id'),
                'initial_remaining' => (string) $currentRemaining,
                'recorded_spend' => '0',
                'admission_spend' => '0',
                'completed_models' => [],
                'pending' => null,
            ];
            $this->writeAuthorization($authorization);

            return $authorization;
        }

        $authorization = $this->jsonObject($path);
        $modelIds = $this->strings($authorization['model_ids'] ?? null, 'authorization models');
        $completed = $this->strings($authorization['completed_models'] ?? null, 'completed authorization models');
        $initial = $this->decimal($authorization['initial_remaining'] ?? null, 'authorization initial allowance');
        $recorded = $this->decimal($authorization['recorded_spend'] ?? null, 'authorization recorded spend');

        if (($authorization['schema_version'] ?? null) === 1) {
            if (($authorization['confirmation'] ?? null) === LiveGroupingModels::CONFIRMATION
                && is_string($authorization['key_fingerprint'] ?? null)
                && hash_equals($authorization['key_fingerprint'], hash('sha256', $key))
                && $modelIds === array_column($this->models, 'id')
                && count($completed) === count($this->models)
                && ($authorization['pending'] ?? null) === null
                && $initial->isGreaterThanOrEqualTo(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))
                && $recorded->isGreaterThanOrEqualTo(BigDecimal::zero())
                && $recorded->isLessThanOrEqualTo(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))) {
                throw new RuntimeException('The live grouping authorization has already been consumed.');
            }

            throw new RuntimeException('The live grouping authorization ledger is invalid or belongs to another key.');
        }

        $expected = array_column(array_slice($this->models, 0, count($completed)), 'id');
        $admission = $this->decimal($authorization['admission_spend'] ?? null, 'authorization admission spend');

        if (($authorization['schema_version'] ?? null) !== 2
            || ($authorization['confirmation'] ?? null) !== LiveGroupingModels::CONFIRMATION
            || ! is_string($authorization['key_fingerprint'] ?? null)
            || ! hash_equals($authorization['key_fingerprint'], hash('sha256', $key))
            || $modelIds !== array_column($this->models, 'id')
            || $initial->isLessThan(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))
            || $recorded->isNegative()
            || $recorded->isGreaterThan(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))
            || $admission->isNegative()
            || $admission->isGreaterThan(BigDecimal::of((string) LiveGroupingModels::MAX_SPEND_USD))
            || $completed !== $expected) {
            throw new RuntimeException('The live grouping authorization ledger is invalid or belongs to another key.');
        }

        if (($authorization['pending'] ?? null) !== null) {
            throw new RuntimeException('The live grouping authorization has an unresolved paid call and cannot be resumed.');
        }

        if (count($completed) === count($this->models)) {
            throw new RuntimeException('The live grouping authorization has already been consumed.');
        }

        /** @var LiveAuthorization $authorization */
        return $authorization;
    }

    /** @param LiveAuthorization $authorization */
    private function writeAuthorization(array $authorization): void
    {
        $path = $this->authorizationPath();
        $directory = dirname($path);

        if ((! is_dir($directory) && ! mkdir($directory, 0700, true))
            || is_link($directory)
            || is_link($path)) {
            throw new RuntimeException('Unable to create the private live grouping authorization ledger.');
        }

        $temporary = $path.'.'.bin2hex(random_bytes(8));

        try {
            $contents = json_encode($authorization, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

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

    private function authorizationPath(): string
    {
        return $this->authorizationFile;
    }

    /** @return resource */
    private function acquireLock()
    {
        $path = sys_get_temp_dir().'/lde-live-grouping-'.hash('sha256', $this->repositoryRoot).'.lock';
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to create the live grouping execution lock.');
        }

        chmod($path, 0600);

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new RuntimeException('Another live grouping screen is already running in this checkout.');
        }

        return $handle;
    }

    /** @return list<string> */
    private function runDirectories(): array
    {
        $paths = glob($this->runsDirectory().'/*', GLOB_ONLYDIR) ?: [];
        $directories = array_map('basename', $paths);
        sort($directories);

        return $directories;
    }

    private function runsDirectory(): string
    {
        return $this->repositoryRoot.'/storage/app/ai-evals/runs';
    }

    private function ownedRunDirectory(string $name): string
    {
        $root = realpath($this->runsDirectory());
        $candidate = $this->runsDirectory().'/'.$name;
        $directory = realpath($candidate);

        if ($name !== basename($name)
            || $root === false
            || $directory === false
            || is_link($candidate)
            || dirname($directory) !== $root) {
            throw new RuntimeException('The live grouping run directory is not an owned direct child.');
        }

        return $directory;
    }

    /** @param list<string> $names */
    private function removePrivateReplays(array $names): void
    {
        foreach ($names as $name) {
            $this->removePrivateReplay($this->ownedRunDirectory($name));
        }
    }

    private function removePrivateReplay(string $runDirectory): void
    {
        $replay = $runDirectory.'/replay.private.json';

        if (is_link($replay)
            || (is_file($replay) && ! unlink($replay))) {
            throw new RuntimeException('The private replay could not be removed safely.');
        }
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("The evidence file [{$path}] is unreadable.");
        }

        return $this->object(json_decode($contents, true, flags: JSON_THROW_ON_ERROR), 'evidence file');
    }

    /** @return array<string, mixed> */
    private function request(string $url, ?string $key): array
    {
        $request = (new Factory)
            ->acceptJson()
            ->withUserAgent('laravel-document-extraction-live-screen/1.0')
            ->connectTimeout(5)
            ->timeout(20);

        if ($key !== null) {
            $request->withToken($key);
        }

        try {
            $decoded = $request->get($url)->throw()->json();
        } catch (Throwable $exception) {
            throw new RuntimeException('OpenRouter preflight request failed.', previous: $exception);
        }

        return $this->object($decoded, 'OpenRouter response');
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        (new Filesystem)->deleteDirectory($directory);
    }
}
