<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Results;

use Illuminate\Support\Collection;
use Jkudish\LaravelAiPricing\ValueObjects\Money;

final readonly class CostSummary
{
    /** @var Collection<string, Money> */
    public Collection $knownByCurrency;

    /** @var Collection<int, int> */
    public Collection $unpricedCalls;

    /**
     * @param  iterable<string, Money>  $knownByCurrency
     * @param  iterable<int, int>  $unpricedCalls
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

    public function asSimulated(): self
    {
        return new self(
            knownByCurrency: $this->knownByCurrency,
            unpricedCalls: $this->unpricedCalls,
            complete: $this->complete,
            evidenceOrigin: EvidenceOrigin::Simulated,
        );
    }
}
