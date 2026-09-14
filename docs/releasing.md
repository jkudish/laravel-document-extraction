# Releasing

This runbook covers GitHub and Packagist releases. It does not authorize publication, a visibility change, or Packagist submission. Get approval for each external action.

## Choose a version

Git tags provide Composer versions. Do not add a `version` field to `composer.json`.

Use an immutable SemVer tag:

```sh
version=v0.1.0-beta.2 # or v0.1.0
composer_version="${version#v}"
```

Never move a published tag. Publish a new version instead.

## Prepare the candidate

Start after the release-preparation PR is merged. Update the changelog with:

- The version and release date
- User-facing changes
- Important compatibility or safety limits

Capture a clean `main` commit:

```sh
git fetch origin main
git switch main
git pull --ff-only origin main
test -z "$(git status --porcelain)"
release_sha="$(git rev-parse HEAD)"
```

Before making a private repository public:

- Review all reachable commits, tags, and remote branches
- Check for credentials, private documents, evaluation evidence, and internal files
- Remove merged remote work branches that should not become public
- Enable GitHub private vulnerability reporting
- Check the description, topics, license, and support links

A clean current tree does not prove the repository history is safe to publish.

Run the release checks:

```sh
composer verify
composer test:matrix
composer test:consumer

archive_dir="$(mktemp -d)"
COMPOSER_ROOT_VERSION="$composer_version" composer archive \
  --format=zip \
  --dir="$archive_dir" \
  --file="laravel-document-extraction-$composer_version"
sha256sum "$archive_dir/laravel-document-extraction-$composer_version.zip"
```

Record:

- The full commit SHA
- Archive checksum
- Verification results
- Known release limits

## Draft the GitHub release

Prepare notes from the matching changelog entry. Use `--prerelease` for alpha, beta, and release candidates. Omit it for stable releases.

```bash
release_notes="$archive_dir/release-notes.md"
release_flags=(--prerelease) # use () for a stable release

gh release create "$version" \
  --repo jkudish/laravel-document-extraction \
  --target "$release_sha" \
  --title "Laravel Document Extraction $version" \
  --notes-file "$release_notes" \
  "${release_flags[@]}" \
  --draft
```

Before publication:

- Verify the draft targets `release_sha`
- Attach the exact archive if desired
- Record the attachment checksum
- Confirm the notes contain no private names or data

## Publish the GitHub release

Get explicit approval for the exact commit and version. Visibility and Packagist are separate approvals.

Then publish and verify the tag:

```sh
gh release edit "$version" \
  --repo jkudish/laravel-document-extraction \
  --draft=false

test "$(git ls-remote origin "refs/tags/$version^{}" | awk '{print $1}')" = "$release_sha" \
  || test "$(git ls-remote origin "refs/tags/$version" | awk '{print $1}')" = "$release_sha"
```

If a published release is wrong, publish a new version. Never retarget the tag.

## Publish on Packagist

Packagist requires a public repository.

1. Confirm the approved visibility change is complete.
2. Check the public README, license, tag, and GitHub release.
3. Submit the repository at <https://packagist.org/packages/submit>.
4. Enable or verify the GitHub integration for future tags.
5. Confirm Packagist points to the approved tag.

Finally, test a clean public install without a VCS override:

```sh
composer require "jkudish/laravel-document-extraction:$composer_version"
php artisan package:discover --ansi
php artisan extraction:doctor
composer show jkudish/laravel-document-extraction --locked
```

The package must resolve from Packagist and pass the consumer environment's native checks.
