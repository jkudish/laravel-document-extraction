# Contributing

Thank you for helping improve Laravel Document Extraction.

## Development setup

The package requires PHP 8.4 or 8.5, Laravel 13.23 or newer, Imagick, Poppler, util-linux, and the
PHP extensions declared in `composer.json`.

On Debian 12 or in an Amp orb, the repository provides a repeatable setup:

```sh
.agents/setup
.agents/resume
```

The setup script installs both supported PHP versions, native document tools, PCOV, Composer, and locked dependencies. It is safe to run again after switching branches.

On another Linux environment, install the required native tools and PHP extensions before running `composer install`.

This package does not require a web server, database, provider login, or model credentials for offline
development.

## Running checks

Run the normal package checks with:

```sh
composer verify
```

This runs:

- Composer metadata, platform, and dependency checks
- Pint
- Larastan level 10
- The complete credential-free test suite

Before a release or a change to compatibility, packaging, native preparation, or shared public
contracts, also run:

```sh
composer test:matrix
composer test:consumer
```

- `test:matrix` covers PHP 8.4 and 8.5 with the minimum and current Laravel 13 releases.
- `test:consumer` installs the exported package in a clean Testbench application.

Pest Test Impact Analysis is available for a faster local loop:

```sh
composer test:tia
composer test:tia:fresh
```

TIA is never accepted as a substitute for the complete `--no-tia` release checks.

## Pull requests

Keep changes focused. Add tests for behavior changes. Update the docs when a public contract changes.

Do not commit:

- Source documents or provider responses
- Credentials
- Benchmark replay
- Generated scorecards

This repository uses local verification instead of hosted CI. Maintainers run `composer pr:check` against a clean commit. Contributors do not need the maintainer signoff token.

The complete verification, receipt, and maintainer signoff workflow is documented in
[`docs/verification.md`](docs/verification.md).

## Live evaluations

Normal development never contacts an AI provider. Do not use `--evals`, the live grouping screen, or real documents in ordinary test runs.

Paid evaluation requires:

- A reviewed corpus
- A privacy decision
- A spend limit
- Explicit authorization

## Security issues

Please do not open a public issue for a suspected vulnerability. Follow the private reporting process
in [`SECURITY.md`](SECURITY.md).
