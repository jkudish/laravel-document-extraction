<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Results;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * @property-read array<string, mixed>|null $data
 * @property-read string|null $text
 */
final readonly class ExtractionResult
{
    /** @var Collection<int, DocumentResult> */
    public Collection $documents;

    /** @var Collection<int, PageResult> */
    public Collection $pages;

    /** @var Collection<int, CallRecord> */
    public Collection $calls;

    /** @var Collection<int, ExtractionError> */
    public Collection $errors;

    /**
     * @param  iterable<int, DocumentResult>  $documents
     * @param  iterable<int, PageResult>  $pages
     * @param  iterable<int, CallRecord>  $calls
     * @param  iterable<int, ExtractionError>  $errors
     */
    public function __construct(
        iterable $documents,
        public string $sourceSha256,
        public string $mediaType,
        public ?int $pageCount = null,
        iterable $pages = [],
        iterable $calls = [],
        iterable $errors = [],
        public CostSummary $cost = new CostSummary,
        public bool $detectionMode = false,
        private bool $coverageComplete = true,
        public EvidenceOrigin $evidenceOrigin = EvidenceOrigin::Live,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/', $sourceSha256) !== 1) {
            throw new InvalidArgumentException('Extraction results require a lowercase SHA-256 source identity.');
        }

        if (preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~', $mediaType) !== 1) {
            throw new InvalidArgumentException('Extraction results require a valid normalized media type.');
        }

        if ($pageCount !== null && $pageCount < 1) {
            throw new InvalidArgumentException('A known page count must be positive.');
        }

        $this->documents = collect($documents)->values();
        $this->pages = collect($pages)->values();
        $this->calls = collect($calls)->values();
        $this->errors = collect($errors)->values();

        foreach ($this->documents->concat($this->pages) as $outcome) {
            if ($outcome->error !== null && ! $this->errors->containsStrict($outcome->error)) {
                $this->errors->push($outcome->error);
            }
        }

        if (! $detectionMode && $this->documents->count() !== 1) {
            throw new InvalidArgumentException('Ordinary extraction results require exactly one document result.');
        }

        $this->validatePageProvenance();
    }

    public function __get(string $name): mixed
    {
        if ($name === 'data') {
            return $this->detectionMode ? null : $this->documents->first()?->data;
        }

        if ($name === 'text') {
            return $this->detectionMode ? null : $this->documents->first()?->text;
        }

        throw new InvalidArgumentException("Unknown extraction result property [{$name}].");
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['data', 'text'], true) && $this->__get($name) !== null;
    }

    public function complete(): bool
    {
        return $this->coverageComplete
            && $this->documents->isNotEmpty()
            && $this->errors->isEmpty()
            && $this->documents->every(static fn (DocumentResult $document): bool => $document->complete())
            && $this->pages->every(static fn (PageResult $page): bool => $page->complete());
    }

    public function asSimulated(): self
    {
        $calls = $this->calls->map(static fn (CallRecord $call): CallRecord => $call->asSimulated());
        $unpriced = $calls->map(
            static fn (CallRecord $call, int $index): string => $call->reference() ?? 'call:'.($index + 1),
        );

        return new self(
            documents: $this->documents,
            sourceSha256: $this->sourceSha256,
            mediaType: $this->mediaType,
            pageCount: $this->pageCount,
            pages: $this->pages,
            calls: $calls,
            errors: $this->errors,
            cost: new CostSummary(
                unpricedCalls: $unpriced,
                complete: $calls->isEmpty(),
                evidenceOrigin: EvidenceOrigin::Simulated,
            ),
            detectionMode: $this->detectionMode,
            coverageComplete: $this->coverageComplete,
            evidenceOrigin: EvidenceOrigin::Simulated,
        );
    }

    public function asRecorded(): self
    {
        if ($this->evidenceOrigin === EvidenceOrigin::Simulated) {
            return $this;
        }

        return new self(
            documents: $this->documents,
            sourceSha256: $this->sourceSha256,
            mediaType: $this->mediaType,
            pageCount: $this->pageCount,
            pages: $this->pages,
            calls: $this->calls->map(static fn (CallRecord $call): CallRecord => $call->asRecorded()),
            errors: $this->errors,
            cost: $this->cost->asRecorded(),
            detectionMode: $this->detectionMode,
            coverageComplete: $this->coverageComplete,
            evidenceOrigin: EvidenceOrigin::Recorded,
        );
    }

    private function validatePageProvenance(): void
    {
        $resultPages = [];
        $documentPages = [];

        foreach ($this->documents as $document) {
            if ($this->pageCount === null && $document->pages !== null) {
                throw new InvalidArgumentException('Unpaginated results cannot contain document page numbers.');
            }

            if ($this->pageCount !== null && $document->pages === null) {
                throw new InvalidArgumentException('Paginated document results require original page provenance.');
            }

            foreach ($document->pages ?? [] as $page) {
                if ($this->pageCount !== null && $page > $this->pageCount) {
                    throw new InvalidArgumentException('Document page provenance exceeds the source page count.');
                }

                if (isset($documentPages[$page])) {
                    throw new InvalidArgumentException('Document page provenance must not overlap between documents.');
                }

                $documentPages[$page] = true;
            }
        }

        if ($this->pageCount === null && $this->pages->isNotEmpty()) {
            throw new InvalidArgumentException('Unpaginated results cannot contain physical page results.');
        }

        foreach ($this->pages as $pageResult) {
            $this->validatePageReference($pageResult->page, 'A page result');

            if (isset($resultPages[$pageResult->page])) {
                throw new InvalidArgumentException('Physical page results must have unique page numbers.');
            }

            $resultPages[$pageResult->page] = true;
        }

        foreach ($this->calls as $call) {
            foreach ($call->pages as $page) {
                $this->validatePageReference($page, 'Call provenance');
            }
        }

        foreach ($this->errors as $error) {
            foreach ($error->pages as $page) {
                $this->validatePageReference($page, 'Error provenance');
            }
        }
    }

    private function validatePageReference(int $page, string $owner): void
    {
        if ($this->pageCount === null) {
            throw new InvalidArgumentException("{$owner} cannot contain page numbers for unpaginated content.");
        }

        if ($page > $this->pageCount) {
            throw new InvalidArgumentException("{$owner} exceeds the source page count.");
        }
    }
}
