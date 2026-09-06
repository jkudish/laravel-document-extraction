<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Jkudish\DocumentExtraction\AI\OcrAgent;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Exceptions\AiExecutionException;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

final class ReviewLifecycleAgent implements Agent, HasMiddleware, HasStructuredOutput
{
    use Promptable;

    /** @param array<mixed> $middleware */
    public function __construct(private readonly array $middleware = []) {}

    public function instructions(): string
    {
        return 'Extract the value.';
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

final class ReviewPostProcessMiddleware
{
    public function __construct(private readonly string $text = '{"value":"post-processed"}') {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $response = $next($prompt);

        if ($response instanceof StructuredAgentResponse) {
            $response->structured = ['value' => 'post-processed'];
            $response->text = $this->text;
        }

        return $response;
    }
}

final class ReviewSlowPostProcessMiddleware
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $response = $next($prompt);

        usleep(1_100_000);

        return $response;
    }
}

final class ReviewShortCircuitMiddleware
{
    public function handle(AgentPrompt $prompt, Closure $next): StructuredAgentResponse
    {
        return new StructuredAgentResponse(
            $prompt->invocationId ?? 'missing',
            ['value' => 'short-circuited'],
            '{"value":"short-circuited"}',
            new Usage,
            new Meta('middleware', 'cache'),
        );
    }
}

final class ReviewPostForwardSameAgentMiddleware
{
    private bool $nested = false;

    public ?ReviewLifecycleAgent $agent = null;

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $response = $next($prompt);

        if (! $this->nested) {
            $this->nested = true;

            try {
                ($this->agent ?? throw new LogicException('Missing agent.'))
                    ->prompt('supported post-forward same-agent call', provider: 'openai', model: 'nested-model');
            } finally {
                $this->nested = false;
            }
        }

        return $response;
    }
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('extraction.provider', 'openai');
    config()->set('extraction.model', 'review-model');
});

it('rejects a provider-declared length result even when JSON is valid', function (): void {
    config()->set('ai.providers.openai.key', 'test-key');
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_incomplete',
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'model' => 'review-model',
            'output' => [[
                'type' => 'message',
                'status' => 'incomplete',
                'content' => [[
                    'type' => 'output_text',
                    'text' => '{"value":"syntactically-valid-but-provider-incomplete"}',
                    'annotations' => [],
                ]],
            ]],
            'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
        ]),
    ]);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new ReviewLifecycleAgent)
        ->extract();

    expect($result->complete())->toBeFalse()
        ->and($result->data)->toBeNull()
        ->and($result->errors->first()?->code)->toBe('invalid_output');
});

it('rejects a provider success after the shared invocation deadline expires', function (): void {
    config()->set('extraction.limits.invocation_deadline', 1);
    ReviewLifecycleAgent::fake(function (): array {
        usleep(1_100_000);

        return ['value' => 'late-success'];
    })->preventStrayPrompts();

    expect(fn () => app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new ReviewLifecycleAgent)
        ->extract())->toThrow(AiExecutionException::class, 'deadline was exceeded');
});

it('rejects a late OCR page instead of reporting it complete', function (): void {
    config()->set('extraction.limits.invocation_deadline', 1);
    OcrAgent::fake(function (): string {
        usleep(1_100_000);

        return 'late transcription';
    })->preventStrayPrompts();

    expect(fn () => app(DocumentExtraction::class)
        ->fromPath(dirname(__DIR__, 2).'/tests/Fixtures/Images/sample.png')
        ->text())->toThrow(AiExecutionException::class, 'deadline was exceeded');
});

it('checks the shared deadline after post-forward middleware', function (): void {
    config()->set('extraction.limits.invocation_deadline', 1);
    ReviewLifecycleAgent::fake([['value' => 'provider']])->preventStrayPrompts();

    expect(fn () => app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new ReviewLifecycleAgent([new ReviewSlowPostProcessMiddleware]))
        ->extract())->toThrow(AiExecutionException::class, 'deadline was exceeded');
});

it('preserves ordinary post-forward middleware response changes', function (): void {
    ReviewLifecycleAgent::fake([['value' => 'provider']])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new ReviewLifecycleAgent([new ReviewPostProcessMiddleware]))
        ->extract();

    expect($result->data)->toBe(['value' => 'post-processed']);
});

it('revalidates changed middleware JSON rather than trusting normalized structured data', function (string $text): void {
    ReviewLifecycleAgent::fake([['value' => 'provider']])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new ReviewLifecycleAgent([new ReviewPostProcessMiddleware($text)]))
        ->extract();

    expect($result->complete())->toBeFalse()
        ->and($result->data)->toBeNull()
        ->and($result->errors->first()?->code)->toBe('invalid_output')
        ->and($result->calls)->toHaveCount(1);
})->with(['truncated' => '{"value":', 'wrong type' => '{"value":3}']);

it('bounds post-forward middleware output while retaining the completed provider evidence', function (): void {
    config()->set('extraction.limits.retained_output_bytes', 32);
    ReviewLifecycleAgent::fake([['value' => 'provider']])->preventStrayPrompts();

    try {
        app(DocumentExtraction::class)
            ->fromString('source', 'text/plain')
            ->using(new ReviewLifecycleAgent([new ReviewPostProcessMiddleware(str_repeat('x', 33))]))
            ->extract();
        $this->fail('Expected the middleware output limit to stop extraction.');
    } catch (AiExecutionException $exception) {
        expect($exception->errorCode)->toBe('output_limit_exceeded')
            ->and($exception->partialResult?->complete())->toBeFalse()
            ->and($exception->partialResult?->calls)->toHaveCount(1);
    }
});

it('retains provider evidence when post-forward middleware raises a configuration failure', function (): void {
    ReviewLifecycleAgent::fake([['value' => 'provider']])->preventStrayPrompts();
    $middleware = function (AgentPrompt $prompt, Closure $next): never {
        $next($prompt);

        throw ConfigurationException::make('test_configuration', 'Post-forward configuration failure.');
    };

    try {
        app(DocumentExtraction::class)
            ->fromString('source', 'text/plain')
            ->using(new ReviewLifecycleAgent([$middleware]))
            ->extract();
        $this->fail('Expected the configuration failure to stop extraction.');
    } catch (ConfigurationException $exception) {
        expect($exception->errorCode)->toBe('test_configuration')
            ->and($exception->partialResult?->complete())->toBeFalse()
            ->and($exception->partialResult?->calls)->toHaveCount(1);
    }
});

it('validates ordinary middleware short-circuit output without inventing provider calls', function (): void {
    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using(new ReviewLifecycleAgent([new ReviewShortCircuitMiddleware]))
        ->extract();

    expect($result->data)->toBe(['value' => 'short-circuited'])
        ->and($result->calls)->toBeEmpty();
    Http::assertNothingSent();
});

it('permits unrelated same-agent calls after the outer gateway returns', function (): void {
    $middleware = new ReviewPostForwardSameAgentMiddleware;
    $agent = new ReviewLifecycleAgent([$middleware]);
    $middleware->agent = $agent;
    ReviewLifecycleAgent::fake([
        ['value' => 'outer'],
        ['value' => 'post-forward-nested'],
    ])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->using($agent)
        ->extract();

    expect($result->data)->toBe(['value' => 'outer'])
        ->and($result->calls)->toHaveCount(1);
    ReviewLifecycleAgent::assertPromptedTimes(2);
});
