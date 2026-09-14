# Verification

## Setup

In an Amp orb or Debian 12 environment:

```sh
.agents/setup
.agents/resume
```

`.agents/setup` installs:

- PHP 8.4 and 8.5
- Imagick and PCOV
- Poppler and util-linux
- Composer
- Locked development dependencies

It is safe to run again. `.agents/resume` only checks readiness.

Offline development needs no web server, database, provider login, or model credentials.

## Standard checks

Run:

```sh
composer verify
```

This includes:

- `composer validate --strict`
- Platform requirement checks
- A locked dependency audit
- Pint
- Larastan level 10
- The full Pest suite with TIA disabled

The checks use credential-free child environments where needed. They do not contact an AI provider.

Run the package readiness command separately:

```sh
php artisan extraction:doctor
```

This Artisan command checks native tools, PHP extensions, configured limits, Imagick, and supported codecs. It does not upload a document or contact a provider.

## Consumer package proof

Run:

```sh
composer test:consumer
```

This command:

- Builds the Composer archive from committed `HEAD`
- Checks the archive allowlist and denylist
- Installs it in a clean Testbench application
- Proves package discovery and `extraction:doctor`
- Tests direct and partial text extraction
- Tests exact-decimal structured data
- Tests a reusable application agent
- Fakes Laravel AI and blocks stray HTTP

The archive includes runtime code, configuration, prompts, schemas, workers, license, README, changelog, and public docs.

It excludes tests, scripts, agent instructions, quality configuration, caches, credentials, private evaluation evidence, and `composer.lock`.

## Compatibility matrix

Run:

```sh
composer test:matrix
```

The matrix covers:

- PHP 8.4 and 8.5
- Laravel 13.23.0 and the newest supported Laravel 13 release
- Platform checks
- Full uncached Pest tests
- Larastan level 10
- Native Imagick smoke tests

The matrix runs in isolated installations. It proves the recorded Linux runtime only. It makes no Windows or macOS support claim.

Preparation tests use synthetic PDF and image fixtures. The compressed PDF bomb must only run under the suite's Linux memory limits.

## Faster local testing

Pest Test Impact Analysis is available for local development:

```sh
composer test:tia
composer test:tia:fresh
composer test:tia:proof
```

Use `vendor/bin/pest --no-tia` for complete evidence. TIA never replaces release checks.

`composer test:pao:proof` checks that PAO preserves a failing exit status.

## Grouping tests

Grouping tests are offline and credential-free. They cover:

- Real PDF and image preparation
- Original page numbers and source checksums
- Independent detector routing
- Local page-assignment validation
- Partial sibling results
- Shared attempt, output, attachment, and time limits

These tests prove package behavior, not model accuracy. Live evaluation is separate and explicitly authorized. See [`evaluation-setup.md`](evaluation-setup.md).

## Exact-commit maintainer check

This repository uses local verification instead of hosted CI. For a committed candidate:

```sh
git fetch origin main
composer pr:check
```

For a stacked PR:

```sh
git fetch origin <base-branch>
composer pr:check -- --base <base-branch>
```

`pr:check` requires:

- A clean worktree
- A candidate descended from the exact remote base
- An unchanged commit throughout the run
- Current Composer and runtime fingerprints
- Every required matrix cell

It runs:

1. Composer installation and metadata checks
2. Pint and Larastan
3. Full Pest tests with TIA disabled
4. TIA and PAO proofs
5. The PHP and Laravel matrix
6. Offline safeguard and shell checks

On success, it writes a private `0600` receipt under Git administrative storage. The receipt binds the commit, base, test plan, lockfile, package metadata, runtime versions, and matrix results.

Receipts belong to one checkout and environment. Do not copy them to attest another candidate.

## Maintainer signoff

Signoff requires:

- Explicit approval of the full verified commit SHA
- An open PR with the matching head and base
- The `basecamp/gh-signoff` extension
- A dedicated `GH_SIGNOFF_TOKEN`

The token needs only:

- Commit statuses: read/write
- Pull requests: read
- Metadata: read

Run:

```sh
GH_SIGNOFF_TOKEN=... composer pr:signoff -- --approved-sha <full-sha>
```

The command rechecks the commit, receipt, runtime, remote base, and PR before writing the GitHub status. It never forces a status.

`GH_SIGNOFF_TOKEN` is not installed by setup. Do not replace it with an ambient `GH_TOKEN`.

## Trust boundary

The receipt catches accidental or partial changes. It is not:

- A cryptographic signature
- Independent CI
- Branch protection
- Merge approval
- Publication or release approval

A local actor who can rewrite the receipt can recompute its unkeyed hash. A failed status readback may happen after GitHub accepted the status and must be investigated.
