# Verification

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
platform checks, full `--no-tia` tests, and Larastan level 10 across `src` and `tests`.

The package requires Laravel 13.23 or newer. The complete native Illuminate Image API first exists
in Laravel 13.20 and its plural `config/images.php` convention first exists in 13.21, so 13.23 has
the required facade, manager, service provider, Intervention Image 4 Imagick driver, and
`illuminate/json-schema` version required by Laravel AI v0.11.2.

Exact-SHA verification receipts and `composer pr:check` / `composer pr:signoff` are intentionally
reserved for PlanMode task #3441. The `composer verify` command is the package-quality extension
point that those safeguards can invoke; this foundation does not claim signoff or release proof.
