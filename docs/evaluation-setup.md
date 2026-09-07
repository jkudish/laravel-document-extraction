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
