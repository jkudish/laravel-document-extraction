<?php

declare(strict_types=1);

use Jkudish\DocumentExtraction\AI\AiExecutionSession;
use Jkudish\DocumentExtraction\Preparation\Deadline;
use Jkudish\LaravelAiPricing\Adapters\LaravelAiObservationAdapter;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Enums\PricingSource;
use Jkudish\LaravelAiPricing\ResponseCostResolver;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

it('prices each returned step once and sums exact subtotals independently by currency', function (): void {
    $quotes = [
        new CostQuote(new Money('1.000000000000000001', 'USD'), CostCompleteness::Complete, PricingSource::Configured),
        new CostQuote(new Money('0.000000000000000009', 'USD'), CostCompleteness::Complete, PricingSource::Configured),
        new CostQuote(new Money('2.500000000000000003', 'CAD'), CostCompleteness::Partial, PricingSource::Configured, missingUnits: ['output_tokens']),
    ];
    $resolver = new class($quotes) implements CostResolver
    {
        /** @param list<CostQuote> $quotes */
        public function __construct(private array $quotes) {}

        public int $calls = 0;

        public function resolve(PricingObservation $observation): CostQuote
        {
            $quote = $this->quotes[$this->calls] ?? throw new RuntimeException('Unexpected pricing call.');
            $this->calls++;

            return $quote;
        }
    };
    $session = new AiExecutionSession(
        invocationId: 'extraction-1',
        deadline: Deadline::afterSeconds(10),
        attemptLimit: 3,
        attemptTimeout: 5,
        outputLimit: 1000,
        attachmentLimit: 1000,
        pricing: new ResponseCostResolver($resolver, new LaravelAiObservationAdapter),
    );
    $responses = [
        new StepResponse('one', [], FinishReason::Stop, new Usage(1), new Meta('provider', 'model')),
        new StepResponse('two', [], FinishReason::Stop, new Usage(1), new Meta('provider', 'model')),
        new StepResponse('three', [], FinishReason::Stop, new Usage(1), new Meta('provider', 'model')),
    ];

    foreach ($responses as $index => $response) {
        $attempt = $session->beginAttempt();
        $session->record(
            stage: 'extraction',
            pages: [1],
            nativeInvocationId: 'native-1',
            ordinal: $attempt['ordinal'],
            requestedProvider: 'provider',
            requestedModel: 'model',
            resolvedProvider: 'provider',
            resolvedModel: 'model',
            outcome: 'succeeded',
            durationMilliseconds: 1,
            startedAt: new DateTimeImmutable('2026-09-06T00:00:00+00:00'),
            response: $response,
        );

        expect($session->calls()[$index]->cost)->toBe($quotes[$index]);
    }

    $summary = $session->costSummary();

    expect($resolver->calls)->toBe(3)
        ->and($session->calls())->toHaveCount(3)
        ->and(array_map(static fn ($call): int => $call->ordinal ?? throw new LogicException('Missing ordinal.'), $session->calls()))->toBe([1, 2, 3])
        ->and((string) $summary->knownByCurrency->get('USD')?->amount)->toBe('1.000000000000000010')
        ->and((string) $summary->knownByCurrency->get('CAD')?->amount)->toBe('2.500000000000000003')
        ->and($summary->unpricedCalls->all())->toBe(['extraction-1:3'])
        ->and($summary->complete)->toBeFalse();
});
