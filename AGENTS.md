# Laravel Document Extraction

This is a synchronous Laravel package, not a full application. Follow the existing
`src/`, `config/`, `resources/`, and Pest/Testbench layout. Read [README.md](README.md)
for the public contract and [docs/verification.md](docs/verification.md) before testing,
changing native-runtime behavior, or delivering a PR. PlanMode Plan
`laravel-document-extraction-package` owns accepted scope and decisions.

## Prefer the ordinary Laravel solution

- Load `laravel-best-practices` and read the affected rule files; use `testing-laravel`
  for tests. Verify version-sensitive APIs against installed dependencies.
- Consistency first: follow the owning module's conventions. Do not import another
  project's directories or introduce a second pattern for the same responsibility.
- Use the existing service provider/container, Laravel configuration, Storage,
  Process, Image, collections, and native AI agents, schemas, options and fallback.
  Prefer concrete dependency injection; add bindings/interfaces when actually needed.
- In consumer examples, use Eloquent relationships/scopes/casts, Policies, appropriate
  validation, controllers, resources and transactions where they own the behavior.
  Do not add these application layers to this package merely to resemble an app:
  persistence, authorization, queues, idempotency and business confirmation stay with consumers.
- Extract a focused business operation when it clarifies responsibility, removes
  meaningful duplication or improves testability—not one layer or class per operation.
  Every new abstraction, DTO, table, state, dependency or asynchronous step must solve
  a current requirement. No arbitrary class-size limits or blanket abstraction bans.
- Use `config/extraction.php` for deployment-controlled settings, `env()` only in
  configuration, and the existing per-invocation configuration snapshot. Do not invent
  registries, activation workflows or shared mutable tenant configuration for these settings.

## Explain ownership before adding concepts

Before substantial proposals, delegation and delivery, show the normal execution flow,
its owners and failure/cleanup path; justify each new concept against that flow and
explain necessary departures from native APIs. Put this requirement in worker briefs.
The current path is `Extraction` / `PendingExtraction` → `DocumentExtraction`
(validation and source/workspace lifetime) → `DocumentPreparer` (bounded preparation)
→ `NativeAiProcessor` / `NativeAiExecutor` (native calls, validation and attempt evidence)
→ existing result objects. Extend these owners before adding a parallel pipeline.
Keep accepted behavior; remove unnecessary ceremony, not necessary safeguards.

## Safeguards and delivery

- Preserve original-source custody, original-page provenance, local validation of
  lossless JSON, resource limits, safe partial results and Fiber/invocation isolation.
  Gateway decoration exists for native SDK enforcement gaps; do not replace the actual
  application agent or add a custom retry framework. The accepted middleware exclusion
  is only same-agent prompting before forwarding the outer invocation (Plan D22).
- Keep original pricing quotes, exact per-currency Money totals and unknown/simulated/
  recorded evidence distinct. Do not double-count calls or claim estimates are invoices.
- Schema validity and complete page coverage do not establish factual accuracy or
  business confirmation. Preserve this distinction in results, examples and benchmarks.
- Do not put source documents, extracted text, prompts or raw provider responses in logs
  or exception context. Prefer identifiers and bounded, non-content metadata.
- Use native fakes and prevent stray HTTP in offline tests. Keep live quality/spend
  evaluation separate. Do not print secrets or inspect generated Pest/PHPStan caches.
- Use credential-free child environments for PHP/tests; `composer analyse` and
  `composer pr:check` provide isolation. `pr:check` requires a clean committed target
  and includes full `--no-tia` tests, PHP/Laravel matrix, Larastan 10, Pint and safeguards.
  Follow the verification guide; TIA replay never substitutes for delivery evidence.
- Preserve `.agents/setup` as idempotent provisioning and `.agents/resume` as a fast
  readiness check, not an installer. No hosted CI, paid calls, private-document egress,
  releases or production changes without applicable explicit authority. Recheck the
  exact target and current authorization before dispatch, push, signoff or merge.
