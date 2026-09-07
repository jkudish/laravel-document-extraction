<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Jkudish\DocumentExtraction\AI\NativeAiBridge;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Results\EvidenceOrigin;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Enums\PricingSource;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\StructuredTextResponse;

final class PricingEvidenceAgent implements Agent, HasMiddleware, HasStructuredOutput
{
    use Promptable;

    /** @param array<mixed> $middleware */
    public function __construct(private readonly array $middleware = []) {}

    public function instructions(): string
    {
        return 'Extract the requested value.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['value' => $schema->string()->required()];
    }

    /** @return array<mixed> */
    public function middleware(): array
    {
        return $this->middleware;
    }
}

final class PricingResponseMiddleware
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $response = $next($prompt);

        if (! $response instanceof StructuredAgentResponse) {
            throw new LogicException('Pricing test middleware expected a structured response.');
        }

        $response->text = '{"value":"middleware"}';

        return $response;
    }
}

final class EvidenceStepGateway implements StepTextGateway
{
    /** @param list<StepResponse|Throwable> $responses */
    public function __construct(private array $responses) {}

    public int $dispatches = 0;

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $this->dispatches++;
        $response = array_shift($this->responses);

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response ?? throw new RuntimeException('The evidence gateway has no response.');
    }

    /**
     * @param  Message[]  $messages
     * @return Generator<int, mixed, mixed, StepResponse|null>
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        throw new LogicException('Streaming is outside package extraction.');
    }
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('ai.providers.anthropic.key', 'test-key');
    config()->set('ai-pricing.offline', true);
    config()->set('ai-pricing.prices', []);
});

function evidenceStep(
    string $text,
    Usage $usage,
    ?string $provider = 'effective-provider',
    ?string $model = 'effective-model',
    FinishReason $finishReason = FinishReason::Stop,
): StepResponse {
    return new StepResponse(
        text: $text,
        toolCalls: [],
        finishReason: $finishReason,
        usage: $usage,
        meta: new Meta($provider, $model),
        structured: $finishReason === FinishReason::Stop && str_starts_with($text, '{')
            ? ['value' => 'normalized']
            : null,
        continuationToken: $finishReason === FinishReason::Continue ? 'continue-token' : null,
    );
}

function installEvidenceGateway(EvidenceStepGateway $gateway): void
{
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use ($gateway): void {
        $event->prompt->provider()->useTextGateway($gateway);
    });

    // Register package attribution after the gateway installer so it decorates
    // the controlled provider boundary rather than being replaced by it.
    app(NativeAiBridge::class);
}

/** @param array<string, array{amount: string, per?: string, currency?: string}> $rates */
function configureEvidencePrice(array $rates): void
{
    config()->set('ai-pricing.prices', ['effective-provider:effective-model' => $rates]);
}

it('records and prices one returned native attempt with isolated identities and provenance', function (): void {
    configureEvidencePrice([
        'input_tokens' => ['amount' => '0.100000000000000001'],
        'output_tokens' => ['amount' => '0.010000000000000001'],
        'reasoning_tokens' => ['amount' => '0.001'],
    ]);
    $gateway = new EvidenceStepGateway([
        evidenceStep('{"value":"provider"}', new Usage(2, 3, 0, 0, 4)),
    ]);
    installEvidenceGateway($gateway);

    $result = app(DocumentExtraction::class)
        ->fromString('private-source-that-must-not-enter-evidence', 'text/plain')
        ->using(new PricingEvidenceAgent)
        ->extract('openai', 'requested-model');
    $call = $result->calls->sole();

    expect($gateway->dispatches)->toBe(1)
        ->and($result->data)->toBe(['value' => 'provider'])
        ->and($call->ordinal)->toBe(1)
        ->and($call->extractionInvocationId)->not->toBeNull()
        ->and($call->nativeInvocationId)->not->toBeNull()
        ->and($call->reference())->toBe($call->extractionInvocationId.':1')
        ->and([$call->requestedProvider, $call->requestedModel])->toBe(['openai', 'requested-model'])
        ->and([$call->resolvedProvider, $call->resolvedModel])->toBe(['openai', 'requested-model'])
        ->and([$call->effectiveProvider, $call->effectiveModel])->toBe(['effective-provider', 'effective-model'])
        ->and($call->usage?->toArray())->toBe([
            'prompt_tokens' => 2,
            'completion_tokens' => 3,
            'cache_write_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'reasoning_tokens' => 4,
        ])
        ->and($call->startedAt)->not->toBeNull()
        ->and($call->durationMilliseconds)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($call->evidenceOrigin)->toBe(EvidenceOrigin::Live)
        ->and($call->cost?->completeness)->toBe(CostCompleteness::Complete)
        ->and($call->cost?->source)->toBe(PricingSource::Configured)
        ->and((string) $call->cost?->cost?->amount)->toBe('0.234000000000000005')
        ->and($call->cost?->toArray())->toBe($call->toArray()['cost_quote'])
        ->and((string) $result->cost->knownByCurrency->get('USD')?->amount)->toBe('0.234000000000000005')
        ->and($result->cost->complete)->toBeTrue()
        ->and($result->cost->unpricedCalls)->toBeEmpty();

    expect(json_encode($call, JSON_THROW_ON_ERROR))
        ->not->toContain('private-source-that-must-not-enter-evidence')
        ->not->toContain('test-key')
        ->not->toContain('provider-body');
    Http::assertNothingSent();
});

it('retains usage and a quote once when locally invalid returned JSON is rejected', function (): void {
    configureEvidencePrice([
        'input_tokens' => ['amount' => '0.1'],
        'output_tokens' => ['amount' => '0.01'],
    ]);
    $gateway = new EvidenceStepGateway([
        evidenceStep('not-json', new Usage(2, 3)),
    ]);
    installEvidenceGateway($gateway);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new PricingEvidenceAgent)
        ->extract('openai', 'requested-model');
    $call = $result->calls->sole();

    expect($gateway->dispatches)->toBe(1)
        ->and($result->complete())->toBeFalse()
        ->and($result->data)->toBeNull()
        ->and($call->outcome)->toBe('invalid_output')
        ->and($call->usage?->promptTokens)->toBe(2)
        ->and((string) $call->cost?->cost?->amount)->toBe('0.23')
        ->and($result->cost->complete)->toBeTrue();
});

it('preserves valid extraction when effective identity or catalog pricing is unavailable', function (bool $effectiveIdentity): void {
    $gateway = new EvidenceStepGateway([
        evidenceStep(
            '{"value":"safe"}',
            new Usage(2, 3),
            $effectiveIdentity ? 'effective-provider' : null,
            $effectiveIdentity ? 'effective-model' : null,
        ),
    ]);
    installEvidenceGateway($gateway);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new PricingEvidenceAgent)
        ->extract('openai', 'requested-model');
    $call = $result->calls->sole();

    expect($result->data)->toBe(['value' => 'safe'])
        ->and($result->complete())->toBeTrue()
        ->and($call->effectiveProvider)->toBe($effectiveIdentity ? 'effective-provider' : null)
        ->and($call->effectiveModel)->toBe($effectiveIdentity ? 'effective-model' : null)
        ->and($call->cost?->completeness)->toBe($effectiveIdentity ? CostCompleteness::Unavailable : null)
        ->and($result->cost->knownByCurrency)->toBeEmpty()
        ->and($result->cost->unpricedCalls->all())->toBe([$call->reference()])
        ->and($result->cost->complete)->toBeFalse();
    Http::assertNothingSent();
})->with(['catalog unavailable' => true, 'effective identity unknown' => false]);

it('retains an exact partial subtotal while marking its call unpriced', function (): void {
    configureEvidencePrice([
        'input_tokens' => ['amount' => '0.100000000000000001'],
    ]);
    $gateway = new EvidenceStepGateway([
        evidenceStep('{"value":"safe"}', new Usage(2, 3)),
    ]);
    installEvidenceGateway($gateway);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new PricingEvidenceAgent)
        ->extract('openai', 'requested-model');
    $call = $result->calls->sole();

    expect($result->data)->toBe(['value' => 'safe'])
        ->and($call->cost?->completeness)->toBe(CostCompleteness::Partial)
        ->and($call->cost?->missingUnits)->toBe(['output_tokens'])
        ->and((string) $call->cost?->cost?->amount)->toBe('0.200000000000000002')
        ->and((string) $result->cost->knownByCurrency->get('USD')?->amount)->toBe('0.200000000000000002')
        ->and($result->cost->unpricedCalls->all())->toBe([$call->reference()])
        ->and($result->cost->complete)->toBeFalse();
});

it('does not report a false zero when native usage is the undocumented all-zero default', function (): void {
    configureEvidencePrice([
        'input_tokens' => ['amount' => '0.1'],
        'output_tokens' => ['amount' => '0.01'],
    ]);
    $gateway = new EvidenceStepGateway([
        evidenceStep('{"value":"safe"}', new Usage),
    ]);
    installEvidenceGateway($gateway);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new PricingEvidenceAgent)
        ->extract('openai', 'requested-model');
    $call = $result->calls->sole();

    expect($result->data)->toBe(['value' => 'safe'])
        ->and($call->usage)->toBeNull()
        ->and($call->cost?->completeness)->toBe(CostCompleteness::Unavailable)
        ->and($call->cost?->cost)->toBeNull()
        ->and($result->cost->unpricedCalls->all())->toBe([$call->reference()])
        ->and($result->cost->complete)->toBeFalse();
});

it('keeps mixed known and failed-without-response spend honest across fallback', function (): void {
    configureEvidencePrice([
        'input_tokens' => ['amount' => '0.1'],
        'output_tokens' => ['amount' => '0.01'],
    ]);
    $gateway = new EvidenceStepGateway([
        ProviderConnectionException::forProvider('openai'),
        evidenceStep('{"value":"fallback"}', new Usage(2, 3)),
    ]);
    installEvidenceGateway($gateway);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new PricingEvidenceAgent)
        ->extract(['openai' => 'first-model', 'anthropic' => 'second-model']);
    [$failed, $succeeded] = $result->calls->all();

    expect($gateway->dispatches)->toBe(2)
        ->and($result->data)->toBe(['value' => 'fallback'])
        ->and([$failed->ordinal, $succeeded->ordinal])->toBe([1, 2])
        ->and($failed->nativeInvocationId)->toBe($succeeded->nativeInvocationId)
        ->and([$failed->requestedProvider, $succeeded->requestedProvider])->toBe(['openai', 'anthropic'])
        ->and($failed->outcome)->toBe('failed')
        ->and($failed->usage)->toBeNull()
        ->and($failed->effectiveProvider)->toBeNull()
        ->and($failed->cost)->toBeNull()
        ->and($succeeded->outcome)->toBe('succeeded')
        ->and((string) $result->cost->knownByCurrency->get('USD')?->amount)->toBe('0.23')
        ->and($result->cost->unpricedCalls->all())->toBe([$failed->reference()])
        ->and($result->cost->complete)->toBeFalse();
});

it('prices continuation steps exactly once without pricing the aggregate response again', function (): void {
    configureEvidencePrice([
        'input_tokens' => ['amount' => '0.1'],
        'output_tokens' => ['amount' => '0.01'],
    ]);
    $gateway = new EvidenceStepGateway([
        evidenceStep('continued', new Usage(1, 2), finishReason: FinishReason::Continue),
        evidenceStep('{"value":"done"}', new Usage(3, 4)),
    ]);
    installEvidenceGateway($gateway);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new PricingEvidenceAgent)
        ->extract('openai', 'requested-model');
    [$continued, $finished] = $result->calls->all();

    expect($gateway->dispatches)->toBe(2)
        ->and($result->calls)->toHaveCount(2)
        ->and($result->calls->pluck('ordinal')->all())->toBe([1, 2])
        ->and($result->calls->pluck('outcome')->all())->toBe(['continued', 'succeeded'])
        ->and($continued->nativeInvocationId)->toBe($finished->nativeInvocationId)
        ->and((string) $continued->cost?->cost?->amount)->toBe('0.12')
        ->and((string) $finished->cost?->cost?->amount)->toBe('0.34')
        ->and((string) $result->cost->knownByCurrency->get('USD')?->amount)->toBe('0.46')
        ->and($result->cost->complete)->toBeTrue();
});

it('keeps provider pricing evidence authoritative across post-forward middleware edits', function (): void {
    configureEvidencePrice([
        'input_tokens' => ['amount' => '0.1'],
        'output_tokens' => ['amount' => '0.01'],
    ]);
    $gateway = new EvidenceStepGateway([
        evidenceStep('{"value":"provider"}', new Usage(2, 3)),
    ]);
    installEvidenceGateway($gateway);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new PricingEvidenceAgent([new PricingResponseMiddleware]))
        ->extract('openai', 'requested-model');
    $call = $result->calls->sole();

    expect($result->data)->toBe(['value' => 'middleware'])
        ->and($call->usage?->toArray())->toBe((new Usage(2, 3))->toArray())
        ->and((string) $call->cost?->cost?->amount)->toBe('0.23')
        ->and($result->calls)->toHaveCount(1);
});

it('marks native agent fakes as simulated and never prices their synthetic usage', function (): void {
    configureEvidencePrice([
        'input_tokens' => ['amount' => '0.1'],
        'output_tokens' => ['amount' => '0.01'],
    ]);
    PricingEvidenceAgent::fake([new StructuredTextResponse(
        ['value' => 'fake'],
        '{"value":"fake"}',
        new Usage(2, 3),
        new Meta('effective-provider', 'effective-model'),
    )])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new PricingEvidenceAgent)
        ->extract('openai', 'requested-model');
    $call = $result->calls->sole();

    expect($result->evidenceOrigin)->toBe(EvidenceOrigin::Simulated)
        ->and($call->evidenceOrigin)->toBe(EvidenceOrigin::Simulated)
        ->and($call->usage?->promptTokens)->toBe(2)
        ->and($call->cost)->toBeNull()
        ->and($result->cost->knownByCurrency)->toBeEmpty()
        ->and($result->cost->unpricedCalls->all())->toBe([$call->reference()])
        ->and($result->cost->complete)->toBeFalse();
    Http::assertNothingSent();
});

it('reports deterministic and middleware-only execution as zero actual calls and complete zero spend', function (bool $shortCircuit): void {
    if ($shortCircuit) {
        $middleware = new class
        {
            public function handle(AgentPrompt $prompt, Closure $next): StructuredAgentResponse
            {
                return new StructuredAgentResponse(
                    $prompt->invocationId ?? 'middleware-only',
                    ['value' => 'cache'],
                    '{"value":"cache"}',
                    new Usage,
                    new Meta('middleware', 'cache'),
                );
            }
        };
        $result = app(DocumentExtraction::class)
            ->fromString('source', 'text/plain')
            ->using(new PricingEvidenceAgent([$middleware]))
            ->extract('openai', 'requested-model');
    } else {
        $result = app(DocumentExtraction::class)
            ->fromString('deterministic', 'text/plain')
            ->withoutAi()
            ->text();
    }

    expect($result->calls)->toBeEmpty()
        ->and($result->cost->knownByCurrency)->toBeEmpty()
        ->and($result->cost->unpricedCalls)->toBeEmpty()
        ->and($result->cost->complete)->toBeTrue();
    Http::assertNothingSent();
})->with(['deterministic no-AI' => false, 'middleware short circuit' => true]);
