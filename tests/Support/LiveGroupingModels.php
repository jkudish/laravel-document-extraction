<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use Jkudish\PestAiBenchmarks\Configuration;

/**
 * @phpstan-type LiveModel array{id: string, canonical: string, endpoint: string, route: array{provider_name: string, data_region: string, service_tier: ?string}, zdr: bool, reasoning: bool, output_parameter: string, max_price: array{prompt: float, completion: float, image?: float}}
 */
final class LiveGroupingModels
{
    public const string DEVELOPMENT_STAGE = 'development-repeats';

    public const string HOLDOUT_STAGE = 'frozen-holdout';

    public const string BENCHMARK = 'live OpenRouter grouping finalist development repeats';

    public const string CONFIRMATION = 'run-30-finalist-development-repeat-detector-calls';

    public const string FIXTURE_ID = 'mixed-document-lengths';

    public const string FIXTURE_FILE = 'bundle-03.pdf';

    public const string SCREEN = 'openrouter-live-grouping-development-repeats-v1';

    public const string HOLDOUT_BENCHMARK = 'live OpenRouter grouping frozen holdout';

    public const string HOLDOUT_CONFIRMATION = 'run-27-frozen-holdout-detector-calls';

    public const string HOLDOUT_SCREEN = 'openrouter-live-grouping-frozen-holdout-v1';

    /** @var list<string> */
    public const array FIXTURE_IDS = [
        'single-three-page-document',
        'three-single-page-documents',
        'blank-separator',
        'mixed-document-lengths',
        'non-financial-documents',
    ];

    /** @var list<string> */
    public const array HOLDOUT_FIXTURE_IDS = [
        'same-issuer-invoices',
        'ambiguous-orphan',
        'scan-like-raster',
    ];

    /** @var list<string> */
    public const array SURVIVOR_IDS = [
        'google/gemini-2.5-flash',
        'openai/gpt-5.6-luna',
        'anthropic/claude-haiku-4.5',
    ];

    public const float MAX_SPEND_USD = 10.0;

    public const int DEVELOPMENT_REPETITIONS = 2;

    public const int HOLDOUT_REPETITIONS = 3;

    public const float MAX_KEY_LIMIT_USD = 50.0;

    public const int MAX_OUTPUT_TOKENS = 512;

    /** @return list<LiveModel> */
    public static function all(): array
    {
        return [
            self::model('qwen/qwen3.8-flash', 'alibaba', false, true, 0.15, 0.47, canonical: 'qwen/qwen3.8-flash-20260826'),
            self::model('qwen/qwen3.7-plus', 'alibaba', false, true, 0.96, 3.84, canonical: 'qwen/qwen3.7-plus-20260602'),
            self::model('qwen/qwen3.6-flash', 'alibaba', false, true, 0.75, 3.0),
            self::model('qwen/qwen3.5-flash-02-23', 'alibaba', false, true, 0.065, 0.26, canonical: 'qwen/qwen3.5-flash-20260224'),
            self::model('qwen/qwen3-vl-32b-instruct', 'alibaba', false, false, 0.104, 0.416),
            self::model('qwen/qwen2.5-vl-72b-instruct', 'parasail/fp8', true, false, 0.8, 1.0),
            self::model('google/gemini-3.8-flash', 'google-vertex/global', true, true, 0.75, 3.75, 0.000_000_75, canonical: 'google/gemini-3.8-flash-20260902'),
            self::model('google/gemini-3.1-flash-lite', 'google-vertex/global', true, true, 0.25, 1.5, 0.000_000_25, canonical: 'google/gemini-3.1-flash-lite-20260507'),
            self::model('google/gemini-2.5-flash', 'google-vertex/global', true, true, 0.3, 2.5, 0.000_000_3),
            self::model('google/gemini-2.5-pro', 'google-vertex/us', true, true, 2.5, 15.0, 0.000_001_25),
            self::model('openai/gpt-5.6-luna', 'azure/eu', true, true, 0.44, 1.98, completionTokenParameter: true, canonical: 'openai/gpt-5.6-luna-20260709', observedDataRegion: 'global'),
            self::model('anthropic/claude-sonnet-5', 'amazon-bedrock/global', true, true, 2.0, 10.0, canonical: 'anthropic/claude-sonnet-5-20260630'),
            self::model('anthropic/claude-haiku-4.5', 'amazon-bedrock/global', true, true, 1.0, 5.0, canonical: 'anthropic/claude-4.5-haiku-20251001'),
            self::model('mistralai/mistral-small-2603', 'mistral/zdr', true, true, 0.15, 0.6),
            self::model('meta-llama/llama-4-maverick', 'digitalocean', true, false, 0.2, 0.696, canonical: 'meta-llama/llama-4-maverick-17b-128e-instruct'),
        ];
    }

    /** @return list<LiveModel> */
    public static function survivors(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $model): bool => in_array($model['id'], self::SURVIVOR_IDS, true),
        ));
    }

    /** @return list<string> */
    public static function fixtureIds(): array
    {
        return self::FIXTURE_IDS;
    }

    /**
     * @return array{
     *     id: string,
     *     benchmark: string,
     *     confirmation: string,
     *     screen: string,
     *     fixture_ids: list<string>,
     *     fixture_split: string,
     *     repetitions: int,
     *     max_spend_usd: float,
     *     authorization_file: string,
     *     prerequisite_authorization_file: ?string
     * }
     */
    public static function stage(string $stage): array
    {
        return match ($stage) {
            self::DEVELOPMENT_STAGE => [
                'id' => self::DEVELOPMENT_STAGE,
                'benchmark' => self::BENCHMARK,
                'confirmation' => self::CONFIRMATION,
                'screen' => self::SCREEN,
                'fixture_ids' => self::FIXTURE_IDS,
                'fixture_split' => 'prompt-example',
                'repetitions' => self::DEVELOPMENT_REPETITIONS,
                'max_spend_usd' => self::MAX_SPEND_USD,
                'authorization_file' => 'storage/app/ai-evals/live-grouping-screen-v5.json',
                'prerequisite_authorization_file' => null,
            ],
            self::HOLDOUT_STAGE => [
                'id' => self::HOLDOUT_STAGE,
                'benchmark' => self::HOLDOUT_BENCHMARK,
                'confirmation' => self::HOLDOUT_CONFIRMATION,
                'screen' => self::HOLDOUT_SCREEN,
                'fixture_ids' => self::HOLDOUT_FIXTURE_IDS,
                'fixture_split' => 'holdout',
                'repetitions' => self::HOLDOUT_REPETITIONS,
                'max_spend_usd' => self::MAX_SPEND_USD,
                'authorization_file' => 'storage/app/ai-evals/live-grouping-screen-v6.json',
                'prerequisite_authorization_file' => 'storage/app/ai-evals/live-grouping-screen-v5.json',
            ],
            default => throw new \InvalidArgumentException("Unknown live grouping stage [{$stage}]."),
        };
    }

    /** @return array<string, Configuration> */
    public static function configurations(?string $onlyModel = null): array
    {
        $configurations = [];

        foreach (self::survivors() as $model) {
            if ($onlyModel !== null && $model['id'] !== $onlyModel) {
                continue;
            }

            $options = self::options($model);
            $configurations[$model['id'].' @ '.$model['endpoint']] = Configuration::model(
                provider: 'openrouter',
                model: $model['id'],
                options: $options,
            )->withSettings([
                'extraction.timeout' => 120,
                'extraction.detection' => [
                    'provider' => 'openrouter',
                    'model' => $model['id'],
                    'timeout' => 120,
                    'options' => $options,
                ],
            ]);
        }

        if ($onlyModel !== null && $configurations === []) {
            throw new \InvalidArgumentException('The requested live grouping model is not approved.');
        }

        return $configurations;
    }

    /** @return LiveModel */
    public static function find(string $id): array
    {
        foreach (self::all() as $model) {
            if ($model['id'] === $id) {
                return $model;
            }
        }

        throw new \InvalidArgumentException('The requested live grouping model is not approved.');
    }

    /** @param LiveModel $model
     * @return array{openrouter: array<string, mixed>}
     */
    public static function options(array $model): array
    {
        $options = [
            $model['output_parameter'] => self::MAX_OUTPUT_TOKENS,
            'provider' => [
                'only' => [$model['endpoint']],
                'allow_fallbacks' => false,
                'require_parameters' => true,
                'data_collection' => 'deny',
                'zdr' => $model['zdr'],
                'max_price' => $model['max_price'],
            ],
        ];

        if ($model['reasoning']) {
            $options['reasoning'] = ['effort' => 'none', 'exclude' => true];
        }

        return ['openrouter' => $options];
    }

    /**
     * @return LiveModel
     */
    private static function model(
        string $id,
        string $endpoint,
        bool $zdr,
        bool $reasoning,
        float $prompt,
        float $completion,
        ?float $image = null,
        bool $completionTokenParameter = false,
        ?string $canonical = null,
        ?string $observedDataRegion = null,
    ): array {
        $maxPrice = ['prompt' => $prompt, 'completion' => $completion];

        if ($image !== null) {
            $maxPrice['image'] = $image;
        }

        return [
            'id' => $id,
            'canonical' => $canonical ?? $id,
            'endpoint' => $endpoint,
            'route' => [
                'provider_name' => match (strtok($endpoint, '/')) {
                    'alibaba' => 'Alibaba',
                    'parasail' => 'Parasail',
                    'google-vertex' => 'Google',
                    'azure' => 'Azure',
                    'amazon-bedrock' => 'Amazon Bedrock',
                    'mistral' => 'Mistral',
                    'digitalocean' => 'DigitalOcean',
                    default => throw new \LogicException("The OpenRouter endpoint [{$endpoint}] has no route identity."),
                },
                'data_region' => $observedDataRegion ?? match (true) {
                    str_contains($endpoint, '/us') => 'us',
                    str_contains($endpoint, '/eu') => 'europe',
                    default => 'global',
                },
                'service_tier' => str_contains($endpoint, 'priority') ? 'priority' : null,
            ],
            'zdr' => $zdr,
            'reasoning' => $reasoning,
            'output_parameter' => $completionTokenParameter ? 'max_completion_tokens' : 'max_tokens',
            'max_price' => $maxPrice,
        ];
    }
}
