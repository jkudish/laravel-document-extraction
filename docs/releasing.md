# Releasing

This maintainer runbook prepares and publishes immutable GitHub prereleases for the Composer library.
It does not authorize publication. A release requires explicit approval of its exact commit and tag.

## Version policy

Git tags supply Composer package versions, so `composer.json` intentionally has no `version` field.
The first prerelease is `v0.1.0-beta.1`; subsequent beta corrections increment the final number rather
than moving or replacing a published tag.

## Prepare an exact candidate

Start only after the release-preparation PR is merged. Fetch `main`, require a clean fast-forwarded
checkout, and capture its full commit:

```sh
git fetch origin main
git switch main
git pull --ff-only origin main
test -z "$(git status --porcelain)"
release_sha="$(git rev-parse HEAD)"
```

Run the credential-free package, compatibility, and distribution gates:

```sh
composer verify
composer test:matrix
composer test:consumer

rm -rf /tmp/laravel-document-extraction-beta
mkdir -p /tmp/laravel-document-extraction-beta
COMPOSER_ROOT_VERSION=0.1.0-beta.1 composer archive \
  --format=zip \
  --dir=/tmp/laravel-document-extraction-beta \
  --file=laravel-document-extraction-0.1.0-beta.1
sha256sum /tmp/laravel-document-extraction-beta/laravel-document-extraction-0.1.0-beta.1.zip
```

`composer test:consumer` must report a clean installed copy and verify the archive allowlist and
denylist. The archive includes runtime code, configuration, workers, prompts, schemas, license,
README, changelog, and public docs. It excludes tests, scripts, contributor tooling, caches, private
evaluation evidence, and the library development `composer.lock`.

## Create the draft prerelease

Creating a draft does not authorize publication. Bind the draft to the captured full commit and use
the matching changelog section as its notes:

```sh
gh release create v0.1.0-beta.1 \
  --repo jkudish/laravel-document-extraction \
  --target "$release_sha" \
  --title "Laravel Document Extraction v0.1.0-beta.1" \
  --notes-file /tmp/laravel-document-extraction-beta/release-notes.md \
  --prerelease \
  --draft
```

Before requesting publication, verify the draft target, attach the exact archive if distribution by
GitHub asset is desired, record its SHA-256, and confirm no tag ref was created prematurely. Do not
include private ledgers, keys, scorecards, replays, prompts, provider responses, or source documents.

## Publish only after approval

The publication request must identify the exact commit, `v0.1.0-beta.1` tag, archive checksum,
verification results, known beta limits, and whether Packagist publication is also requested. GitHub
release publication, Packagist submission, repository visibility changes, and production deployment
are separate consequences.

After publication, verify that the immutable tag resolves to the approved commit and that a clean
consumer can install the tagged version. If a published beta is wrong, publish a new beta number;
never retarget the existing tag. Before publication, update or remove only the draft and rebuild its
evidence from the replacement exact candidate.
