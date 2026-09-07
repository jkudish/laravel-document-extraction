<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Results;

use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Usage;

/** @implements Arrayable<string, mixed> */
final readonly class CallRecord implements Arrayable, JsonSerializable
{
    /** @var Collection<int, int> */
    public Collection $pages;

    public ?string $resolvedProvider;

    public ?string $resolvedModel;

    /**
     * @param  iterable<int, mixed>  $pages
     */
    public function __construct(
        public string $stage,
        public string $outcome,
        iterable $pages = [],
        public ?string $provider = null,
        public ?string $model = null,
        public ?int $durationMilliseconds = null,
        public ?CostQuote $cost = null,
        public EvidenceOrigin $evidenceOrigin = EvidenceOrigin::Live,
        public ?string $extractionInvocationId = null,
        public ?string $nativeInvocationId = null,
        public ?int $ordinal = null,
        public ?string $requestedProvider = null,
        public ?string $requestedModel = null,
        public ?string $effectiveProvider = null,
        public ?string $effectiveModel = null,
        public ?Usage $usage = null,
        public ?DateTimeImmutable $startedAt = null,
    ) {
        if (trim($stage) === '' || trim($outcome) === '') {
            throw new InvalidArgumentException('Call records require a stage and outcome.');
        }

        if ($durationMilliseconds !== null && $durationMilliseconds < 0) {
            throw new InvalidArgumentException('Call duration cannot be negative.');
        }

        if ($evidenceOrigin === EvidenceOrigin::Mixed) {
            throw new InvalidArgumentException('An individual call must have one evidence origin.');
        }

        $this->validateAttemptIdentity();
        $this->validateModelIdentity($provider, $model, 'resolved');
        $this->validateModelIdentity($requestedProvider, $requestedModel, 'requested');
        $this->validateModelIdentity($effectiveProvider, $effectiveModel, 'effective');
        $this->resolvedProvider = $provider;
        $this->resolvedModel = $model;

        $normalized = [];

        foreach ($pages as $page) {
            if (! is_int($page) || $page < 1 || isset($normalized[$page])) {
                throw new InvalidArgumentException('Call pages must be unique positive integers.');
            }

            $normalized[$page] = true;
        }

        $values = array_keys($normalized);
        sort($values, SORT_NUMERIC);
        /** @var Collection<int, int> $pageCollection */
        $pageCollection = collect($values)->values();
        $this->pages = $pageCollection;
    }

    public function reference(): ?string
    {
        return $this->extractionInvocationId !== null && $this->ordinal !== null
            ? $this->extractionInvocationId.':'.$this->ordinal
            : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference(),
            'extraction_invocation_id' => $this->extractionInvocationId,
            'native_invocation_id' => $this->nativeInvocationId,
            'ordinal' => $this->ordinal,
            'stage' => $this->stage,
            'pages' => $this->pages->all(),
            'requested_identity' => $this->identity($this->requestedProvider, $this->requestedModel),
            'resolved_identity' => $this->identity($this->resolvedProvider, $this->resolvedModel),
            'effective_identity' => $this->identity($this->effectiveProvider, $this->effectiveModel),
            'usage' => $this->usage?->toArray(),
            'started_at' => $this->startedAt?->format(DATE_RFC3339_EXTENDED),
            'duration_milliseconds' => $this->durationMilliseconds,
            'outcome' => $this->outcome,
            'mode' => $this->evidenceOrigin->value,
            'cost_quote' => $this->cost?->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function asSimulated(): self
    {
        return new self(
            stage: $this->stage,
            outcome: $this->outcome,
            pages: $this->pages,
            provider: $this->provider,
            model: $this->model,
            durationMilliseconds: $this->durationMilliseconds,
            cost: null,
            evidenceOrigin: EvidenceOrigin::Simulated,
            extractionInvocationId: $this->extractionInvocationId,
            nativeInvocationId: $this->nativeInvocationId,
            ordinal: $this->ordinal,
            requestedProvider: $this->requestedProvider,
            requestedModel: $this->requestedModel,
            effectiveProvider: $this->effectiveProvider,
            effectiveModel: $this->effectiveModel,
            usage: null,
            startedAt: $this->startedAt,
        );
    }

    public function asRecorded(): self
    {
        if ($this->evidenceOrigin === EvidenceOrigin::Simulated) {
            return $this;
        }

        return new self(
            stage: $this->stage,
            outcome: $this->outcome,
            pages: $this->pages,
            provider: $this->provider,
            model: $this->model,
            durationMilliseconds: $this->durationMilliseconds,
            cost: $this->cost,
            evidenceOrigin: EvidenceOrigin::Recorded,
            extractionInvocationId: $this->extractionInvocationId,
            nativeInvocationId: $this->nativeInvocationId,
            ordinal: $this->ordinal,
            requestedProvider: $this->requestedProvider,
            requestedModel: $this->requestedModel,
            effectiveProvider: $this->effectiveProvider,
            effectiveModel: $this->effectiveModel,
            usage: $this->usage,
            startedAt: $this->startedAt,
        );
    }

    private function validateAttemptIdentity(): void
    {
        $values = [$this->extractionInvocationId, $this->nativeInvocationId, $this->ordinal];
        $present = count(array_filter($values, static fn (mixed $value): bool => $value !== null));

        if ($present !== 0 && $present !== count($values)) {
            throw new InvalidArgumentException('Call attempt identity requires extraction id, native id, and ordinal together.');
        }

        if (($this->extractionInvocationId !== null && trim($this->extractionInvocationId) === '')
            || ($this->nativeInvocationId !== null && trim($this->nativeInvocationId) === '')
            || ($this->ordinal !== null && $this->ordinal < 1)) {
            throw new InvalidArgumentException('Call attempt identity values must be non-empty and the ordinal must be positive.');
        }
    }

    private function validateModelIdentity(?string $provider, ?string $model, string $label): void
    {
        if (($provider === null) !== ($model === null)) {
            throw new InvalidArgumentException("Call {$label} provider and model must both be known or both be unknown.");
        }

        if (($provider !== null && trim($provider) === '') || ($model !== null && trim($model) === '')) {
            throw new InvalidArgumentException("Call {$label} provider and model must not be blank.");
        }
    }

    /** @return array{provider: string, model: string}|null */
    private function identity(?string $provider, ?string $model): ?array
    {
        return $provider !== null && $model !== null
            ? ['provider' => $provider, 'model' => $model]
            : null;
    }
}
