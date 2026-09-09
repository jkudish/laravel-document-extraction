# Evaluation setup

The evaluation stack uses Pest Evals, Pest AI Benchmarks, Laravel AI Pricing, and the document
extraction package's normal public API. It does not introduce another runner, scoring registry,
workflow, or model-selection layer.

## Execution flow

Pest loads both plugins through their Composer `extra.pest.plugins` declarations. An explicitly
selected benchmark configuration temporarily maps provider, model, options, and allowlisted settings
onto `config/extraction.php`. The benchmark then calls `Extraction`, which follows the package's normal
preparation and native Laravel AI agent path. Benchmark middleware observes each native AI attempt;
Laravel AI Pricing prices that observation; and the benchmark-owned `toPassBenchmarkScorer()`
expectation delegates scoring and pass/fail behavior to Pest Evals while recording the result. The
benchmark plugin then records the trial and report. The configuration scope restores every overridden
key in a `finally` block.

Extraction result totals are output data only. They are not ingested as another benchmark pricing
observation, so one native attempt remains one pricing observation.

## Published dependencies

The development stack uses stable public releases:

- `jkudish/pest-plugin-ai-benchmarks` `^0.1.0`
- `pestphp/pest-plugin-evals` `^5.0.2`
- `jkudish/laravel-ai-pricing` `^0.1.0`

Composer resolves all three from Packagist and public GitHub distributions. The package declares no
VCS or local path repository, and `.agents/setup` installs the locked graph without Composer or GitHub
credentials. The benchmark records Pest Evals scorer results through its own public expectation, so
this package does not depend on an unreleased callback branch or maintained fork.

## Offline proof before paid evaluations

The committed smoke benchmark is deliberately separate from future quality/spend suites. It uses
Laravel AI's native agent fake, blocks stray Laravel HTTP requests, keeps the pricing catalog offline,
and exercises direct text extraction independently of document grouping.

Laravel AI marks the fake attempt as simulated, so both extraction and benchmark pricing correctly
remain unavailable and no pricing resolver is invoked for that attempt. The smoke proves plugin
discovery, production-path extraction, one native-attempt measurement, one recorded scorer result,
configuration restoration, and absence of duplicate cost attribution; it is not measured-live pricing
proof. The package's separate `NativeAiPricingTest` uses a controlled native gateway to prove measured
usage is resolved and attributed once without a network request.

Run the normal behavior check without `--evals`; the benchmark must be skipped and its execution marker
must remain absent:

```sh
proof_dir="$(mktemp -d)"
mkdir -p "$proof_dir/home" "$proof_dir/tmp"
env -i HOME="$proof_dir/home" TMPDIR="$proof_dir/tmp" \
  PATH=/usr/local/bin:/usr/bin:/bin PAO_DISABLE=1 \
  LDE_EVAL_EXECUTION_MARKER="$proof_dir/marker" \
  vendor/bin/pest tests/Evals/ExtractionBenchmarkTest.php --no-tia
test ! -e "$proof_dir/marker"
```

Then run only the dedicated offline smoke in eval mode; it must execute both configurations, record one
target observation and explicit scorer result per trial, and create two marker lines:

```sh
env -i HOME="$proof_dir/home" TMPDIR="$proof_dir/tmp" \
  PATH=/usr/local/bin:/usr/bin:/bin PAO_DISABLE=1 \
  LDE_EVAL_EXECUTION_MARKER="$proof_dir/marker" \
  vendor/bin/pest tests/Evals/ExtractionBenchmarkTest.php --no-tia --evals
test "$(wc -l < "$proof_dir/marker")" -eq 2
rm -rf "$proof_dir"
```

Never add `--evals` to the package's normal test command. Future paid evaluations need a separately
reviewed corpus, provider credentials, model list, spend ceiling, and explicit authorization; none are
part of this setup proof.

## Offline document-grouping scorecard

`tests/Evals/GroupingBenchmarkTest.php` is the credential-free gate before any live model screen. It
runs both an offline production configuration and an offline candidate configuration over all eight
synthetic PDFs (33 unique physical pages) through the public `detectDocuments()` API. The native
Laravel AI fake uses manifest truth to construct simulated gateway output, and the scorer receives the
same truth only after extraction. Assertions prove that manifest fixture IDs, split names, and
answer-label terminology do not enter detector or extraction prompts; attachments remain the ordinary
normalized source pages.

Nine deterministic Pest Evals scorers separately cover exact group sets, merged page pairs, split page
pairs, selected-page coverage, ambiguity, unassigned pages, invalid structured output, invalid
membership, and provider failure. A tenth scorer checks native attempt identity and simulated cost
evidence. Focused mutation cases prove that each metric fails independently instead of merely
confirming the perfect fake response.

The benchmark dependency fingerprint includes the complete package `src/` tree, scorer definitions,
all fixture bytes and truth, configuration, Composer lock, and the locked benchmark/Evals execution
owners. Configuration fingerprints include full provider/model/timeout, endpoint, routing, fallback,
reasoning, preparation, and resource-limit settings. PHP, Imagick, ImageMagick, and Poppler versions
are also part of each case identity, so runtime drift invalidates replay.

Run the complete proof from the repository root:

```sh
scripts/prove-grouping-benchmark
```

The script uses a credential-empty child environment, first proves normal mode skips all benchmark
trials, then executes the offline `--evals` run, applies the benchmark plugin's complete stable
scorecard validator, and cross-checks the private replay output. For each trial, the validator requires
one target measurement per actual native call with matching model identity and token usage,
simulated/unavailable pricing, no duplicate ingestion of extraction totals, all ten scorer results,
and both configurations across eight fixtures / 33 pages. It rejects a deliberately tampered
measurement/call identity, then changes the runtime fingerprint and proves the prior run cannot be
replayed.

The expected summary is 16 trials, 50 native attempts, and 176 results (the Pest test result plus ten
scorers per trial). Generated run evidence is removed after validation. Set
`LDE_KEEP_GROUPING_RUN=1` only when a local reviewer needs to inspect the synthetic run files; do not
commit `replay.private.json`, and continue to treat replay payloads as private application data for any
future real corpus.

This dry run proves wiring, deterministic scoring, custody, configuration restoration, evidence
cardinality, and replay invalidation. Because every AI response is simulated, it does not establish
semantic grouping quality, transport compatibility, billable cost, or a winning model. The documented
live screen remains gated by its explicit live opt-in and total spend/data-routing controls.

## Live OpenRouter grouping screen

`scripts/live-grouping-screen` is the only live entry point. An explicit stage is required even for a
dry run. Each dry run makes no network request and prints its exact finalist routes, fixture split,
repetitions, call count, privacy settings, confirmation, and $10 logical spend cap:

```sh
scripts/live-grouping-screen --stage=development-repeats
scripts/live-grouping-screen --stage=frozen-holdout
```

The live mode is intentionally narrow. It requires the exact printed confirmation and an
`OPENROUTER_API_KEY`; do not paste the key into the command line or logs. Before inference it verifies
that the key has a finite monthly limit no larger than $50 and at least $10 remaining. It then fetches
the current public catalog and rejects missing models, stale endpoints, unsupported image/structured
output, endpoint price increases, unavailable routes, or missing ZDR where the selected route requires
it. Every selected route requires ZDR and also pins `data_collection = deny`. Before any request
carrying page content, the parent and isolated child both
verify that the selected regular, non-symlink PDF remains inside the synthetic fixture directory and
matches its approved size and SHA-256.

```sh
scripts/live-grouping-screen --stage=development-repeats --live \
  --confirm=run-30-finalist-development-repeat-detector-calls

scripts/live-grouping-screen --stage=frozen-holdout --live \
  --confirm=run-27-frozen-holdout-detector-calls
```

The authorized development-repeat stage is Gemini 2.5 Flash, Luna, and Haiku × all five
`prompt-example` fixtures × two new repetitions: exactly 30 detector calls in a private ignored v5
ledger. The separately authorized holdout stage freezes those same three model configurations and
runs `same-issuer-invoices`, `ambiguous-orphan`, and `scan-like-raster` × three repetitions: exactly
27 detector calls in a private ignored v6 ledger. The holdout refuses before network access unless
the v5 ledger records the exact completed 30-call matrix for the same key and its development contract
fingerprint still matches the current prompt, schemas, rendering, routes/options, scorers, fixtures,
lockfile, and execution owners. Any intervening configuration change therefore requires a new reviewed
development gate rather than silently retuning on holdout.

Each model/fixture/repetition runs as one separately validated Pest benchmark trial before the next paid
request. The public `Extraction::fromPath(...)->detectDocuments()->schema(...)->extract()` path sends
the detector
through OpenRouter with the exact model and endpoint, `allow_fallbacks = false`,
`require_parameters = true`, a 512-token output limit, reasoning disabled where supported, and the
recorded rate ceilings. The application extraction agent remains Laravel AI-faked, so each trial has
exactly one paid detector request even when it detects several groups. Fixture IDs, split names,
repetition labels, and expected grouping truth are never added to the provider prompt.
After the completion, a bounded non-inference OpenRouter generation-metadata poll must confirm the
model, provider, observed data-region value, standard service tier, and no model router. A newly
completed generation can briefly return 404, so the poll retries only 404 with fixed 1s, 2s, 4s, 8s,
and 15s delays; any other error or a sixth 404 fails. When OpenRouter exposes its nullable
provider-response chain, it must contain exactly one successful response. The trial stops if that
independent route evidence is absent or inconsistent; fallback prevention also remains pinned in the
request and benchmark contract. OpenRouter does not document `data_region` as endpoint geography, so
Luna's exact request remains `azure/eu` while its separately observed value is pinned to `global`.

Each stage enforces its own $10 software admission budget. Before each sequential request, reconciled spend
plus a conservative reservation derived from the selected endpoint's full context capacity, the
highest advertised base or override input/cache/image-token rate, 512 output and reasoning tokens,
and the selected fixture's page count must fit under $10. Unbounded applicable charges, incomplete cost
evidence, unknown pricing units, or reservation overruns stop the run. Immediately after each request, the runner refreshes
the key allowance and uses validated provider-reported cost when available, otherwise the observed
allowance depletion. Delayed allowance changes are not attributed to a later call's reservation; the
cumulative allowance change and a persistent admission total are independently checked against $10.
The admission total uses validated provider cost when available and otherwise retains the call's full
catalog-derived reservation, including for technical failures, so delayed charges cannot create room
for another request.
This is a software safeguard, not a provider-level $10 cap: it cannot undo an in-flight provider charge,
and unrelated use of the same key is conservatively counted against this screen.
The ignored, private authorization ledger survives command restarts, binds the run to the current key,
the exact ordered model/fixture/repetition matrix, $10 cap, fixture identities, route options, and hashed execution
dependencies, and records a reservation before dispatch. A completed prefix
can resume, but an unresolved in-flight call or a fully consumed stage authorization cannot be run
again. The selected route and reservation are refreshed from the catalog immediately before every
request.

The nine quality scorers deliberately use a zero threshold so weak models remain recorded evidence
instead of aborting the screen; their numeric scores—not their `passed` flag—are the quality result.

After every trial, the command applies the benchmark plugin's stable scorecard validator and checks
fixture identity, all nine deterministic grouping scores, requested/effective model evidence, one
live detector measurement, simulated grouped extraction measurements, and unique call ordinals. The
runner also requires every scorer to reference the same target measurements and validates the private
replay's trial fingerprint, fixture identity, source hash, page count, and audited route before cleanup. The
production result's integrity scorer still requires one positive provider-reported USD cost with
matching extraction totals. When the outer benchmark observer cannot expose response pricing after a
locally rejected structured result, the command records the immediate key-allowance change instead of
inventing a per-call quote. A one-attempt technical failure is retained as compatibility evidence and
may have no observed charge; it does not acquire quality scores or effective-model evidence that the
failed response did not supply. Negative or over-reservation spend, duplicate calls, invalid
scorecard data, route drift, or reaching the logical spend cap stops the screen before the next model.
The command removes private replay after validation and retains only the ignored scorecard path.
Scorecards contain bounded metrics and call evidence, not source documents, prompts, or raw provider
responses.

The normal test suite never sets the confirmation and never makes these requests. Its focused offline
test fakes both OpenRouter preflight and inference while exercising the same command, fixture
selection, public extraction path, scorecard validation, replay cleanup, and fail-closed cases.

The broad-screen run completed on **2026-09-08**. It consumed all 28 unique model/fixture pairs,
produced 20 validated grouping scorecards and eight technical-evidence scorecards, and reconciled
$0.091173548 of provider-reported spend under its $5 software cap. Its ignored v2 ledger remains fully
consumed and separate from this stage. The prompt canary had fresh authorization for exactly
four finalists × `blank-separator` and `mixed-document-lengths`, used an ignored v3 ledger, and changed
only the detector instructions. It completed all eight calls with seven all-nine quality scorecards,
one bounded technical failure, $0.037815190 of provider-reported spend, and a $0.140727190 conservative
admission total. The v3 ledger is fully consumed and cannot repeat the matrix. Results and their limits
are summarized in [`grouping-models.md`](grouping-models.md). The coverage screen then ran the same four
models × `single-three-page-document`, `three-single-page-documents`, and
`non-financial-documents`: 12 unique calls under a $4 software cap, using an ignored v4 ledger with no
prompt or model-setting change. All 12 passed all nine quality checks. Provider-reported and admission
spend were $0.051611365; the delayed key-allowance snapshot changed by $0.043508365. The v4 ledger is
fully consumed and cannot repeat the matrix. The 30-call development-repeat v5 stage and contingent
27-call frozen-holdout v6 stage are now explicitly authorized under separate $10 caps. They remain
unexecuted until their reviewed offline/preflight gates pass; no other provider calls are authorized.
