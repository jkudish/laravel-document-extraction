# Evaluation setup

The evaluation suite uses the package's public API with:

- [Pest Evals](https://github.com/pestphp/pest-plugin-evals)
- [Pest AI Benchmarks](https://github.com/jkudish/pest-plugin-ai-benchmarks)
- [Laravel AI Pricing](https://github.com/jkudish/laravel-ai-pricing)

All are installed from tagged public releases. The project has no VCS or local path repository.

## How it works

For each benchmark trial:

1. The benchmark applies one provider and model configuration.
2. It calls the normal `Extraction` API.
3. Middleware records each native AI attempt once.
4. Laravel AI Pricing records available pricing evidence.
5. Pest Evals scores the result.
6. Configuration is restored.

Extraction totals are output only. They are not counted again as benchmark observations.

## Offline smoke test

The smoke benchmark uses Laravel AI's fake and blocks stray HTTP. It checks plugin discovery, scoring, configuration cleanup, and call counting.

First prove that normal Pest runs skip evaluations:

```sh
proof_dir="$(mktemp -d)"
mkdir -p "$proof_dir/home" "$proof_dir/tmp"

env -i HOME="$proof_dir/home" TMPDIR="$proof_dir/tmp" \
  PATH=/usr/local/bin:/usr/bin:/bin PAO_DISABLE=1 \
  LDE_EVAL_EXECUTION_MARKER="$proof_dir/marker" \
  vendor/bin/pest tests/Evals/ExtractionBenchmarkTest.php --no-tia

test ! -e "$proof_dir/marker"
```

Then run the offline evaluation:

```sh
env -i HOME="$proof_dir/home" TMPDIR="$proof_dir/tmp" \
  PATH=/usr/local/bin:/usr/bin:/bin PAO_DISABLE=1 \
  LDE_EVAL_EXECUTION_MARKER="$proof_dir/marker" \
  vendor/bin/pest tests/Evals/ExtractionBenchmarkTest.php --no-tia --evals

test "$(wc -l < "$proof_dir/marker")" -eq 2
rm -rf "$proof_dir"
```

Never add `--evals` to the normal test command.

## Offline grouping proof

Run:

```sh
scripts/prove-grouping-benchmark
```

This command uses eight synthetic PDFs and no provider credentials. It checks:

- The public `detectDocuments()` flow
- 16 trials and 50 simulated native attempts
- Exact groups, merge errors, split errors, and page coverage
- Ambiguous and unassigned pages
- Invalid schema, membership, and provider responses
- Attempt identity and simulated pricing evidence
- Configuration cleanup
- Replay invalidation when code, fixtures, configuration, or native tools change
- Removal of generated replay data

Set `LDE_KEEP_GROUPING_RUN=1` only for local inspection. Never commit `replay.private.json`.

This proof tests wiring and scoring. It does not test model quality, live transport, or billable cost.

## Live OpenRouter screen

`scripts/live-grouping-screen` is the only live entry point. A dry run prints the routes, fixtures, repetitions, call count, privacy settings, confirmation text, and spend cap:

```sh
scripts/live-grouping-screen --stage=development-repeats
scripts/live-grouping-screen --stage=frozen-holdout
```

Live mode requires the exact printed confirmation and `OPENROUTER_API_KEY`. Never put the key on the command line or in logs.

Before every paid call, the script checks:

- The key has a finite monthly limit and enough remaining allowance
- The model and endpoint still exist
- Image and structured output are supported
- Price ceilings have not increased
- Required zero-data-retention routing is available
- `data_collection = deny` is set
- Provider fallback is disabled
- The selected synthetic PDF matches its approved path, size, and SHA-256
- The next reservation fits within the stage's software spend cap

Each trial uses the public extraction flow. Grouped extraction remains faked, so only the detector call is paid.

After each call, the script validates:

- Requested and effective model identity
- Provider and route evidence
- One detector measurement
- Unique call numbering
- All deterministic grouping scores
- Available provider cost or allowance change
- Private replay identity before cleanup

The script stops on route drift, invalid evidence, unknown applicable pricing units, or a spend-cap overrun.

## Spend and privacy limits

- The software cap is not a provider billing cap. It cannot undo a request in progress.
- Failed calls may still be billable.
- Unknown cost stays unknown, not zero.
- Delayed allowance changes are not assigned to later calls.
- Ledgers live under Git administrative storage and survive worktree cleanup.
- Ledgers trust the current OS user. They are not an external authorization service.
- Real documents, prompts, provider responses, and credentials must not enter committed scorecards.

## Completed evaluation

The completed stages are summarized in [`grouping-models.md`](grouping-models.md).

- Finalist development repeats: 30 of 30 passed all grouping checks
- Frozen holdout: 27 of 27 passed all grouping checks
- Both stages had no technical failures
- Grouped extraction and OCR remained simulated

No additional provider calls are authorized by this evaluation plan.

Any new paid evaluation requires:

- Explicit authorization
- A reviewed model and fixture list
- A privacy decision
- A spend limit
- A fresh dry run
