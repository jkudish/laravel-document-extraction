# Verification

## Orb runtime

`.agents/setup` installs PHP 8.4 and 8.5 with Imagick and PCOV, Poppler, util-linux, Composer
2.10.3, and the locked development dependencies. Run it after checking out a branch
with different dependencies; it is safe to repeat. Warm runs ask Composer to reconcile
the lockfile rather than skipping installation based on a custom cache marker.

Amp snapshots successful setup for fresh orbs. `.agents/resume` only checks readiness;
it never downloads dependencies or starts services. This package needs no web server,
database, provider login, or model credentials for offline development.

```sh
.agents/setup
.agents/resume
```

Expected final output: `Document extraction development runtime is ready.` Missing
tools or `vendor/autoload.php` make resume fail with instructions to rerun setup.
Setup uses a credential-free child environment, private temporary downloads, and a
dedicated Composer home/cache at `~/.cache/lde-composer`. No shell activation is
needed: binaries are installed in standard paths. PHP patch versions follow the
signed Debian Sury repository; verification records the actual installed versions.

## Full checks

The package has no hosted CI. Local full verification is authoritative and always disables Test
Impact Analysis replay:

```sh
composer validate --strict
composer check-platform-reqs
composer audit --locked
vendor/bin/pint --test
composer analyse
vendor/bin/pest --no-tia
```

`composer verify` runs the same single-environment gates, including `check-platform-reqs`. No
command makes model calls or requires provider credentials. `composer analyse` launches Larastan
through a disposable allowlisted environment; use that route rather than invoking PHPStan directly
from a credential-bearing shell. PHPStan cache is confined to that temporary environment and
removed afterward, not written under the repository.

The application-level readiness command is also offline and secret-free:

```sh
php artisan extraction:doctor
```

It checks Linux containment, configured bounds, executable Poppler/PHP/`prlimit` paths, required PHP
extensions, the Illuminate Image Imagick driver, and every advertised codec. It does not contact a
provider, upload a document, fetch a URL, or make a network capability probe. A missing capability
is a failed readiness check, never a skipped-support claim.

## Development acceleration

Pest 5 TIA is local-only and uses PCOV installed by `.agents/setup`:

```sh
vendor/bin/pest --tia
vendor/bin/pest --tia --fresh
vendor/bin/pest --no-tia
```

TIA state is ignored under `.pest/`. Pest watches package configuration, fixtures, schemas,
prompts, and Composer inputs. The TIA directory is additionally partitioned by PHP, Imagick codec,
and Poppler runtime fingerprints so native runtime drift cannot replay another environment's
results. Run `composer test:tia:proof` to exercise baseline, replay, watched-input invalidation,
lock invalidation, and simulated native drift. The proof refuses detached HEAD and runs destructive
invalidation mutations on a named branch in a disposable local clone, never in the caller worktree.

PAO only optimizes test output for recognized agents. `composer test:pao:proof` forces PAO for one
passing run and one temporary intentional failure, and proves that the failure remains non-zero. Its
generated failure file and captured output live in a uniquely owned private directory under ignored
`.pest`, outside normal test discovery. Cleanup removes only files and the directory created by that
proof run; it never changes or removes caller-owned `.pest` children.

## Compatibility matrix

`composer test:matrix` builds four isolated installations from committed `HEAD`: PHP 8.4 and 8.5,
each with Laravel 13.23.0 and the newest Laravel allowed by `^13.23`. Every cell runs production
and development platform checks, full `--no-tia` tests, and Larastan level 10 across `src` and `tests`.
Illuminate Image is supplied by the pinned framework's replacement of `illuminate/image`;
the cell log identifies that bundled framework version explicitly. The matrix wrapper starts itself
in a disposable allowlisted environment, including its Composer and PHPStan caches, and removes that
environment on exit.

The September 5, 2026 verification exercised this resolved matrix:

| PHP | Laravel | Laravel AI | Testbench | Pest |
| --- | --- | --- | --- | --- |
| 8.4.25 | 13.23.0 | 0.11.2 | 11.2.0 | 5.1.3 |
| 8.4.25 | 13.30.1 | 0.11.2 | 11.2.0 | 5.1.3 |
| 8.5.10 | 13.23.0 | 0.11.2 | 11.2.0 | 5.1.3 |
| 8.5.10 | 13.30.1 | 0.11.2 | 11.2.0 | 5.1.3 |

All four cells passed the native Imagick image smoke test, the full uncached Pest suite, and
Larastan level 10. The locked current environment additionally resolved Laravel AI Pricing
0.1.0, Intervention Image 4.3.2, Opis JSON Schema 2.6.0, and Spatie PDF-to-text 1.55.0. Native
evidence was Imagick extension 3.8.1 over ImageMagick 6.9.11-60, PCOV 1.0.12, Poppler 22.12.0,
and Composer 2.10.3.

Preparation coverage uses real synthetic binaries for text/scanned/mixed/vector/blank/encrypted/
malformed PDFs and JPEG, PNG, WebP, TIFF, HEIC, HEIF, BMP, AVIF, and GIF images. The compressed PDF
bomb is executed only inside reduced Linux address-space and PHP-memory limits; it must never be run
without OS containment. The matrix proves these capabilities on the recorded Debian/Linux native
runtime only. It makes no Windows or macOS support claim, and a consumer deployment must run
`extraction:doctor` against its own installed codecs and executables.

Page-grouping tests are credential-free. They use Laravel AI's native structured and text fakes,
prevent stray HTTP, and exercise real PDF/image preparation so selected original page numbers,
source checksums, normalized visual attachments, and cleanup remain covered. The suite verifies
detector-off behavior, independent detection routing (including an OpenRouter-compatible route),
strict local assignment validation, partial sibling outcomes, grouped text/OCR, and shared
attempt/output/attachment limits. These tests prove orchestration and invariants, not model accuracy;
paid quality evaluation is a separate explicitly authorized activity.

The package requires Laravel 13.23 or newer. The complete native Illuminate Image API first exists
in Laravel 13.20 and its plural `config/images.php` convention first exists in 13.21, so 13.23 has
the required facade, manager, service provider, Intervention Image 4 Imagick driver, and
`illuminate/json-schema` version required by Laravel AI v0.11.2.

Exact-SHA verification receipts and `composer pr:check` / `composer pr:signoff` are intentionally
implemented as package-only PHP tooling; they add no Node, application, database, frontend, hosted
CI, deployment, or release machinery.

## Exact-SHA pull request workflow

The invariant is: **current clean HEAD descended from the verified base + successful receipt for that
SHA and ordered plan + explicit approval of that full SHA + open PR whose head and base match the
receipt**. A new commit, changed plan, changed Composer lock, changed native/PHP runtime, advanced
remote base, dirty file, missing matrix cell, or altered receipt fails closed. Starting a new check
first invalidates any prior receipt for the same SHA, so an interrupted or failed recheck cannot
leave old success evidence attestable.

### Verify a committed candidate

Fetch the intended PR base, commit all candidate changes, then run:

```sh
git fetch origin main
composer pr:check
```

For a stacked PR, name its actual base:

```sh
git fetch origin work/3435-foundation
composer pr:check -- --base work/3435-foundation
```

`pr:check` compares the exact local `origin/<base>` SHA with `git ls-remote`, proves that exact base is
an ancestor of the candidate, captures clean `HEAD`, and runs this ordered plan in a disposable
private home with ambient GitHub tokens and model/provider secrets omitted:

1. Composer install, strict validation, full platform checks, and locked audit.
2. Pint `--test` and Larastan level 10 across `src`, PR tooling, and tests.
3. The full native Pest suite with `--no-tia`.
4. TIA invalidation and PAO failure-preservation proofs.
5. PHP 8.4/8.5 × Laravel 13.23.0/current compatibility matrix with machine evidence.
6. Dedicated offline safeguard tests and shell syntax checks.

Only after a second clean-tree and unchanged-HEAD check does it atomically publish JSON under Git
administrative storage:

```text
$(git rev-parse --git-path laravel-document-extraction/pr-check/<sha>.json)
```

The directory is private and the receipt is mode `0600`. The receipt binds repository/origin,
candidate and fetched-base SHAs, ordered-plan hash/policy, Composer lock/package hashes, actual PHP,
Composer, Imagick/ImageMagick, PCOV and Poppler versions, and every completed matrix cell. Receipts
are local to one checkout/orb and must not be copied to attest another candidate or environment.
An offline synthetic-canary regression exercises the supported PHPStan child process and rejects any
credential canary in command output, the receipt, or the generated disposable analysis cache.

### Sign off an explicitly approved SHA

Install `basecamp/gh-signoff` and supply a dedicated `GH_SIGNOFF_TOKEN`. For a fine-grained token,
the narrow repository permissions are **Commit statuses: read/write**, **Pull requests: read**, and
mandatory metadata read; no Contents write or Administration permission is required. Do not replace
this token with ambient `GH_TOKEN`.

After a human or already-authorized delivery gate identifies the exact verified full SHA:

```sh
GH_SIGNOFF_TOKEN=... composer pr:signoff -- --approved-sha <40-lowercase-hex-sha>
```

The command revalidates the clean exact HEAD, private receipt, current plan/lock/runtime, and fresh
remote base; uses only the dedicated token as `GH_TOKEN` for `gh`; requires the extension and an open
PR with matching head/base; rechecks HEAD immediately before `gh signoff --commit <sha>`; never
forces; then queries `repos/<owner>/<repo>/commits/<sha>/status` and requires the `signoff` context to
be successful for that exact SHA.

Every GitHub subprocess pins `GH_HOST=github.com` and `GH_REPO` to the slug parsed from the validated
receipt/origin. Ambient host and default-repository configuration cannot redirect the dedicated
token or commit-status write.

CLI options are operation-specific. Unknown options—including `--force`—and duplicate options are
rejected before workflow execution. Output from ambient Git and every dedicated-token GitHub command
is suppressed; only the allowlisted secret-free verification steps and safe analysis route publish
their ordinary output.

`GH_SIGNOFF_TOKEN` is intentionally not installed by `.agents/setup`. Without it, signoff refuses
before any GitHub status mutation. Verification and receipt creation remain fully usable offline
apart from dependency downloads and remote-base freshness.

### Trust boundary

This follows Agentsy's trusted-token self-attestation pattern. The receipt's strict schema and
evidence hash catch accidental/partial alteration, but a local actor able to rewrite a receipt can
also recompute its unkeyed hash. It is not hostile tamper-proof evidence, a cryptographic signature,
independent CI, branch protection, merge authorization, publication, or release approval. Failed
status readback after `gh signoff` means the remote mutation may already have occurred and must be
investigated; the tool does not pretend it can roll back an append-only GitHub commit status.
