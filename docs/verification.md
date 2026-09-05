# Verification

## Orb runtime

`.agents/setup` installs PHP 8.4 and 8.5 with Imagick and PCOV, Poppler, Composer
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

Until the foundation reaches `main`, the Amp project pre-setup bridge caches a pinned
foundation checkout and its installed dependencies outside the working tree at
`~/.cache/lde-orb-bootstrap`. It leaves the README-only `main` unchanged. After checking
out an implementation branch, run `.agents/setup` to materialize its locked dependencies
from the warm Composer cache. Once `main` contains `.agents/setup`, the bridge is a no-op
and Amp runs the repository lifecycle normally.

## Full checks

The package has no hosted CI. Local full verification is authoritative and always disables Test
Impact Analysis replay:

```sh
composer validate --strict
composer check-platform-reqs
composer audit --locked
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest --no-tia
```

`composer verify` runs the same code-quality gates except `check-platform-reqs`, which should be
run separately for the intended production installation. No command makes model calls or requires
provider credentials.

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
lock invalidation, and simulated native drift.

PAO only optimizes test output for recognized agents. `composer test:pao:proof` forces PAO for one
passing run and one temporary intentional failure, and proves that the failure remains non-zero.

## Compatibility matrix

`composer test:matrix` builds four isolated installations from committed `HEAD`: PHP 8.4 and 8.5,
each with Laravel 13.23.0 and the newest Laravel allowed by `^13.23`. Every cell runs production
and development platform checks, full `--no-tia` tests, and Larastan level 10 across `src` and `tests`.
Illuminate Image is supplied by the pinned framework's replacement of `illuminate/image`;
the cell log identifies that bundled framework version explicitly.

The September 5, 2026 verification exercised this resolved matrix:

| PHP | Laravel | Laravel AI | Testbench | Pest |
| --- | --- | --- | --- | --- |
| 8.4.25 | 13.23.0 | 0.11.2 | 11.2.0 | 5.1.3 |
| 8.4.25 | 13.30.1 | 0.11.2 | 11.2.0 | 5.1.3 |
| 8.5.10 | 13.23.0 | 0.11.2 | 11.2.0 | 5.1.3 |
| 8.5.10 | 13.30.1 | 0.11.2 | 11.2.0 | 5.1.3 |

All four cells passed the native Imagick image smoke test, the full 7-test / 32-assertion suite,
and Larastan level 10. The locked current environment additionally resolved Laravel AI Pricing
0.1.0, Intervention Image 4.3.2, Opis JSON Schema 2.6.0, and Spatie PDF-to-text 1.55.0. Native
evidence was Imagick extension 3.8.1 over ImageMagick 6.9.11-60, PCOV 1.0.12, Poppler 22.12.0,
and Composer 2.10.3.

The package requires Laravel 13.23 or newer. The complete native Illuminate Image API first exists
in Laravel 13.20 and its plural `config/images.php` convention first exists in 13.21, so 13.23 has
the required facade, manager, service provider, Intervention Image 4 Imagick driver, and
`illuminate/json-schema` version required by Laravel AI v0.11.2.

Exact-SHA verification receipts and `composer pr:check` / `composer pr:signoff` are intentionally
reserved for PlanMode task #3441. The `composer verify` command is the package-quality extension
point that those safeguards can invoke; this foundation does not claim signoff or release proof.
