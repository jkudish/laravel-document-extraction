<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use Jkudish\PestAiBenchmarks\Configuration;

/**
 * @phpstan-type LiveModel array{id: string, canonical: string, endpoint: string, zdr: bool, reasoning: bool, output_parameter: string, max_price: array{prompt: float, completion: float, image?: float}}
 */
final class LiveGroupingModels
{
    public const string BENCHMARK = 'live OpenRouter grouping compatibility canary';

    public const string CONFIRMATION = 'run-15-paid-detector-calls';

    public const string FIXTURE_ID = 'mixed-document-lengths';

    public const string FIXTURE_FILE = 'bundle-03.pdf';

    public const float MAX_SPEND_USD = 5.0;

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
            self::model('google/gemini-2.5-pro', 'google-vertex/global', true, true, 2.5, 15.0, 0.000_001_25),
            self::model('openai/gpt-5.6-luna', 'azure/us', true, true, 0.44, 1.98, completionTokenParameter: true, canonical: 'openai/gpt-5.6-luna-20260709'),
            self::model('anthropic/claude-sonnet-5', 'amazon-bedrock/global', true, true, 2.0, 10.0, canonical: 'anthropic/claude-sonnet-5-20260630'),
            self::model('anthropic/claude-haiku-4.5', 'amazon-bedrock/global', true, true, 1.0, 5.0, canonical: 'anthropic/claude-4.5-haiku-20251001'),
            self::model('mistralai/mistral-small-2603', 'mistral/zdr', true, true, 0.15, 0.6),
            self::model('meta-llama/llama-4-maverick', 'digitalocean', true, false, 0.2, 0.696, canonical: 'meta-llama/llama-4-maverick-17b-128e-instruct'),
        ];
    }

    /** @return array<string, Configuration> */
    public static function configurations(?string $onlyModel = null): array
    {
        $configurations = [];

        foreach (self::all() as $model) {
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
    ): array {
        $maxPrice = ['prompt' => $prompt, 'completion' => $completion];

        if ($image !== null) {
            $maxPrice['image'] = $image;
        }

        return [
            'id' => $id,
            'canonical' => $canonical ?? $id,
            'endpoint' => $endpoint,
            'zdr' => $zdr,
            'reasoning' => $reasoning,
            'output_parameter' => $completionTokenParameter ? 'max_completion_tokens' : 'max_tokens',
            'max_price' => $maxPrice,
        ];
    }
}
