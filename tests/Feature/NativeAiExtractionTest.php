<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Request;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Jkudish\DocumentExtraction\AI\InlineSchemaAgent;
use Jkudish\DocumentExtraction\AI\NativeAiBridge;
use Jkudish\DocumentExtraction\AI\OcrAgent;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Exceptions\AiExecutionException;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Jkudish\DocumentExtraction\Results\CallRecord;
use Jkudish\DocumentExtraction\Results\ExtractionResult;
use Jkudish\DocumentExtraction\Results\PageResult;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;

#[Provider('anthropic')]
#[Model('agent-model')]
#[Temperature(0.25)]
final class NativeStructuredAgent implements Agent, HasMiddleware, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /** @param array<mixed> $middleware */
    public function __construct(private readonly array $middleware = []) {}

    public function instructions(): string
    {
        return 'Application-owned extraction instructions.';
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

    public function providerOptions(Lab|string $provider): array
    {
        return ['application_option' => 'preserved'];
    }
}

final class NativeAuxiliaryAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Answer an unrelated nested question.';
    }
}

final class PrefixNativePrompt
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        return $next($prompt->prepend('middleware-prefix'));
    }
}

final class PromptOtherAgent
{
    public function __construct(private readonly NativeAuxiliaryAgent $other) {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $this->other->prompt('unrelated nested call', provider: 'openai', model: 'aux-model');

        return $next($prompt);
    }
}

final class PromptSameAgentBeforeForwarding
{
    private bool $nested = false;

    public ?NativeStructuredAgent $agent = null;

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        if (! $this->nested) {
            $this->nested = true;

            try {
                ($this->agent ?? throw new LogicException('The test middleware has no agent.'))
                    ->prompt('forbidden nested call', provider: 'openai', model: 'nested-model');
            } finally {
                $this->nested = false;
            }
        }

        return $next($prompt);
    }
}

final class NativeGatewayCapture
{
    /** @var list<array{schema: array<string, mixed>|null, timeout: ?int, prompt: string, instructions: ?string, provider: string, model: string, temperature: ?float, provider_options: array<string, mixed>|null}> */
    public array $requests = [];
}

final class UnsupportedNativeSchemaType extends StringType {}

final readonly class CapturingNativeGateway implements StepTextGateway
{
    public function __construct(
        private StepTextGateway $gateway,
        private NativeGatewayCapture $capture,
    ) {}

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
        $last = end($messages);
        $prompt = $last instanceof UserMessage ? (string) $last->content : '';
        $this->capture->requests[] = [
            'schema' => $schema === null ? null : (new ObjectSchema($schema))->toSchema(),
            'timeout' => $timeout,
            'prompt' => $prompt,
            'instructions' => $instructions,
            'provider' => $provider->name(),
            'model' => $model,
            'temperature' => $options?->temperature,
            'provider_options' => $options?->providerOptions($provider->driver()),
        ];

        return $this->gateway->generateTextStep(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );
    }

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
        return yield from $this->gateway->generateStreamStep(
            $invocationId,
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );
    }
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('extraction.provider', 'openai');
    config()->set('extraction.model', 'test-model');
});

function captureNativeGateway(): NativeGatewayCapture
{
    $capture = new NativeGatewayCapture;

    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use ($capture): void {
        $provider = $event->prompt->provider();

        if (! method_exists($provider, 'textGateway')) {
            throw new LogicException('The test provider does not expose its public text gateway.');
        }

        $gateway = $provider->textGateway();

        if (! $gateway instanceof StepTextGateway) {
            throw new LogicException('The test provider returned an invalid text gateway.');
        }

        if (! $gateway instanceof CapturingNativeGateway) {
            $provider->useTextGateway(new CapturingNativeGateway($gateway, $capture));
        }
    });

    // Resolve the bridge after the capture listener so the bridge wraps the
    // recorder and the recorder observes the package-clamped timeout.
    app(NativeAiBridge::class);

    return $capture;
}

it('extracts and validates structured data through a native Laravel agent fake', function (): void {
    InlineSchemaAgent::fake([
        ['invoice' => 'INV-42', 'total' => 1250],
    ])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('Invoice INV-42 totals 1250 cents.', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => [
            'invoice' => $schema->string()->required(),
            'total' => $schema->integer()->required(),
        ])
        ->extract();
    $call = $result->calls->first();
    assert($call instanceof CallRecord);

    expect($result->data)->toBe(['invoice' => 'INV-42', 'total' => 1250])
        ->and($result->complete())->toBeTrue()
        ->and($result->calls)->toHaveCount(1)
        ->and($call->stage)->toBe('extraction')
        ->and($call->outcome)->toBe('succeeded')
        ->and($result->cost->complete)->toBeFalse()
        ->and($result->cost->unpricedCalls->all())->toBe([0]);

    InlineSchemaAgent::assertPromptedTimes(1);
});

it('preserves application agent middleware attributes options and native routing defaults', function (): void {
    config()->set('extraction.provider', null);
    config()->set('extraction.model', null);
    config()->set('extraction.options', ['anthropic' => ['package_option' => 'must-not-replace-agent-options']]);
    $capture = captureNativeGateway();
    $agent = new NativeStructuredAgent([new PrefixNativePrompt]);
    NativeStructuredAgent::fake([['value' => 'ok']])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using($agent)
        ->extract();

    expect($result->data)->toBe(['value' => 'ok'])
        ->and($capture->requests)->toHaveCount(1)
        ->and($capture->requests[0]['provider'])->toBe('anthropic')
        ->and($capture->requests[0]['model'])->toBe('agent-model')
        ->and($capture->requests[0]['temperature'])->toBe(0.25)
        ->and($capture->requests[0]['provider_options'])->toBe(['application_option' => 'preserved'])
        ->and($capture->requests[0]['prompt'])->toStartWith('middleware-prefix')
        ->and($capture->requests[0]['instructions'])->toBe('Application-owned extraction instructions.');
});

it('uses per-call routing ahead of package and application-agent defaults', function (): void {
    $capture = captureNativeGateway();
    $agent = new NativeStructuredAgent;
    NativeStructuredAgent::fake([['value' => 'ok']])->preventStrayPrompts();

    app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using($agent)
        ->extract('openai', 'call-model', 31);

    expect($capture->requests[0]['provider'])->toBe('openai')
        ->and($capture->requests[0]['model'])->toBe('call-model')
        ->and($capture->requests[0]['timeout'])->toBeLessThanOrEqual(31);
});

it('resolves OCR purpose routing timeout options and middleware ahead of root settings', function (): void {
    config()->set('extraction.provider', 'anthropic');
    config()->set('extraction.model', 'root-model');
    config()->set('extraction.timeout', 25);
    config()->set('extraction.options', [
        'openai' => ['root_option' => 'replaced'],
        'anthropic' => ['root_only' => true],
    ]);
    config()->set('extraction.middleware', [new PrefixNativePrompt]);
    config()->set('extraction.ocr', [
        'provider' => 'openai',
        'model' => 'ocr-model',
        'timeout' => 9,
        'options' => ['openai' => ['purpose_option' => 'selected']],
    ]);
    $capture = captureNativeGateway();
    OcrAgent::fake(['transcribed'])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromPath(__DIR__.'/../Fixtures/Images/sample.png')
        ->text();

    expect($result->text)->toBe('transcribed')
        ->and($capture->requests)->toHaveCount(1)
        ->and($capture->requests[0]['provider'])->toBe('openai')
        ->and($capture->requests[0]['model'])->toBe('ocr-model')
        ->and($capture->requests[0]['timeout'])->toBeLessThanOrEqual(9)
        ->and($capture->requests[0]['provider_options'])->toBe(['purpose_option' => 'selected'])
        ->and($capture->requests[0]['prompt'])->toStartWith('middleware-prefix');
});

it('captures the exact closed schema at dispatch and clamps the per-attempt timeout', function (): void {
    config()->set('extraction.limits.ai_attempt_timeout', 7);
    $capture = captureNativeGateway();
    InlineSchemaAgent::fake([['name' => null, 'lines' => [], 'details' => []]])->preventStrayPrompts();

    app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => [
            'name' => $schema->string()->nullable()->required(),
            'lines' => $schema->array()->items($schema->string())->required(),
            'details' => $schema->object([])->required(),
        ])
        ->extract(timeout: 30);

    $schema = $capture->requests[0]['schema'];
    assert(is_array($schema));

    expect($capture->requests[0]['timeout'])->toBe(7)
        ->and($schema['type'] ?? null)->toBe('object')
        ->and($schema['additionalProperties'] ?? null)->toBeFalse()
        ->and($schema['required'] ?? null)->toBe(['name', 'lines', 'details'])
        ->and(data_get($schema, 'properties.name.type'))->toBe(['string', 'null'])
        ->and(data_get($schema, 'properties.details.additionalProperties'))->toBeFalse();
});

it('rejects a schema the native compiler cannot serialize before provider dispatch', function (): void {
    $capture = captureNativeGateway();
    InlineSchemaAgent::fake([['value' => 'must not run']])->preventStrayPrompts();

    expect(fn () => app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (): array => ['value' => new UnsupportedNativeSchemaType])
        ->extract())
        ->toThrow(ConfigurationException::class, 'generated extraction schema is invalid');

    // Entering Agent::prompt is required to obtain the native schema. The
    // wrapped provider gateway and HTTP transport must remain untouched.
    expect($capture->requests)->toBeEmpty();
    InlineSchemaAgent::assertPromptedTimes(1);
});

it('accepts locally valid nullable nested empty-object and empty-list output', function (): void {
    InlineSchemaAgent::fake([
        '{"name":null,"details":{},"lines":[]}',
    ])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => [
            'name' => $schema->string()->nullable()->required(),
            'details' => $schema->object([])->required(),
            'lines' => $schema->array()->items($schema->string())->required(),
        ])
        ->extract();

    expect($result->data)->toBe(['name' => null, 'details' => [], 'lines' => []])
        ->and($result->complete())->toBeTrue();
});

it('rejects swapped empty-object and empty-list identities before associative conversion', function (string $response): void {
    InlineSchemaAgent::fake([$response])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => [
            'details' => $schema->object([])->required(),
            'lines' => $schema->array()->items($schema->string())->required(),
        ])
        ->extract();

    expect($result->complete())->toBeFalse()
        ->and($result->data)->toBeNull()
        ->and($result->errors->first()?->code)->toBe('invalid_output');
})->with([
    'list where object is required' => ['{"details":[],"lines":[]}'],
    'object where list is required' => ['{"details":{},"lines":{}}'],
]);

it('preserves an original empty JSON object before associative conversion', function (): void {
    InlineSchemaAgent::fake([
        new StructuredTextResponse([], '{}', new Usage, new Meta('openai', 'test-model')),
    ])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => [])
        ->extract();

    expect($result->data)->toBe([])
        ->and($result->complete())->toBeTrue();
});

it('rejects malformed truncated missing mistyped extra and root-list JSON locally', function (string $response): void {
    InlineSchemaAgent::fake([$response])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract();
    $call = $result->calls->first();
    assert($call instanceof CallRecord);

    expect($result->complete())->toBeFalse()
        ->and($result->data)->toBeNull()
        ->and($result->errors->first()?->code)->toBe('invalid_output')
        ->and($result->calls)->toHaveCount(1)
        ->and($call->outcome)->toBe('invalid_output');
})->with([
    'malformed' => ['not json'],
    'truncated' => ['{"value":"cut'],
    'missing required' => ['{}'],
    'wrong type' => ['{"value":42}'],
    'extra property' => ['{"value":"ok","extra":true}'],
    'root list' => ['[]'],
]);

it('rejects provider output that is not valid UTF-8', function (): void {
    InlineSchemaAgent::fake(["{\"value\":\"\xB1\"}"])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract();

    expect($result->complete())->toBeFalse()
        ->and($result->errors->first()?->code)->toBe('invalid_output')
        ->and($result->errors->first()?->message)->toContain('valid UTF-8');
});

it('uses only native failover for failoverable provider exceptions', function (): void {
    config()->set('extraction.model', null);
    $capture = captureNativeGateway();
    $attempt = 0;
    InlineSchemaAgent::fake(function () use (&$attempt): array {
        if ($attempt++ === 0) {
            throw ProviderConnectionException::forProvider('openai');
        }

        return ['value' => 'fallback'];
    })->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract(['openai' => 'first-model', 'anthropic' => 'second-model']);

    expect($result->data)->toBe(['value' => 'fallback'])
        ->and($capture->requests)->toHaveCount(2)
        ->and(array_column($capture->requests, 'provider'))->toBe(['openai', 'anthropic'])
        ->and($result->calls->pluck('outcome')->all())->toBe(['failed', 'succeeded']);
});

it('reuses each dynamically generated dispatch schema for that attempt validation', function (): void {
    config()->set('extraction.model', null);
    $schemaCalls = 0;
    $capture = captureNativeGateway();
    $attempt = 0;
    InlineSchemaAgent::fake(function () use (&$attempt): array {
        if ($attempt++ === 0) {
            throw ProviderConnectionException::forProvider('openai');
        }

        return ['value' => 42];
    })->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(function (JsonSchema $schema) use (&$schemaCalls): array {
            $schemaCalls++;

            return ['value' => $schemaCalls === 1
                ? $schema->string()->required()
                : $schema->integer()->required()];
        })
        ->extract(['openai' => 'first-model', 'anthropic' => 'second-model']);

    expect($result->data)->toBe(['value' => 42])
        ->and($schemaCalls)->toBe(2)
        ->and(data_get($capture->requests, '0.schema.properties.value.type'))->toBe('string')
        ->and(data_get($capture->requests, '1.schema.properties.value.type'))->toBe('integer');
});

it('stops native fallback when the invocation attempt budget is exhausted and attaches evidence', function (): void {
    config()->set('extraction.model', null);
    config()->set('extraction.limits.ai_attempts', 1);
    $capture = captureNativeGateway();
    InlineSchemaAgent::fake([
        fn (): never => throw ProviderConnectionException::forProvider('openai'),
        ['value' => 'must not run'],
    ])->preventStrayPrompts();

    try {
        app(DocumentExtraction::class)
            ->fromString('source', 'text/plain')
            ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
            ->extract(['openai' => 'first-model', 'anthropic' => 'second-model']);

        throw new RuntimeException('The configured AI attempt budget should stop native fallback.');
    } catch (AiExecutionException $exception) {
        expect($exception->errorCode)->toBe('ai_attempt_limit_exceeded')
            ->and($exception->partialResult)->not->toBeNull()
            ->and($exception->partialResult?->calls)->toHaveCount(1)
            ->and($exception->partialResult?->calls->first()?->outcome)->toBe('failed')
            ->and($capture->requests)->toHaveCount(1);
    }
});

it('stops fallback after the shared preparation and invocation deadline expires', function (): void {
    config()->set('extraction.model', null);
    config()->set('extraction.limits.invocation_deadline', 1);
    InlineSchemaAgent::fake([
        function (): never {
            usleep(1_100_000);

            throw ProviderConnectionException::forProvider('openai');
        },
        ['value' => 'must not run'],
    ])->preventStrayPrompts();

    try {
        app(DocumentExtraction::class)
            ->fromString('source', 'text/plain')
            ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
            ->extract(['openai' => 'first-model', 'anthropic' => 'second-model']);

        throw new RuntimeException('An expired invocation deadline should stop native fallback.');
    } catch (AiExecutionException $exception) {
        expect($exception->errorCode)->toBe('invocation_deadline_exceeded')
            ->and($exception->partialResult)->not->toBeNull()
            ->and($exception->partialResult?->calls)->toHaveCount(1);
    }
});

it('does not retry programming errors or invalid output', function (Throwable $failure): void {
    config()->set('extraction.model', null);
    $capture = captureNativeGateway();
    InlineSchemaAgent::fake([
        fn (): never => throw $failure,
        ['value' => 'must not run'],
    ])->preventStrayPrompts();

    $run = fn () => app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract(['openai', 'anthropic']);

    if ($failure instanceof AiException) {
        $result = $run();
        expect($result->errors->first()?->code)->toBe('provider_failed');
    } else {
        expect($run)->toThrow(LogicException::class);
    }

    expect($capture->requests)->toHaveCount(1);
})->with([
    'non-failoverable provider failure' => [new AiException('bad request')],
    'programming error' => [new LogicException('programming defect')],
]);

it('allows a cross-agent middleware prompt without attributing it to extraction', function (): void {
    $other = new NativeAuxiliaryAgent;
    $agent = new NativeStructuredAgent([new PromptOtherAgent($other)]);
    NativeAuxiliaryAgent::fake(['auxiliary answer'])->preventStrayPrompts();
    NativeStructuredAgent::fake([['value' => 'extracted']])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using($agent)
        ->extract('openai', 'extract-model');

    expect($result->data)->toBe(['value' => 'extracted'])
        ->and($result->calls)->toHaveCount(1);
    NativeAuxiliaryAgent::assertPromptedTimes(1);
    NativeStructuredAgent::assertPromptedTimes(1);
});

it('fails observably when middleware prompts the same agent before forwarding', function (): void {
    $middleware = new PromptSameAgentBeforeForwarding;
    $agent = new NativeStructuredAgent([$middleware]);
    $middleware->agent = $agent;
    NativeStructuredAgent::fake([
        ['value' => 'nested'],
        ['value' => 'outer'],
    ])->preventStrayPrompts();

    expect(fn () => app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using($agent)
        ->extract('openai', 'test-model'))
        ->toThrow(ConfigurationException::class, 'must not prompt the same agent');
});

it('cleans invocation scope after exceptions and across sequential requests', function (): void {
    InlineSchemaAgent::fake(['not json'])->preventStrayPrompts();
    $failed = app(DocumentExtraction::class)
        ->fromString('first', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract();

    InlineSchemaAgent::fake([['value' => 'second']])->preventStrayPrompts();
    $succeeded = app(DocumentExtraction::class)
        ->fromString('second', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract();

    expect($failed->complete())->toBeFalse()
        ->and($succeeded->data)->toBe(['value' => 'second'])
        ->and($succeeded->complete())->toBeTrue();
});

it('isolates interleaved extraction scopes by Fiber', function (): void {
    InlineSchemaAgent::fake(function (string $prompt): array {
        $value = str_contains($prompt, 'fiber-a') ? 'A' : 'B';
        Fiber::suspend('started-'.$value);

        return ['value' => $value];
    })->preventStrayPrompts();

    $extract = fn (string $source) => app(DocumentExtraction::class)
        ->fromString($source, 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract();
    $a = new Fiber(fn () => $extract('fiber-a'));
    $b = new Fiber(fn () => $extract('fiber-b'));

    expect($a->start())->toBe('started-A')
        ->and($b->start())->toBe('started-B');
    $a->resume();
    $b->resume();
    $resultA = $a->getReturn();
    $resultB = $b->getReturn();
    assert($resultA instanceof ExtractionResult);
    assert($resultB instanceof ExtractionResult);

    expect($resultA->data)->toBe(['value' => 'A'])
        ->and($resultB->data)->toBe(['value' => 'B']);
});

it('returns truthful partial OCR page results when one provider call fails', function (): void {
    OcrAgent::fake([
        'first page',
        fn (): never => throw new AiException('provider failed'),
    ])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromPath(__DIR__.'/../Fixtures/Images/multipage.tiff')
        ->text('openai', 'ocr-model');
    $first = $result->pages->get(0);
    $second = $result->pages->get(1);
    assert($first instanceof PageResult);
    assert($second instanceof PageResult);

    expect($result->pageCount)->toBe(2)
        ->and($result->pages)->toHaveCount(2)
        ->and($first->text)->toBe('first page')
        ->and($first->complete())->toBeTrue()
        ->and($second->complete())->toBeFalse()
        ->and($second->error?->code)->toBe('provider_failed')
        ->and($result->complete())->toBeFalse()
        ->and($result->calls->pluck('outcome')->all())->toBe(['succeeded', 'failed']);
});

it('rejects oversized prepared visual payloads before provider egress', function (): void {
    config()->set('extraction.limits.inline_attachment_bytes', 1);
    OcrAgent::fake(['must not run'])->preventStrayPrompts();

    expect(fn () => app(DocumentExtraction::class)
        ->fromPath(__DIR__.'/../Fixtures/Images/sample.png')
        ->text('openai', 'ocr-model'))
        ->toThrow(AiExecutionException::class, 'pre-base64');

    OcrAgent::assertNeverPrompted();
});

it('enforces aggregate UTF-8 AI output bytes without truncation', function (): void {
    config()->set('extraction.limits.retained_output_bytes', 10);
    InlineSchemaAgent::fake(['{"value":"too long"}'])->preventStrayPrompts();

    expect(fn () => app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract())
        ->toThrow(AiExecutionException::class, 'aggregate output byte limit');
});

it('attaches prior page and call evidence when a global OCR output limit stops processing', function (): void {
    config()->set('extraction.limits.retained_output_bytes', 5);
    OcrAgent::fake(['one', 'three'])->preventStrayPrompts();

    try {
        app(DocumentExtraction::class)
            ->fromPath(__DIR__.'/../Fixtures/Images/multipage.tiff')
            ->text('openai', 'ocr-model');

        throw new RuntimeException('The aggregate OCR output limit should stop processing.');
    } catch (AiExecutionException $exception) {
        $partial = $exception->partialResult;

        expect($exception->errorCode)->toBe('output_limit_exceeded')
            ->and($partial)->not->toBeNull()
            ->and($partial?->text)->toBe('one')
            ->and($partial?->pages)->toHaveCount(1)
            ->and($partial?->calls)->toHaveCount(2)
            ->and($partial?->calls->pluck('outcome')->all())->toBe(['succeeded', 'failed'])
            ->and($partial?->complete())->toBeFalse();
    }
});

it('uses a representative OpenAI HTTP response while validating its raw text and request schema', function (): void {
    config()->set('ai.providers.openai.key', 'test-key');
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_test',
            'status' => 'completed',
            'model' => 'fixture-model',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => '{"value":"from-http-fixture"}',
                    'annotations' => [],
                ]],
            ]],
            'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
        ]),
    ]);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract('openai', 'fixture-model');

    expect($result->data)->toBe(['value' => 'from-http-fixture']);
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $schema = data_get($data, 'text.format.schema');

        if (! is_array($schema)) {
            return false;
        }

        return $request->url() === 'https://api.openai.com/v1/responses'
            && ($schema['type'] ?? null) === 'object'
            && ($schema['additionalProperties'] ?? null) === false
            && ($schema['required'] ?? null) === ['value'];
    });
});

it('uses a representative Anthropic native structured HTTP response and validates its response text', function (): void {
    config()->set('ai.providers.anthropic.key', 'test-key');
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'fixture-model',
            'content' => [[
                'type' => 'text',
                'text' => '{"value":"from-anthropic-fixture"}',
            ]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
        ]),
    ]);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract('anthropic', 'fixture-model');

    expect($result->data)->toBe(['value' => 'from-anthropic-fixture']);
    Http::assertSent(function (Request $request): bool {
        $schema = data_get($request->data(), 'output_config.format.schema');

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && is_array($schema)
            && ($schema['type'] ?? null) === 'object'
            && ($schema['additionalProperties'] ?? null) === false
            && ($schema['required'] ?? null) === ['value'];
    });
});
