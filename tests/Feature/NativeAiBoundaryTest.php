<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Request;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Http;
use Jkudish\DocumentExtraction\AI\InlineSchemaAgent;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Exceptions\AiExecutionException;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;

final class BoundaryAttachmentMiddleware
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        return $next($prompt->withAttachments([
            ...$prompt->attachments->all(),
            Image::fromBase64(base64_encode(str_repeat('x', 64)), 'image/png'),
        ]));
    }
}

final class ChangingProviderOptionsAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public int $optionReads = 0;

    /** @var list<string> */
    public array $optionProviders = [];

    public function instructions(): string
    {
        return 'Extract the value.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['value' => $schema->string()->required()];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $this->optionProviders[] = $provider instanceof Lab ? $provider->value : $provider;

        return ++$this->optionReads === 1
            ? ['metadata' => ['checked' => 'yes']]
            : ['input' => 'This must not replace the document.'];
    }
}

beforeEach(function (): void {
    config()->set('extraction.provider', 'openai');
    config()->set('extraction.model', 'fixture-model');
    config()->set('ai.providers.openai.key', 'test-key');
    Http::preventStrayRequests();
});

function boundaryOpenAiResponse(string $text = '{"value":"ok"}'): void
{
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_boundary',
            'status' => 'completed',
            'model' => 'fixture-model',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
            ]],
            'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
        ]),
    ]);
}

it('validates original Anthropic tool JSON without collapsing object and array identity', function (string $input, bool $valid): void {
    config()->set('ai.providers.anthropic.key', 'test-key');
    config()->set('ai.providers.anthropic.use_native_structured_output', false);
    $body = '{"id":"msg_boundary","type":"message","role":"assistant","model":"fixture-model",'
        .'"content":[{"type":"tool_use","id":"tool_1","name":"output_structured_data","input":'.$input.'}],'
        .'"stop_reason":"tool_use","usage":{"input_tokens":2,"output_tokens":3}}';
    Http::fake(['https://api.anthropic.com/v1/messages' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => [
            'lines' => $schema->array()->items($schema->string())->required(),
            'details' => $schema->object([])->required(),
        ])
        ->extract('anthropic', 'fixture-model');

    expect($result->complete())->toBe($valid)
        ->and($result->data)->toBe($valid ? ['lines' => [], 'details' => []] : null);
})->with([
    'valid original containers' => ['{"lines":[],"details":{}}', true],
    'invalid original array' => ['{"lines":{},"details":{}}', false],
    'invalid original object' => ['{"lines":[],"details":[]}', false],
]);

it('rejects provider options that replace generated request structure before HTTP', function (string $key): void {
    boundaryOpenAiResponse();
    config()->set('extraction.options', ['openai' => [$key => ['overridden' => true]]]);

    expect(fn () => app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract())->toThrow(ConfigurationException::class, 'must not replace');
    Http::assertNothingSent();
})->with(['text', 'input', 'tools', 'previous_response_id', 'response_json_schema', 'instructions', 'prompt']);

it('freezes native provider options once while preserving safe provider metadata', function (): void {
    boundaryOpenAiResponse();
    $agent = new ChangingProviderOptionsAgent;

    $result = app(DocumentExtraction::class)->fromString('source', 'text/plain')->using($agent)->extract();

    expect($result->data)->toBe(['value' => 'ok'])
        ->and($agent->optionReads)->toBe(1);
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'metadata.checked') === 'yes'
        && is_array($request['input']));
});

it('preserves the native custom OpenAI-compatible provider-name option selector', function (): void {
    config()->set('ai.providers.private-compatible', [
        'driver' => 'openai-compatible', 'url' => 'https://compatible.example/v1', 'key' => 'fixture-key',
    ]);
    Http::fake(['https://compatible.example/v1/chat/completions' => Http::response([
        'id' => 'chatcmpl_fixture', 'model' => 'fixture-model',
        'choices' => [[
            'index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"value":"ok"}'], 'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ])]);
    $agent = new ChangingProviderOptionsAgent;

    $result = app(DocumentExtraction::class)->fromString('source', 'text/plain')->using($agent)
        ->extract('private-compatible', 'fixture-model');

    expect($result->data)->toBe(['value' => 'ok'])
        ->and($agent->optionProviders)->toBe(['private-compatible']);
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'metadata.checked') === 'yes');
});

it('preserves native provider-side compaction without allowing request replacement', function (): void {
    boundaryOpenAiResponse();
    config()->set('extraction.options.openai.context_management', [['type' => 'compaction', 'compact_threshold' => 2000]]);

    $result = app(DocumentExtraction::class)->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])->extract();

    expect($result->complete())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'context_management.0.type') === 'compaction');
});

it('checks final middleware attachments against the request limit before HTTP', function (): void {
    boundaryOpenAiResponse();
    config()->set('extraction.limits.inline_attachment_bytes', 1);
    config()->set('extraction.middleware', [new BoundaryAttachmentMiddleware]);

    expect(fn () => app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract())->toThrow(AiExecutionException::class, 'pre-base64');
    Http::assertNothingSent();
});

it('preserves bounded middleware image attachments in the actual request', function (): void {
    boundaryOpenAiResponse();
    config()->set('extraction.limits.inline_attachment_bytes', 64);
    config()->set('extraction.middleware', [new BoundaryAttachmentMiddleware]);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract();

    expect($result->complete())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_contains(
        json_encode($request->data(), JSON_THROW_ON_ERROR),
        base64_encode(str_repeat('x', 64)),
    ));
});

it('fails before dispatch when a native provider cannot preserve original structured JSON', function (): void {
    InlineSchemaAgent::fake([['value' => 'must not run']])->preventStrayPrompts();

    expect(fn () => app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()])
        ->extract('bedrock', 'fixture-model'))->toThrow(ConfigurationException::class, 'original structured JSON');
    Http::assertNothingSent();
});

it('keeps native constraints authoritative locally despite provider-specific schema adaptation', function (): void {
    config()->set('ai.providers.anthropic.key', 'test-key');
    Http::fake(['https://api.anthropic.com/v1/messages' => Http::response([
        'id' => 'msg_constraints', 'type' => 'message', 'role' => 'assistant', 'model' => 'fixture-model',
        'content' => [['type' => 'text', 'text' => '{"value":"x"}']],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
    ])]);

    $result = app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->min(3)->nullable()->required()])
        ->extract('anthropic', 'fixture-model');

    expect($result->complete())->toBeFalse()
        ->and($result->errors->first()?->code)->toBe('invalid_output');
    Http::assertSent(fn (Request $request): bool => str_contains(
        json_encode(data_get($request->data(), 'output_config.format.schema'), JSON_THROW_ON_ERROR),
        'at least 3 characters',
    ));
});
