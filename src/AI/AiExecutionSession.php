<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use DateTimeImmutable;
use Jkudish\DocumentExtraction\Exceptions\AiExecutionException;
use Jkudish\DocumentExtraction\Exceptions\PreparationException;
use Jkudish\DocumentExtraction\Preparation\Deadline;
use Jkudish\DocumentExtraction\Results\CallRecord;
use Jkudish\DocumentExtraction\Results\CostSummary;
use Jkudish\DocumentExtraction\Results\EvidenceOrigin;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\ResponseCostResolver;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\Usage;
use Throwable;

final class AiExecutionSession
{
    private int $attempts = 0;

    private int $retainedBytes;

    /** @var list<CallRecord> */
    private array $calls = [];

    public function __construct(
        public readonly string $invocationId,
        private readonly Deadline $deadline,
        private readonly int $attemptLimit,
        private readonly int $attemptTimeout,
        public readonly int $outputLimit,
        public readonly int $attachmentLimit,
        private readonly ResponseCostResolver $pricing,
        public readonly EvidenceOrigin $evidenceOrigin = EvidenceOrigin::Live,
        int $initialRetainedBytes = 0,
    ) {
        if (trim($this->invocationId) === '') {
            throw new \InvalidArgumentException('An AI execution session requires an invocation identity.');
        }

        $this->retainedBytes = $initialRetainedBytes;
    }

    /** @return array{ordinal: int, remaining_seconds: int} */
    public function beginAttempt(): array
    {
        if ($this->attempts >= $this->attemptLimit) {
            throw AiExecutionException::make(
                'ai_attempt_limit_exceeded',
                'The extraction exhausted its configured AI attempt budget.',
            );
        }

        $remaining = $this->remainingSeconds();
        $this->attempts++;

        return ['ordinal' => $this->attempts, 'remaining_seconds' => $remaining];
    }

    public function remainingSeconds(): int
    {
        try {
            return $this->deadline->remainingSeconds($this->attemptTimeout);
        } catch (PreparationException $exception) {
            throw AiExecutionException::make(
                $exception->errorCode,
                'The extraction invocation deadline was exceeded.',
                $exception,
            );
        }
    }

    public function replaceRetained(string $previous, string $replacement): void
    {
        $this->retainedBytes -= strlen($previous);
        $this->retain($replacement);
    }

    public function retain(string $output): void
    {
        if (! mb_check_encoding($output, 'UTF-8')) {
            throw new InvalidAiOutputException('The provider returned output that is not valid UTF-8.');
        }

        $bytes = strlen($output);

        if ($bytes > $this->outputLimit - $this->retainedBytes) {
            throw AiExecutionException::make(
                'output_limit_exceeded',
                'AI extraction exceeded the configured aggregate output byte limit.',
            );
        }

        $this->retainedBytes += $bytes;
    }

    public function ensureFinalOutputFits(string $output): void
    {
        if (! mb_check_encoding($output, 'UTF-8') || strlen($output) > $this->outputLimit) {
            throw AiExecutionException::make(
                'output_limit_exceeded',
                'AI extraction exceeded the configured aggregate output byte limit.',
            );
        }
    }

    /**
     * @param  list<int>  $pages
     */
    public function record(
        string $stage,
        array $pages,
        string $nativeInvocationId,
        int $ordinal,
        ?string $requestedProvider,
        ?string $requestedModel,
        string $resolvedProvider,
        string $resolvedModel,
        string $outcome,
        int $durationMilliseconds,
        DateTimeImmutable $startedAt,
        ?StepResponse $response,
    ): void {
        [$effectiveProvider, $effectiveModel] = $this->effectiveIdentity($response);
        $usage = $this->usage($response);
        $cost = $this->cost($response, $effectiveProvider, $effectiveModel);

        $this->calls[] = new CallRecord(
            stage: $stage,
            outcome: $outcome,
            pages: $pages,
            provider: $resolvedProvider,
            model: $resolvedModel,
            durationMilliseconds: $durationMilliseconds,
            cost: $cost,
            evidenceOrigin: $this->evidenceOrigin,
            extractionInvocationId: $this->invocationId,
            nativeInvocationId: $nativeInvocationId,
            ordinal: $ordinal,
            requestedProvider: $requestedProvider,
            requestedModel: $requestedModel,
            effectiveProvider: $effectiveProvider,
            effectiveModel: $effectiveModel,
            usage: $usage,
            startedAt: $startedAt,
        );
    }

    /** @return list<CallRecord> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function costSummary(): CostSummary
    {
        if ($this->calls === []) {
            return CostSummary::none($this->evidenceOrigin);
        }

        /** @var array<string, Money> $known */
        $known = [];
        $unpriced = [];

        foreach ($this->calls as $index => $call) {
            $quote = $call->cost;

            if ($quote?->cost !== null) {
                $currency = $quote->cost->currency;
                $known[$currency] = isset($known[$currency])
                    ? $known[$currency]->plus($quote->cost)
                    : $quote->cost;
            }

            if ($call->evidenceOrigin !== EvidenceOrigin::Live
                || $call->usage === null
                || $quote === null
                || $quote->cost === null
                || $quote->completeness !== CostCompleteness::Complete) {
                $unpriced[] = $call->reference() ?? 'call:'.($index + 1);
            }
        }

        return new CostSummary(
            knownByCurrency: $known,
            unpricedCalls: $unpriced,
            complete: $unpriced === [],
            evidenceOrigin: $this->evidenceOrigin,
        );
    }

    /** @return array{?string, ?string} */
    private function effectiveIdentity(?StepResponse $response): array
    {
        $provider = $response?->meta->provider;
        $model = $response?->meta->model;

        if (! is_string($provider) || trim($provider) === '' || ! is_string($model) || trim($model) === '') {
            return [null, null];
        }

        return [$provider, $model];
    }

    private function usage(?StepResponse $response): ?Usage
    {
        if ($response === null) {
            return null;
        }

        $usage = $response->usage;
        $values = [
            $usage->promptTokens,
            $usage->completionTokens,
            $usage->cacheWriteInputTokens,
            $usage->cacheReadInputTokens,
            $usage->reasoningTokens,
        ];

        if (min($values) < 0 || max($values) === 0) {
            // Laravel AI defaults every usage field to zero. Without a positive
            // unit there is no portable signal that a provider measured usage.
            return null;
        }

        return new Usage(...$values);
    }

    private function cost(
        ?StepResponse $response,
        ?string $effectiveProvider,
        ?string $effectiveModel,
    ): ?CostQuote {
        if ($response === null || $this->evidenceOrigin !== EvidenceOrigin::Live) {
            return null;
        }

        try {
            $quote = $this->pricing->cost($response);
        } catch (Throwable) {
            return null;
        }

        // Pricing must not substitute the requested route when the provider did
        // not report a complete effective identity for the returned response.
        return $effectiveProvider !== null && $effectiveModel !== null ? $quote : null;
    }
}
