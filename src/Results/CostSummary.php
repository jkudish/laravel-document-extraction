<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Results;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class CostSummary implements Arrayable, JsonSerializable
{
    /** @var Collection<string, Money> */
    public Collection $knownByCurrency;

    /** @var Collection<int, string> */
    public Collection $unpricedCalls;

    /**
     * @param  iterable<string, Money>  $knownByCurrency
     * @param  iterable<int, string>  $unpricedCalls
     */
    public function __construct(
        iterable $knownByCurrency = [],
        iterable $unpricedCalls = [],
        public bool $complete = false,
        public EvidenceOrigin $evidenceOrigin = EvidenceOrigin::Live,
    ) {
        $this->knownByCurrency = collect($knownByCurrency);
        $this->unpricedCalls = collect($unpricedCalls)->values();
    }

    public static function unavailable(): self
    {
        return new self;
    }

    public static function none(EvidenceOrigin $evidenceOrigin = EvidenceOrigin::Live): self
    {
        return new self(complete: true, evidenceOrigin: $evidenceOrigin);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'known_by_currency' => $this->knownByCurrency
                ->map(static fn (Money $money): array => $money->toArray())
                ->all(),
            'unpriced_calls' => $this->unpricedCalls->all(),
            'complete' => $this->complete,
            'mode' => $this->evidenceOrigin->value,
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
            knownByCurrency: $this->knownByCurrency,
            unpricedCalls: $this->unpricedCalls,
            complete: $this->complete,
            evidenceOrigin: EvidenceOrigin::Simulated,
        );
    }

    public function asRecorded(): self
    {
        if (in_array($this->evidenceOrigin, [EvidenceOrigin::Simulated, EvidenceOrigin::Mixed], true)) {
            return $this;
        }

        return new self(
            knownByCurrency: $this->knownByCurrency,
            unpricedCalls: $this->unpricedCalls,
            complete: $this->complete,
            evidenceOrigin: EvidenceOrigin::Recorded,
        );
    }
}
