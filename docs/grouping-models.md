# OpenRouter models for document grouping

Retrieved **2026-09-07T03:47:07Z** from OpenRouter's public model, endpoint, and ZDR APIs.
This is an 18-model experimental screen for a future paid synthetic-corpus evaluation. It is not a
runtime registry, quality ranking, model activation, spend approval, or accuracy result.

## What “compatible” means here

The detector sends multiple normalized page images in one ordinary Laravel AI request and expects a
strict group-assignment schema. Every retained model currently has:

1. `image` input and `text` output in model metadata;
2. model-level `response_format` and `structured_outputs`; and
3. at least one endpoint advertising both parameters.

This is **catalog compatibility only**. No paid request was made, so none is yet demonstrated through
Laravel AI v0.11.2 → OpenRouter → the named endpoint. OpenRouter says strict-output support is
endpoint-specific and can change; provider enforcement ranges from native strict mode to translation
or a strong hint. The package's local schema and page-membership validation remains authoritative.
`response_format` without `structured_outputs` is not enough: `json_object` only means valid JSON,
whereas this flow needs `response_format.type = json_schema`.

Laravel AI v0.11.2 does provide the required native wire shapes: one `image_url` part per image,
`json_schema` for a structured agent, and merged `HasProviderOptions`. This permits
`provider.require_parameters = true`, full endpoint routing slugs, and rate ceilings without a custom
transport. Actual multi-image limits still vary by endpoint and must be tested in the authorized eval.

## Expanded candidate matrix

Prices below are the current model-level/default catalog rates in USD per 1M input/output tokens.
Raw per-token decimals, endpoint ranges, cache/reasoning/image fees, overrides, and full endpoint slugs
are preserved in [`grouping-models.json`](grouping-models.json). A missing fee is **not provided**, not
zero. “Actual cost” is **not measured** for every row: it only exists after an eventual live response
and must come from recorded usage/provider cost evidence rather than these catalog estimates.

| Coverage group | Exact model ID | Context / max output | Catalog input / output per 1M | Important pricing or routing qualification |
| --- | --- | ---: | ---: | --- |
| Qwen current | `qwen/qwen3.8-flash` | 1,000,000 / 131,072 | $0.15 / $0.47 | Alibaba only; cache read/write $0.016/$0.20 per 1M; no strict ZDR entry |
| Qwen current | `qwen/qwen3.7-plus` | 1,000,000 / 131,072 | $0.32 / $1.28 | Above 256k prompt tokens: $0.96/$3.84; Alibaba only; no strict ZDR entry |
| Qwen prior | `qwen/qwen3.6-flash` | 1,000,000 / 65,536 | $0.1875 / $1.125 | Above 256k: $0.75/$3.00; Alibaba only; no strict ZDR entry |
| Qwen prior | `qwen/qwen3.5-flash-02-23` | 1,000,000 / 65,536 | $0.065 / $0.26 | Lowest listed Qwen rates; Alibaba only; no strict ZDR entry |
| Qwen vision | `qwen/qwen3-vl-32b-instruct` | 131,072 / 32,768 | $0.104 / $0.416 | Explicit VL model; Alibaba only; no strict ZDR entry |
| Qwen older vision | `qwen/qwen2.5-vl-72b-instruct` | 128,000 / 115,200 | $0.80 / $1.00 | Explicit older VL generation; only `parasail/fp8`, which appears in ZDR API |
| Gemini current | `google/gemini-3.8-flash` | 1,048,576 / 65,536 | $0.75 / $3.75 | $0.00000075/image; internal reasoning $3.75/M; endpoint range $0.375/$1.875–$1.35/$6.75 |
| Gemini older stable | `google/gemini-3.1-flash-lite` | 1,048,576 / 65,536 | $0.25 / $1.50 | $0.00000025/image; eight strict endpoints; range $0.125/$0.75–$0.45/$2.70 |
| Gemini older stable | `google/gemini-2.5-flash` | 1,048,576 / 65,535 | $0.30 / $2.50 | $0.00000030/image; seven strict endpoints; range $0.15/$1.25–$0.54/$4.50 |
| Gemini older stable | `google/gemini-2.5-pro` | 1,048,576 / 65,536 | $1.25 / $10.00 | $0.00000125/image; above 200k: $2.50/$15; seven strict endpoints |
| OpenAI requested | `openai/gpt-5.6-luna` | 1,050,000 / 128,000 | $0.20 / $1.20 | Replaces Sol; above 272k: $0.40/$1.80; strict endpoint range $0.10/$0.60–$0.40/$2.40 |
| Anthropic retained | `anthropic/claude-sonnet-5` | 1,000,000 / 128,000 | $2.00 / $10.00 | Six of nine endpoints strict; strict range $2/$10–$2.20/$11 |
| Anthropic added | `anthropic/claude-haiku-4.5` | 200,000 / 64,000 | $1.00 / $5.00 | Version-pinned Haiku; five of eight endpoints strict; range $1/$5–$1.10/$5.50 |
| Mistral diversity | `mistralai/mistral-small-2603` | 262,144 / 209,715 | $0.15 / $0.60 | Four strict endpoints; range $0.15/$0.60–$0.1875/$0.75; three ZDR entries |
| Meta diversity | `meta-llama/llama-4-maverick` | 1,048,576 / 115,200 | $0.20 / $0.696 | Five strict endpoints across providers; all five appear in ZDR API |
| ByteDance diversity | `bytedance-seed/seed-2.0-mini` | 262,144 / 131,072 | $0.10 / $0.40 | Above 128k: $0.20/$0.80; only `seed/fp8`, listed ZDR |
| Moonshot diversity | `moonshotai/kimi-k2.5` | 262,144 / 235,929 | $0.45 / $2.25 | Five of eight endpoints strict; strict range $0.45/$2.25–$0.60/$3.325 |
| Small-model diversity | `google/gemma-3-12b-it` | 131,072 / 16,384 | $0.05 / $0.15 | Only `deepinfra/bf16`, listed ZDR; smallest output ceiling in the screen |

These groups rank **experimental coverage**, not presumed quality. Newer, larger, or more expensive
does not imply better page grouping. The Qwen set deliberately spans general models, explicit VL
models, and generations; the Gemini set uses version-pinned stable IDs; the last five broaden model
and hosting families at low-to-mid listed rates.

### Requested-name disposition

- **GPT-5.6 Luna:** exact `openai/gpt-5.6-luna` is present and retained. The former Sol row is removed.
  `openai/gpt-5.6-luna-pro` also exists, but was not silently substituted for the requested Luna.
- **Sonnet:** exact `anthropic/claude-sonnet-5` remains.
- **Haiku:** exact version-pinned `anthropic/claude-haiku-4.5` is retained. The catalog also exposes
  `~anthropic/claude-haiku-latest`; the moving alias is not used in a reproducible eval.
- **Older Gemini:** stable `gemini-3.1-flash-lite`, `gemini-2.5-flash`, and `gemini-2.5-pro` are
  retained. The catalog has `gemini-3-flash-preview`, not a stable Gemini 3.0 ID, so it is excluded.
- **Older Qwen:** the catalog-compatible screen includes 3.6, 3.5, Qwen3 VL, and Qwen2.5 VL. No
  requested Qwen name was invented where an exact catalog ID was unavailable.
- Batch variants are excluded from the synchronous detector screen. Router aliases (`~...latest`,
  `openrouter/auto`, and `openrouter/free`) are excluded because they do not freeze model/endpoint
  identity for comparative evidence.

## Cost-controlled evaluation design

No paid-call authority exists yet. When a total cap and data policy are explicitly approved, use the
installed native package path and published evaluation dependencies:

- Pest Evals (`pestphp/pest-plugin-evals`) for deterministic grouping scorers;
- `jkudish/pest-plugin-ai-benchmarks` v0.1.0 for the model/endpoint comparison axis, repetitions, durable
  scorecards, replay/resume, requested/effective identity, and regression evidence; and
- installed `jkudish/laravel-ai-pricing` v0.1.0 for provenance-aware post-response cost attribution.

The Pest plugins are installed from tagged public releases with no VCS or path repository. Pest AI
Benchmarks records explicit scorer results through its own expectation and Pest Evals' public scorer
contract; no unreleased callback branch is required.

Do not replace deterministic grouping metrics with an LLM judge. Do not call catalog-rate arithmetic
“actual cost.” After each completed live trial, retain provider-reported OpenRouter cost when exposed;
otherwise calculate from normalized usage and a dated compatible rate, preserving source,
completeness, missing units, and snapshot provenance. Failed calls can still be billable and unknown
cost must remain unknown rather than zero.

Run the evaluation in gates:

1. **Offline protocol gate:** prove every configuration produces the same frozen images, prompt,
   schema, endpoint options, and local validators. Cost: $0 provider spend.
2. **Broad development screen:** one live grouping trial per model per development bundle:
   \(18D\) trials for \(D\) development bundles, not a total provider-call cap. Exercising the full
   extraction pipeline adds per-group extraction or OCR calls; count every stage and attempt in the
   budget. This screen can identify request incompatibility,
   chronic schema/membership failure, obvious grouping failure, or unacceptable observed cost/latency.
3. **Development finalists:** choose 3–5 models from measured evidence, not metadata. Add repeats to
   reach at least three trials per finalist/development bundle. Prompt/schema changes remain confined
   to development data.
4. **Frozen holdout:** freeze prompt, schema, rendering, model, full endpoint slug, routing, reasoning,
   and scoring before at least three repeats on each held-out bundle. Never tune on holdout outcomes.

The recommendation is to screen all 18 once before choosing finalists. If an eventual approved cap
cannot support that broad screen, three **coverage anchors**, not presumed winners, are
`qwen/qwen3.8-flash` (current low listed rate), `google/gemini-2.5-flash` (older stable Gemini with
seven strict endpoints), and `openai/gpt-5.6-luna` (the requested cross-family replacement for Sol).
Do not promote an anchor or any other candidate to finalist without measured development evidence.

Before execution, calculate a scenario estimate from the frozen call count, output cap, and current
rates, then enforce a separate total-spend control. OpenRouter `provider.max_price` is only a
per-unit **rate ceiling** (prompt/completion values are USD per 1M tokens; image is USD each), not a
total-run budget. Use `provider.require_parameters = true`, a full endpoint slug in `provider.only`,
and `allow_fallbacks = false` so endpoint, price, and data policy do not drift within a comparison.

Record these metrics per trial and aggregate without hiding bundle-level failures:

- exact group-set match and exact whole-bundle match;
- merge mistakes, split mistakes, duplicate assignments, missing assignments, and invalid pages;
- assignment coverage, ambiguity decisions, and unassigned-page decisions;
- schema, membership, provider, timeout, and local-validation failures;
- requested and effective model/provider/endpoint/reasoning identity;
- latency, prompt/output/image/cache/reasoning usage; and
- recorded USD cost, cost source/completeness, and unknown billable failures.

Grouping and extraction are separate settings and separate quality questions. Schema validity and
complete page coverage do not establish factual grouping accuracy; grouping accuracy does not
establish extraction accuracy.

## Routing, price, and privacy caveats

- Endpoint support differs within one model. For Luna, Sonnet, Haiku, and Kimi, some listed endpoints
  fail the strict screen; `require_parameters = true` is mandatory. Full eligible and excluded slugs
  are in the JSON snapshot.
- Default routing is price-weighted and allows fallback. A base provider slug can match multiple
  regions/tiers, so use the complete endpoint slug for evidence-quality trials.
- Flex, priority, fast, batch, and quantized endpoints can differ in rate, latency, availability, and
  behavior. Endpoint range is not a promised price. Re-fetch metadata immediately before execution.
- An absent `image` field means no separate per-image fee was provided; image processing can still
  produce billable prompt tokens. Absent reasoning/cache/request fees likewise mean not provided,
  not free. Only Gemini rows reported a separate image fee in this snapshot.
- Context and output are ceilings, not proof that all rendered pages fit. Input and output share
  context; image tokenization, request body, and image-count limits vary by endpoint.
- `data_collection = deny` and `zdr = true` are OpenRouter routing constraints, not independent policy
  guarantees. ZDR entries can change, permit in-memory implicit caching, and do not cover separately
  enabled tools/plugins. ZDR routing may move OpenAI to Azure, Gemini to Vertex, or Anthropic to
  Bedrock, changing price and behavior.
- Availability, prices, aliases, endpoint support, and data policies are volatile. This file must not
  auto-activate models or become a production allowlist.

## Sources

All research used public, unauthenticated sources and no model calls:

- [OpenRouter Models API](https://openrouter.ai/api/v1/models) for exact IDs, canonical slugs,
  modalities, prices, overrides, reasoning metadata, and context/output limits.
- Each row's endpoint URL follows `https://openrouter.ai/api/v1/models/{author}/{model}/endpoints`;
  all 18 exact URLs are retained in the companion JSON and were fetched independently.
- [ZDR endpoint API](https://openrouter.ai/api/v1/endpoints/zdr) for current model + full endpoint
  combinations marked zero-retention.
- [Models documentation](https://openrouter.ai/docs/guides/overview/models) for pricing units,
  missing-versus-zero semantics, overrides, parameter fields, and context ceilings.
- [Structured Outputs](https://openrouter.ai/docs/guides/features/structured-outputs) for
  `json_schema`, endpoint-specific support, and strict-enforcement caveats.
- [Image Inputs](https://openrouter.ai/docs/guides/overview/multimodal/image-understanding) for
  multi-image shapes and endpoint-dependent image limits.
- [Provider Routing](https://openrouter.ai/docs/guides/routing/provider-selection) and
  [ZDR documentation](https://openrouter.ai/docs/guides/features/zdr) for endpoint pinning, rate
  ceilings, fallback, data collection, and retention constraints.
- Locked Laravel AI v0.11.2 source: [structured request builder](https://github.com/laravel/ai/blob/ee2c5162838d440c4e2e629ea93c8c87e838eaed/src/Gateway/OpenRouter/Concerns/BuildsTextRequests.php)
  and [attachment mapper](https://github.com/laravel/ai/blob/ee2c5162838d440c4e2e629ea93c8c87e838eaed/src/Gateway/OpenRouter/Concerns/MapsAttachments.php).
- [Pest Evals](https://github.com/pestphp/pest-plugin-evals),
  [Pest AI Benchmarks](https://github.com/jkudish/pest-plugin-ai-benchmarks), and
  [Laravel AI Pricing](https://github.com/jkudish/laravel-ai-pricing) for the required future
  evaluation and actual-cost attribution boundaries. Pest AI Benchmarks v0.1.0 was published after the
  model-catalog retrieval and was separately verified from its public Packagist distribution.
