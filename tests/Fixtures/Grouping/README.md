# Synthetic page-grouping corpus

This directory contains eight entirely synthetic PDF bundles and independently authored grouping
truth. No private, customer, or production document contributed content to these fixtures.

The corpus protects the boundary between deterministic preparation and probabilistic grouping:

```text
authored synthetic content -> offline PDF generation -> package DocumentPreparer / Poppler
                           -> manifest assertions      -> later opt-in grouping evaluation
```

The generator and manifest belong to the test corpus. The existing package remains responsible for
source lifetime, native parser isolation, page rendering, and cleanup. The manifest is not generated
by a grouping implementation and is never embedded in the PDF pages or metadata.

## Regenerate

From the repository root, using the project's supported PHP runtime:

```sh
php tests/Fixtures/Grouping/generate.php
```

The script uses only PHP and zlib. It deterministically writes all PDFs and then records their byte
sizes and SHA-256 hashes in `manifest.json`. `bundle-07.pdf` embeds deterministic grayscale page
images and has no text layer; it is actual image-only input rather than text labeled as a scan.

Neutral attachment filenames intentionally reveal no grouping answers. Natural document evidence
such as issuer, invoice identifier, and `Page N of M` remains visible. Blank pages stay unassigned.
An ambiguous page stays unassigned unless observable evidence supports an identity; the manifest
documents why it cannot be assigned rather than inventing a group.

Cases marked `holdout` must remain outside prompt-tuning examples. Do not expose holdout expected
labels while developing a prompt. Live quality scoring is deferred and must use explicit opt-in and
spend controls; these synthetic fixtures do not establish AI accuracy or real-world quality.

See the separately maintained [grouping model capability and pricing shortlist](../../../../docs/grouping-models.md)
for dated public-source research. It does not own or determine corpus truth.

## Limits

The fixtures are deliberately small and reproducible. They exercise physical page handling, text
layers, image-only pages, similar layouts, blank separators, and conservative ambiguity. They do not
represent handwriting, damage, unusual scripts, adversarial PDFs, broad layout diversity, or the
distribution and noise of real documents. Passing them proves corpus plumbing and declared labels,
not model quality or fitness for business confirmation.
