# OpenRouter models for document grouping

Retrieved **2026-09-07T03:27:36Z** from OpenRouter's public catalog and endpoint APIs. This is a
dated research shortlist for a future paid synthetic-corpus pilot, not a runtime registry, default
model selection, spend approval, or claim of grouping or extraction accuracy.

## Compatibility screen

The grouping detector sends several normalized page images in one ordinary Laravel AI request and
expects strict schema output. A candidate therefore had to have all of the following in the current
OpenRouter metadata:

1. `image` among its input modalities and `text` among its output modalities;
2. both `response_format` and `structured_outputs` in the model metadata; and
3. at least one currently listed endpoint advertising both parameters.

`structured_outputs` matters: `response_format` alone can mean basic `json_object` mode, which only
promises valid JSON. Strict schema mode is `response_format.type = json_schema`. OpenRouter says
support is endpoint-specific, can change, and even strict enforcement varies by provider (native
enforcement, translation, or a strong hint). The package's local schema and membership checks remain
authoritative regardless of provider claims.

Laravel AI v0.11.2 is promising but unproved for these exact models. Its native OpenRouter gateway
maps each image attachment to an `image_url` part, builds `json_schema` response format for structured
agents, and merges `HasProviderOptions` values into the request body. That permits a native
`provider.require_parameters = true` option without a custom transport. The package forbids provider
options from replacing generated messages or `response_format`, as intended. **No paid request was
made**, so every row below is only **catalog-compatible**, not demonstrated through a live Laravel
SDK → OpenRouter → endpoint request. OpenRouter also warns that the number of images accepted in one
request varies by model and provider; the future pilot must exercise the actual multi-image bundles.

## Shortlist

Catalog prices are decimal **USD per token** except `image`, which is **USD per input image**.
Normalized token prices multiply the raw values by exactly 1,000,000. “Not provided” is not zero.
The table uses each model's top-level/default catalog price; endpoint variations follow it.

| Model ID | Why retain for consideration | Context / max output | Input / output per 1M tokens | Other reported charges |
| --- | --- | ---: | ---: | --- |
| `qwen/qwen3.7-plus` | Lowest token-cost independent family in this shortlist; one Alibaba endpoint advertises vision + strict structured output | 1,000,000 / 131,072 | $0.32 / $1.28 | cache read $0.064/M; cache write $0.40/M; no separate image or reasoning rate provided |
| `google/gemini-3.8-flash` | Low-cost multimodal option with six structured-capable endpoints and large context | 1,048,576 / 65,536 | $0.75 / $3.75 | $0.00000075/image; internal reasoning $3.75/M; cache read $0.075/M; cache write $0.0416666666666667/M |
| `mistralai/mistral-medium-3-5` | Different model/provider family and the clearest ZDR-tagged first-party endpoint option | 262,144 / 209,715 | $1.50 / $7.50 | no separate image, reasoning, or cache rate provided |
| `openai/gpt-5.6-sol` | Higher-capability-priced comparison from OpenAI; several strict-capable routes | 1,050,000 / 128,000 | $2.00 / $10.00 | cache read $0.20/M; cache write $2.50/M; no separate image or reasoning rate provided |
| `anthropic/claude-sonnet-5` | Higher-capability-priced comparison from Anthropic; multiple strict-capable routes | 1,000,000 / 128,000 | $2.00 / $10.00 | cache read $0.20/M; 5-minute write $2.50/M; 1-hour write $4.00/M; no separate image or reasoning rate provided |

### Price and endpoint qualifications

- **Qwen:** above 256,000 prompt tokens the catalog override is $0.96/M input, $3.84/M
  output, $0.192/M cache read, and $1.20/M cache write. Its only listed endpoint is Alibaba;
  the public ZDR endpoint list did not identify a strict-capable route for this model.
- **Gemini:** structured-capable endpoint tiers ranged from $0.375/$1.875 per 1M tokens for
  `flex`, through the table's default $0.75/$3.75, to $1.35/$6.75 for `priority`; their image,
  reasoning, and cache rates move with the tier. One Vertex flex endpoint reported status `-2`
  rather than `0` at retrieval; this report does not infer undocumented status semantics.
  Flex/priority service tiers require explicit eligibility/selection and should not be assumed from
  the model-level price. The ZDR list contained structured-capable Vertex global endpoints, but not
  AI Studio endpoints.
- **Mistral:** default and `mistral/zdr` routes were $1.50/$7.50 per 1M; the EU route was
  $1.65/$8.25. The ZDR list contained strict-capable `mistral/zdr` and `mistral/eu` routes.
- **GPT:** strict-capable endpoints ranged from OpenAI flex at $1/$5 per 1M through default at
  $2/$10 and fast at $4/$20, then Azure at $5/$30–$5.50/$33. For prompts above 272,000 tokens,
  every endpoint has a higher override; default becomes $4/$15. The Bedrock endpoint did **not**
  advertise structured output. The ZDR list contained strict-capable Azure routes, not first-party
  OpenAI routes.
- **Claude:** strict-capable routes were $2/$10 per 1M except Bedrock `us-east-1` at $2.20/$11.
  Three Google Vertex routes advertised `response_format` but **not** `structured_outputs` and must
  be excluded by `require_parameters`. The ZDR list contained strict-capable Bedrock routes; other
  routes must not be presumed ZDR.

An absent `image` field does not make image input free; it means the catalog supplied no separate
per-image fee. Image processing can contribute model-specific prompt tokens. Likewise, absence of
`internal_reasoning` is not evidence of free reasoning: reasoning tokens may be reflected in normal
completion usage. Actual response usage and recorded cost, not this estimate, should be the billing
evidence. No model has a fixed per-request fee in the retrieved shortlist metadata.

## Future capped synthetic pilot

Start with **three candidates**, without selecting a winner:

1. `qwen/qwen3.7-plus` for the lowest token-cost screen and a distinct family;
2. `google/gemini-3.8-flash` for a low-cost, broad-multimodal comparison with several endpoints; and
3. `anthropic/claude-sonnet-5` for a higher-priced family comparison and multiple strict-capable
   routes.

If Qwen cannot meet the required data policy or native multi-image/schema request, replace it with
`mistralai/mistral-medium-3-5`. Use `openai/gpt-5.6-sol` as an additional higher-priced comparison
only if the capped budget permits. This ordering is experimental design, not a capability ranking.

For each admitted model/endpoint combination:

- freeze the prompt, schema, page rendering, endpoint routing/data policy, model ID, and reasoning
  setting; use `provider.require_parameters = true` and a hard OpenRouter `max_price` ceiling;
- tune nothing on the holdout. Use the development bundles for prompt/schema changes, then freeze
  them before evaluating held-out examples;
- run at least three repeats per synthetic bundle because seeds and nominally low randomness do not
  guarantee reproducibility across providers;
- record exact-group match per bundle, merge mistakes, split mistakes, assignment coverage,
  ambiguity handling, unassigned-page handling, schema/membership failures, provider failures,
  latency, token/image/reasoning usage, and recorded USD cost; and
- report both per-bundle outcomes and aggregates. Do not convert successful schema validation into
  an accuracy claim and do not mix extraction quality with grouping quality.

The pilot is intentionally deferred: it requires explicit paid-call and data-policy approval.

## Routing, privacy, and operational caveats

- OpenRouter's default router is price-weighted and allows fallbacks. For comparable trials, pin the
  intended endpoint with `provider.only` (or exact `order`) and `allow_fallbacks = false`; otherwise
  model ID alone does not identify the serving endpoint, price, latency, or policy.
- `require_parameters = true` excludes endpoints that do not advertise every sent parameter. Without
  it, unsupported parameters may be ignored; OpenRouter's soft preference does not remove a model
  when no endpoint supports the parameter.
- `provider.data_collection = deny` filters providers that may store/train on data; `provider.zdr =
  true` restricts inference to endpoints OpenRouter marks zero-retention. These are routing controls,
  not independent guarantees. OpenRouter states endpoint policy can differ from provider policy and
  takes a conservative stance when policy is unknown. ZDR still permits in-memory implicit caching
  and does not cover separately enabled plugins/tools.
- ZDR can remove first-party endpoints (for example, selecting Azure instead of first-party OpenAI,
  Vertex instead of AI Studio, or Bedrock/Vertex instead of first-party Anthropic), changing price,
  behavior, and availability. Re-fetch endpoint metadata and policy immediately before a paid pilot.
- Context and max-output values are ceilings, not proof that a request containing all rendered pages
  fits. Input and output share context, image tokenization varies, and per-provider image-count/body
  limits are not represented by the headline context number.
- Reasoning is enabled by default in the retrieved metadata for Qwen, Gemini, GPT, and Claude;
  Mistral's `default_enabled` was not provided. It is mandatory for Gemini 3.8 Flash. Fix reasoning
  settings where the model permits it and include reasoning usage in cost/latency comparisons. Do
  not compare “latency” without recording endpoint and service tier.
- Availability, endpoint status, prices, and policies are volatile. This snapshot is evidence for
  consideration only; it must never auto-activate a model or become a production allowlist.

## Sources

All sources were public and retrieved without provider credentials or model calls:

- [OpenRouter Models API](https://openrouter.ai/api/v1/models) — exact IDs, modalities, model-level
  supported parameters, context/output limits, prices, overrides, and reasoning metadata.
- Endpoint APIs for [Qwen](https://openrouter.ai/api/v1/models/qwen/qwen3.7-plus/endpoints),
  [Gemini](https://openrouter.ai/api/v1/models/google/gemini-3.8-flash/endpoints),
  [Mistral](https://openrouter.ai/api/v1/models/mistralai/mistral-medium-3-5/endpoints),
  [GPT](https://openrouter.ai/api/v1/models/openai/gpt-5.6-sol/endpoints), and
  [Claude](https://openrouter.ai/api/v1/models/anthropic/claude-sonnet-5/endpoints) — endpoint-specific
  parameters, prices, status, provider tags, and limits.
- [OpenRouter Models documentation](https://openrouter.ai/docs/guides/overview/models) — field units,
  zero semantics, overrides, supported-parameter meaning, and context-limit caveats.
- [Structured Outputs](https://openrouter.ai/docs/guides/features/structured-outputs) — strict
  `json_schema`, endpoint-specific support, `require_parameters`, and enforcement caveats.
- [Image Inputs](https://openrouter.ai/docs/guides/overview/multimodal/image-understanding) — multiple
  image parts, supported formats, and provider/model image-count variability.
- [Provider Routing](https://openrouter.ai/docs/guides/routing/provider-selection) — routing,
  fallbacks, parameter enforcement, price ceilings, data collection, and ZDR controls.
- [Zero Data Retention](https://openrouter.ai/docs/guides/features/zdr) and the live
  [ZDR endpoint API](https://openrouter.ai/api/v1/endpoints/zdr) — endpoint policy scope and current
  eligible endpoint tags.
- Laravel AI v0.11.2 source at the locked commit: native OpenRouter
  [structured request builder](https://github.com/laravel/ai/blob/ee2c5162838d440c4e2e629ea93c8c87e838eaed/src/Gateway/OpenRouter/Concerns/BuildsTextRequests.php)
  and [attachment mapper](https://github.com/laravel/ai/blob/ee2c5162838d440c4e2e629ea93c8c87e838eaed/src/Gateway/OpenRouter/Concerns/MapsAttachments.php).

The machine-readable companion is [`grouping-models.json`](grouping-models.json). It retains only
the shortlist rather than committing a full catalog scrape.
