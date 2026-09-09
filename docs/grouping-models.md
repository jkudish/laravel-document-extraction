# OpenRouter models for document grouping

Retrieved **2026-09-07T03:47:07Z** from OpenRouter's public model, endpoint, and ZDR APIs.
This is a 15-model experimental screen with a completed paid synthetic compatibility canary and a
completed seven-model development-fixture screen. It is not a runtime registry, production allowlist,
or general accuracy result.

## What “compatible” means here

The detector sends multiple normalized page images in one ordinary Laravel AI request and expects a
strict group-assignment schema. Every retained model currently has:

1. `image` input and `text` output in model metadata;
2. model-level `response_format` and `structured_outputs`; and
3. at least one endpoint advertising both parameters.

Catalog compatibility does not establish request compatibility. The canary below exercises each
pinned route once through Laravel AI v0.11.2 → OpenRouter → the named endpoint. OpenRouter says
strict-output support is endpoint-specific and can change; provider enforcement ranges from native
strict mode to translation or a strong hint. The package's local schema and page-membership validation
remains authoritative. `response_format` without `structured_outputs` is not enough: `json_object`
only means valid JSON, whereas this flow needs `response_format.type = json_schema`.

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
| Gemini older stable | `google/gemini-2.5-pro` | 1,048,576 / 65,536 | $1.25 / $10.00 | $0.00000125/image; above 200k: $2.50/$15; canary pins healthy ZDR `google-vertex/us` |
| OpenAI requested | `openai/gpt-5.6-luna` | 1,050,000 / 128,000 | $0.20 / $1.20 | Replaces Sol; pins healthy ZDR `azure/eu`; above 272k: $0.40/$1.80 |
| Anthropic retained | `anthropic/claude-sonnet-5` | 1,000,000 / 128,000 | $2.00 / $10.00 | Six of nine endpoints strict; strict range $2/$10–$2.20/$11 |
| Anthropic added | `anthropic/claude-haiku-4.5` | 200,000 / 64,000 | $1.00 / $5.00 | Version-pinned Haiku; five of eight endpoints strict; range $1/$5–$1.10/$5.50 |
| Mistral diversity | `mistralai/mistral-small-2603` | 262,144 / 209,715 | $0.15 / $0.60 | Four strict endpoints; range $0.15/$0.60–$0.1875/$0.75; three ZDR entries |
| Meta diversity | `meta-llama/llama-4-maverick` | 1,048,576 / 115,200 | $0.20 / $0.696 | Five strict endpoints across providers; all five appear in ZDR API |

These groups rank **experimental coverage**, not presumed quality. Newer, larger, or more expensive
does not imply better page grouping. The Qwen set deliberately spans general models, explicit VL
models, and generations; the Gemini set uses version-pinned stable IDs; Mistral and Llama broaden
model and hosting families.

## Compatibility canary results

On **2026-09-08**, the approved command made exactly one paid detector request for each of the 15
routes against development fixture `bundle-03.pdf`. Grouped extraction remained simulated. No holdout
was used, no request was repeated, and no fallback route was allowed.

Each score position below is binary, where `1` means the check passed. The order is **exact groups /
merge-safe / split-safe / coverage / ambiguity / unassigned / schema-safe / membership-safe /
provider-safe**. A technical failure produced no quality scores. Latency is the detector measurement,
not an end-to-end extraction benchmark.

| Model @ pinned endpoint | Outcome | Nine grouping scores | Latency | Provider-reported cost |
| --- | --- | --- | ---: | ---: |
| `qwen/qwen3.8-flash @ alibaba` | structured result rejected | `0/1/0/0/1/0/0/1/1` | 4.305s | unavailable |
| `qwen/qwen3.7-plus @ alibaba` | structured result rejected | `0/1/0/0/1/0/0/1/1` | 5.205s | unavailable |
| `qwen/qwen3.6-flash @ alibaba` | technical failure | — | 0.782s | unavailable |
| `qwen/qwen3.5-flash-02-23 @ alibaba` | technical failure | — | 0.468s | unavailable |
| `qwen/qwen3-vl-32b-instruct @ alibaba` | exact | `1/1/1/1/1/1/1/1/1` | 5.281s | $0.001346072 |
| `qwen/qwen2.5-vl-72b-instruct @ parasail/fp8` | exact | `1/1/1/1/1/1/1/1/1` | 6.234s | $0.0131734 |
| `google/gemini-3.8-flash @ google-vertex/global` | technical failure | — | 0.128s | unavailable |
| `google/gemini-3.1-flash-lite @ google-vertex/global` | exact | `1/1/1/1/1/1/1/1/1` | 1.814s | $0.001782 |
| `google/gemini-2.5-flash @ google-vertex/global` | exact | `1/1/1/1/1/1/1/1/1` | 1.667s | $0.0006975 |
| `google/gemini-2.5-pro @ google-vertex/us` | technical failure | — | 0.130s | unavailable |
| `openai/gpt-5.6-luna @ azure/eu` | exact | `1/1/1/1/1/1/1/1/1` | 2.431s | $0.00383555 |
| `anthropic/claude-sonnet-5 @ amazon-bedrock/global` | structured result rejected | `0/1/0/0/1/0/0/1/1` | 7.655s | unavailable |
| `anthropic/claude-haiku-4.5 @ amazon-bedrock/global` | exact | `1/1/1/1/1/1/1/1/1` | 5.984s | $0.009605 |
| `mistralai/mistral-small-2603 @ mistral/zdr` | wrong grouping | `0/0/0/1/1/1/1/1/1` | 3.112s | $0.0022212 |
| `meta-llama/llama-4-maverick @ digitalocean` | exact | `1/1/1/1/1/1/1/1/1` | 3.928s | $0.003023344 |

Seven routes produced the exact expected grouping. Four more returned bounded evidence but failed
schema or grouping checks, and four failed before producing quality evidence. Provider-reported costs
were available for eight trials and totalled **$0.035684066**. The key allowance fell by
**$0.064145742** at the immediate final check and settled at **$0.079192966** ten seconds later. This
lag prevents assigning the difference truthfully to individual calls. The historical ledger's
**$0.069390286** accounting total was therefore not an upper bound; the delivered runner now retains a
full catalog-derived reservation whenever authoritative per-call cost is unavailable. The settled key
allowance remained far below the authorized $5 ceiling.

For the next development-fixture screen, retain only the seven exact routes: both Qwen VL models,
Gemini 3.1 Flash Lite, Gemini 2.5 Flash, Luna, Haiku, and Llama Maverick. Drop the other eight from the
next paid slice unless a later compatibility investigation specifically targets their failure. One
fixture is enough for this canary filter, but not enough to rank the seven survivors or select a
production default.

## Broad development-screen results

On **2026-09-08**, the seven canary survivors each made one paid detector request against the four
remaining development fixtures: 28 unique calls in total. Grouped extraction and OCR remained
simulated. No holdout, fallback route, or consumed model/fixture pair was used twice.

Twenty calls produced validated grouping scores. Seventeen passed all nine grouping checks. Three
produced bounded quality evidence with a single-fixture failure, all on `blank-separator`: Gemini 3.1
Flash Lite missed exact grouping, selected-page coverage, and unassigned-page handling; Gemini 2.5
Flash and Llama Maverick each missed ambiguity handling. Eight calls produced paid detector and cost
evidence but no quality score because the post-response route-evidence check failed: six completed
before bounded generation-metadata polling was added, and Luna's first two exposed that OpenRouter's
undocumented `data_region` reports `global` for the pinned `azure/eu` endpoint. These were harness
evidence failures, not model-quality failures, and were not repeated.

| Model @ pinned endpoint | All-nine passes / scored | Technical failures | Average detector latency across all four calls | Provider-reported cost |
| --- | ---: | ---: | ---: | ---: |
| `qwen/qwen3-vl-32b-instruct @ alibaba` | 0 / 0 | 4 | 3.508s | $0.003604640 |
| `qwen/qwen2.5-vl-72b-instruct @ parasail/fp8` | 2 / 2 | 2 | 4.373s | $0.035155000 |
| `google/gemini-3.1-flash-lite @ google-vertex/global` | 3 / 4 | 0 | 2.261s | $0.005017000 |
| `google/gemini-2.5-flash @ google-vertex/global` | 3 / 4 | 0 | 1.706s | $0.001908600 |
| `openai/gpt-5.6-luna @ azure/eu` | 2 / 2 | 2 | 3.345s | $0.011315700 |
| `anthropic/claude-haiku-4.5 @ amazon-bedrock/global` | 4 / 4 | 0 | 2.601s | $0.026089000 |
| `meta-llama/llama-4-maverick @ digitalocean` | 3 / 4 | 0 | 6.314s | $0.008083608 |
| **Total** | **17 / 20** | **8** | — | **$0.091173548** |

The runner reconciled all 28 provider-reported costs to a cumulative $0.091173548 admission total;
the observed key-allowance change was $0.088660908. The difference reflects provider allowance-update
timing and does not replace the per-call provider evidence. Both values remained far below the $5
software cap.

This evidence supports a development-finalist shortlist, not a production default. Haiku is the only
route with four of four broad fixtures passing every grouping check. Luna passed both scored fixtures;
Gemini 2.5 Flash and Llama each passed three of four, with only the blank-separator ambiguity check
failing. Qwen2.5 VL passed both scored fixtures but has incomplete coverage and the highest observed
cost. Qwen3 VL has no broad quality scores because all four calls predated the metadata-polling fix.

## Prompt clarification canary

All three scored broad-screen misses occurred on `blank-separator`. The metric pattern is consistent
with models either grouping the contentless page as its own document or marking it ambiguous, but the
private raw responses were deleted, so those output shapes are interpretations rather than observed
evidence. The detector instructions now state the existing result semantics explicitly: include a
blank or contentless page only when visible pagination or document continuity establishes membership;
otherwise omit it so it is reported as unassigned. Ambiguity is reserved for document-bearing pages
with genuinely uncertain membership or boundaries. The text contains no fixture name, page number, or
expected grouping.

The completed development-only canary held schema, rendering, endpoint routes, reasoning, 512-token
output limit, timeout, privacy, fallback, scorer, and source fixtures constant. It ran Haiku, Luna,
Gemini 2.5 Flash, and Qwen2.5 VL once each against `blank-separator` and the previously clean
`mixed-document-lengths` control: eight paid detector calls under a fresh $1 software cap. The prompt
fingerprint differs from the prior screen, so these results remain a separate contract rather than
being pooled as repeats. Grouped extraction and OCR were simulated; no holdout was used.

All four `blank-separator` calls passed all nine grouping checks. Gemini 2.5 Flash therefore changed
from failing ambiguity on this fixture under the prior instructions to an exact result under the
clarified instructions; Haiku, Luna, and Qwen2.5 VL remained exact. All three scored control calls were
also exact. Qwen2.5 VL's control call ended as a bounded technical failure before effective-model,
usage, cost, or grouping evidence was available, so it is excluded from both quality and regression
claims.

| Model @ pinned endpoint | All-nine passes / scored | Technical failures | Average detector latency across two calls | Provider-reported cost |
| --- | ---: | ---: | ---: | ---: |
| `qwen/qwen2.5-vl-72b-instruct @ parasail/fp8` | 1 / 1 | 1 | 3.168s | $0.011010000 |
| `google/gemini-2.5-flash @ google-vertex/global` | 2 / 2 | 0 | 1.734s | $0.001288500 |
| `openai/gpt-5.6-luna @ azure/eu` | 2 / 2 | 0 | 2.229s | $0.007763690 |
| `anthropic/claude-haiku-4.5 @ amazon-bedrock/global` | 2 / 2 | 0 | 3.021s | $0.017753000 |
| **Total** | **7 / 7** | **1** | — | **$0.037815190** |

The key-allowance change matched the seven provider-reported costs at $0.037815190. The admission
ledger retained Qwen2.5 VL's $0.102912 conservative reservation for the technical failure, producing a
$0.140727190 admission total under the $1 cap. One sample per model/fixture is enough to support
retaining the generic clarification for further development evaluation, but not to establish a
production default or eliminate stochastic variance. The four-model shortlist remains intact pending
repeats; Qwen's missing control result and higher observed cost keep it behind the three fully scored
routes rather than turning its technical failure into a quality loss.

## Prompt development coverage screen

The completed coverage gate kept the clarified prompt and all other runtime/model settings fixed while
covering the three development fixtures not used by the prompt canary:
`single-three-page-document`, `three-single-page-documents`, and `non-financial-documents`. The same
four models each ran once, for 12 unique detector calls under a fresh $4 software cap and ignored v4
ledger. Grouped extraction and OCR remained simulated. These fixtures were evaluated under the prior
prompt, but they are new pairs for this prompt fingerprint and are not pooled with prior-contract
results. No holdout or same-contract repeat was included.

Every call produced a validated quality scorecard and passed all nine grouping checks. The runner
reconciled $0.051611365 of provider-reported cost; the key allowance changed by $0.043508365 because
the $0.008103 difference exactly matched the final Haiku charge, consistent with that charge not yet
appearing in the allowance snapshot. Since every call had authoritative provider cost, recorded and
admission spend both remain $0.051611365 rather than substituting the delayed allowance value.

| Model @ pinned endpoint | All-nine passes / scored | Average detector latency across three calls | Provider-reported cost |
| --- | ---: | ---: | ---: |
| `qwen/qwen2.5-vl-72b-instruct @ parasail/fp8` | 3 / 3 | 4.152s | $0.024283400 |
| `google/gemini-2.5-flash @ google-vertex/global` | 3 / 3 | 1.536s | $0.001247100 |
| `openai/gpt-5.6-luna @ azure/eu` | 3 / 3 | 2.179s | $0.007839865 |
| `anthropic/claude-haiku-4.5 @ amazon-bedrock/global` | 3 / 3 | 3.000s | $0.018241000 |
| **Total** | **12 / 12** | — | **$0.051611365** |

Across both clarified-prompt gates, Gemini 2.5 Flash, Luna, and Haiku each passed all nine checks on
all five development fixtures. Qwen2.5 VL passed all four fixtures for which quality evidence exists;
its `mixed-document-lengths` call remains a technical failure excluded from quality claims. Gemini is
the development leader: it ties the other fully scored routes on observed deterministic quality while
averaging 1.615s and costing $0.002535600 across five calls, versus Luna at 2.199s/$0.015603555 and
Haiku at 3.009s/$0.035994000. Qwen averaged 3.758s across five attempted calls; its four
provider-reported costs sum to $0.035293400. These are single samples per model/fixture, so repeats
remain necessary before a holdout or production-default decision.

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

The 15-call compatibility canary, 28-call broad development screen, eight-call prompt clarification
canary, and 12-call prompt development coverage screen are complete. Further repeats and holdout remain
gated.
Use the installed native package path and published evaluation dependencies:

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
2. **Compatibility canary (complete):** one live detector trial per model against one development
   bundle: 15 calls total, with grouped extraction/OCR simulated. Seven exact routes survived.
3. **Broad development screen (complete):** one live grouping trial for each of the seven exact
   survivors against each of the four remaining development bundles: 28 detector calls, no repeats.
   Grouped extraction and OCR remained simulated. Twenty calls produced grouping scores, including 17
   that passed every grouping check; eight retained technical evidence without a quality score.
4. **Prompt clarification canary (complete):** four finalists ran once on `blank-separator` and one
   previously clean development control after the generic blank-page instruction change: eight calls,
   seven exact scorecards, one technical failure, no holdout, and no pooling with prior-prompt trials.
5. **Prompt development coverage (complete):** the same four finalists ran once across the three
   remaining development fixtures with every setting fixed: 12 exact scorecards, no holdout, and no
   same-contract repeats.
6. **Development finalists:** use the coverage outcome to add separately authorized repeats and reach
   at least three trials per finalist/development bundle.
7. **Frozen holdout:** freeze prompt, schema, rendering, model, full endpoint slug, routing, reasoning,
   and scoring before at least three repeats on each held-out bundle. Never tune on holdout outcomes.

The compatibility canary removed technical and clearly unusable candidates, and the broad and
clarified-prompt screens supply the development evidence above. Gemini 2.5 Flash is the leading default
candidate on measured development quality, latency, and cost. Retain Luna and Haiku as finalist
alternatives for provider diversity; demote Qwen2.5 VL unless its older-vision diversity justifies its
incomplete control coverage and higher cost. Llama remains the first reserve. Any additional calls
remain separately gated.

The executable screen is deliberately smaller than a manifest system: the test-owned model and
fixture lists in `tests/Support/LiveGroupingModels.php`, one Pest benchmark, and
`scripts/live-grouping-screen`. Run the command without arguments to inspect the exact current
proposal without network access. Live mode preflights the current catalog and key allowance, then
runs and validates one model/fixture trial at a time; it never silently changes the documented matrix.
Each trial verifies the synthetic PDF identity before egress and audits OpenRouter's generation
metadata after inference for the expected model, provider, observed data-region value, standard
service tier, and absence of a model router. OpenRouter does not document `data_region` as endpoint
geography: Luna therefore keeps its exact `azure/eu` request route while separately pinning the
observed value `global`. OpenRouter's provider-attempt chain is also checked when exposed.

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

- Endpoint support differs within one model. For Luna, Sonnet, and Haiku, some listed endpoints
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
  all 15 exact URLs are retained in the companion JSON and were fetched independently.
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
