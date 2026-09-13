# OpenRouter models for document grouping

This guide records the OpenRouter grouping evaluation completed on **September 9, 2026**. It is an optional starting point, not a package default or production allowlist.

The detector sends several page images and expects strict page groups. A compatible route needs:

- Image input and text output
- JSON Schema structured output
- An endpoint that supports both
- Successful use through Laravel AI and OpenRouter

Support and pricing change. Check the current OpenRouter catalog before using these settings.

## Optional tested configuration

The tested order was:

1. Gemini 2.5 Flash
2. GPT-5.6 Luna
3. Claude Haiku 4.5

Create a Laravel AI provider alias for each route:

```php
// config/ai.php
'providers' => [
    // ...
    'grouping-gemini' => [
        'driver' => 'openrouter',
        'key' => env('OPENROUTER_API_KEY'),
    ],
    'grouping-luna' => [
        'driver' => 'openrouter',
        'key' => env('OPENROUTER_API_KEY'),
    ],
    'grouping-haiku' => [
        'driver' => 'openrouter',
        'key' => env('OPENROUTER_API_KEY'),
    ],
],
```

Then configure detection:

```php
// config/extraction.php
'detection' => [
    'provider' => [
        'grouping-gemini' => env('EXTRACTION_GROUPING_GEMINI_MODEL', 'google/gemini-2.5-flash'),
        'grouping-luna' => env('EXTRACTION_GROUPING_LUNA_MODEL', 'openai/gpt-5.6-luna'),
        'grouping-haiku' => env('EXTRACTION_GROUPING_HAIKU_MODEL', 'anthropic/claude-haiku-4.5'),
    ],
    'model' => null,
    'timeout' => null,
    'options' => [
        'grouping-gemini' => [
            'max_tokens' => 512,
            'reasoning' => ['effort' => 'none', 'exclude' => true],
            'provider' => [
                'only' => [env('EXTRACTION_GROUPING_GEMINI_ENDPOINT', 'google-vertex/global')],
                'allow_fallbacks' => false,
                'require_parameters' => true,
                'data_collection' => 'deny',
                'zdr' => true,
            ],
        ],
        'grouping-luna' => [
            'max_completion_tokens' => 512,
            'reasoning' => ['effort' => 'none', 'exclude' => true],
            'provider' => [
                'only' => [env('EXTRACTION_GROUPING_LUNA_ENDPOINT', 'azure/eu')],
                'allow_fallbacks' => false,
                'require_parameters' => true,
                'data_collection' => 'deny',
                'zdr' => true,
            ],
        ],
        'grouping-haiku' => [
            'max_tokens' => 512,
            'reasoning' => ['effort' => 'none', 'exclude' => true],
            'provider' => [
                'only' => [env('EXTRACTION_GROUPING_HAIKU_ENDPOINT', 'amazon-bedrock/global')],
                'allow_fallbacks' => false,
                'require_parameters' => true,
                'data_collection' => 'deny',
                'zdr' => true,
            ],
        ],
    ],
],
```

Laravel AI only advances through this map for failures covered by its native failover contract. Invalid JSON and package limit failures stay visible.

Before using this configuration, check:

- Model and endpoint availability
- Structured-output support
- Pricing
- Data retention and regional requirements
- Results on your own documents

Our test documents are a useful starting point, but every document set is different. Before relying on the package in production, test it with your own files, schemas, and provider setup, and review the extracted data appropriately for your application.

## Final evaluation results

The final evaluation used three models, eight synthetic fixtures, and three observations per model and fixture. Each model passed all 24 calls.

| Model @ endpoint | Passed | Average latency | Provider-reported cost |
| --- | ---: | ---: | ---: |
| `google/gemini-2.5-flash @ google-vertex/global` | 24 / 24 | 1.880s | $0.011665600 |
| `openai/gpt-5.6-luna @ azure/eu` | 24 / 24 | 2.899s | $0.045262910 |
| `anthropic/claude-haiku-4.5 @ amazon-bedrock/global` | 24 / 24 | 2.775s | $0.162840000 |

Gemini led on observed quality, speed, and cost. Luna and Haiku provide provider-diverse fallbacks.

These results only measure document grouping on the included test files. They do not prove that extracted data is correct. Test the package with your own documents and review the results before using them in your application.

Grouped extraction and OCR were simulated. Only the detector calls were live.

## Evaluation stages

| Stage | Calls | Result |
| --- | ---: | --- |
| Compatibility canary | 15 | 7 exact routes survived |
| Broad development screen | 28 | 17 of 20 scored calls passed all checks; 8 had technical evidence only |
| Prompt clarification | 8 | 7 of 7 scored calls passed; 1 technical failure |
| Development coverage | 12 | 12 of 12 passed |
| Finalist repeats | 30 | 30 of 30 passed |
| Frozen holdout | 27 | 27 of 27 passed |

The full catalog snapshot and candidate details are in [`grouping-models.json`](grouping-models.json).

## What was scored

Each trial checked:

- Exact group sets
- Incorrect merges and splits
- Selected-page coverage
- Ambiguous pages
- Unassigned pages
- Schema validity
- Page membership validity
- Provider failures

The scorer used deterministic checks, not another model.

## Evaluation safeguards

The live screen:

- Used synthetic PDFs only
- Pinned full endpoint slugs
- Disabled provider fallback
- Required supported parameters
- Requested zero-data-retention routing and denied data collection
- Set an output limit
- Checked the source file before each request
- Recorded provider identity, latency, usage, and available cost
- Applied a separate software spend cap before each call

The software spend cap cannot undo a provider charge already in progress. Failed calls may still cost money. Unknown cost remains unknown, not zero.

See [`evaluation-setup.md`](evaluation-setup.md) for the offline and live verification commands.

## Routing, price, and privacy notes

- Structured-output support varies by endpoint.
- OpenRouter routing and model aliases can change provider identity.
- Use a full endpoint slug when identity matters.
- `max_price` limits rates, not total spend.
- Missing image, cache, or reasoning fees mean “not provided,” not free.
- Context limits do not guarantee every page image will fit.
- `data_collection = deny` and `zdr = true` are routing constraints, not independent guarantees.
- Recheck availability, prices, and data policies before each evaluation or deployment.

## Sources

- [OpenRouter Models API](https://openrouter.ai/api/v1/models)
- [OpenRouter model documentation](https://openrouter.ai/docs/guides/overview/models)
- [Structured Outputs](https://openrouter.ai/docs/guides/features/structured-outputs)
- [Image Inputs](https://openrouter.ai/docs/guides/overview/multimodal/image-understanding)
- [Provider Routing](https://openrouter.ai/docs/guides/routing/provider-selection)
- [Zero Data Retention](https://openrouter.ai/docs/guides/features/zdr)
- [Pest Evals](https://github.com/pestphp/pest-plugin-evals)
- [Pest AI Benchmarks](https://github.com/jkudish/pest-plugin-ai-benchmarks)
- [Laravel AI Pricing](https://github.com/jkudish/laravel-ai-pricing)
