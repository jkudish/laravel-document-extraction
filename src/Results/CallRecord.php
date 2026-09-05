<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Results;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;

final readonly class CallRecord
{
    /** @var Collection<int, int> */
    public Collection $pages;

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
    ) {
        if (trim($stage) === '' || trim($outcome) === '') {
            throw new InvalidArgumentException('Call records require a stage and outcome.');
        }

        if ($durationMilliseconds !== null && $durationMilliseconds < 0) {
            throw new InvalidArgumentException('Call duration cannot be negative.');
        }

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

    public function asSimulated(): self
    {
        return new self(
            stage: $this->stage,
            outcome: $this->outcome,
            pages: $this->pages,
            provider: $this->provider,
            model: $this->model,
            durationMilliseconds: $this->durationMilliseconds,
            cost: $this->cost,
            evidenceOrigin: EvidenceOrigin::Simulated,
        );
    }
}
