<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Jkudish\DocumentExtraction\Exceptions\ProcessingUnavailableException;
use Jkudish\DocumentExtraction\PendingExtraction;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

class StructuredTestAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Extract facts.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['value' => $schema->string()->required()];
    }
}

final class ToolUsingStructuredTestAgent extends StructuredTestAgent implements HasTools
{
    public function tools(): iterable
    {
        return [new StructuredTestAgent];
    }
}

final class ConversationalStructuredTestAgent extends StructuredTestAgent implements Conversational
{
    public function messages(): iterable
    {
        return [new Message('user', 'prior conversation')];
    }
}

it('snapshots request configuration and resolves text purpose overrides without global mutation', function (): void {
    config()->set('extraction.provider', 'root-provider');
    config()->set('extraction.model', 'root-model');
    config()->set('extraction.ocr.provider', 'ocr-provider');
    config()->set('extraction.ocr.model', 'ocr-model');
    $extraction = sourceRecorder();
    $pending = $extraction->fromString('source');
    config()->set('extraction.ocr.provider', 'changed-after-request');

    $pending->text();

    $configuration = $pending->configuration();
    $invocation = $extraction->invocations[0];

    expect($configuration['ocr']['provider'])->toBe('ocr-provider')
        ->and(config('extraction.ocr.provider'))->toBe('changed-after-request')
        ->and($invocation->provider)->toBe('ocr-provider')
        ->and($invocation->model)->toBe('ocr-model');
});

it('resolves no provider or model when AI is disabled', function (): void {
    config()->set('extraction.provider', 'configured-provider');
    config()->set('extraction.model', 'configured-model');
    $extraction = sourceRecorder();

    $extraction->fromString('source')->withoutAi()->text();

    expect($extraction->invocations[0]->provider)->toBeNull()
        ->and($extraction->invocations[0]->model)->toBeNull();
});

it('normalizes selected pages to ascending original physical page numbers', function (): void {
    $pending = sourceRecorder()->fromString('%PDF-1.7 source', 'application/pdf')->pages([3, 1, 2]);

    expect($pending->selectedPages())->toBe([1, 2, 3]);
});

it('rejects invalid page selections', function (array $pages): void {
    expect(fn () => sourceRecorder()->fromString('source')->pages($pages))
        ->toThrow(ConfigurationException::class);
})->with([
    'empty' => [[]],
    'zero' => [[0]],
    'negative' => [[-1]],
    'string' => [['1']],
    'duplicate' => [[1, 1]],
    'associative' => [['page' => 1]],
]);

it('rejects conflicting modes before reading caller bytes', function (Closure $configure, string $terminal): void {
    $stream = fopen('php://temp', 'w+b');
    assert(is_resource($stream));
    fwrite($stream, 'unread source');
    rewind($stream);
    $extraction = sourceRecorder();
    $pending = $configure($extraction->fromStream($stream));

    try {
        expect(fn () => $pending->{$terminal}())
            ->toThrow(ConfigurationException::class)
            ->and(ftell($stream))->toBe(0)
            ->and($extraction->snapshots)->toBe([]);
    } finally {
        fclose($stream);
    }
})->with([
    'schema and agent' => [
        fn (PendingExtraction $pending): PendingExtraction => $pending->schema(fn (JsonSchema $schema) => ['value' => $schema->string()])->using(StructuredTestAgent::class),
        'extract',
    ],
    'instructions and agent' => [
        fn (PendingExtraction $pending): PendingExtraction => $pending->instructions('Inline only')->using(StructuredTestAgent::class),
        'extract',
    ],
    'schema with text' => [
        fn (PendingExtraction $pending): PendingExtraction => $pending->schema(fn (JsonSchema $schema) => ['value' => $schema->string()]),
        'text',
    ],
    'without AI structured' => [fn (PendingExtraction $pending): PendingExtraction => $pending->withoutAi(), 'extract'],
    'without AI detection' => [fn (PendingExtraction $pending): PendingExtraction => $pending->withoutAi()->detectDocuments(), 'text'],
]);

it('rejects a provider list plus a separate model before reading caller bytes', function (): void {
    $stream = fopen('php://temp', 'w+b');
    assert(is_resource($stream));
    fwrite($stream, 'unread source');
    rewind($stream);
    $extraction = sourceRecorder();

    try {
        expect(fn () => $extraction->fromStream($stream)->text([
            'primary' => 'model-a',
            'fallback' => 'model-b',
        ], 'separate-model'))
            ->toThrow(ConfigurationException::class)
            ->and(ftell($stream))->toBe(0);
    } finally {
        fclose($stream);
    }
});

it('rejects malformed provider maps and terminal timeouts', function (array|string|Lab|null $provider, ?string $model, ?int $timeout): void {
    expect(fn () => sourceRecorder()->fromString('source')->text($provider, $model, $timeout))
        ->toThrow(ConfigurationException::class);
})->with([
    'empty provider map' => [[], null, null],
    'empty provider name' => [['' => 'model'], null, null],
    'empty provider model' => [['provider' => ''], null, null],
    'duplicate provider list' => [['openai', Lab::OpenAI], null, null],
    'empty provider string' => ['', null, null],
    'mixed list and map' => [[0 => 'openai', 'fallback' => 'model'], null, null],
    'non-list numeric keys' => [[1 => 'openai'], null, null],
    'empty model' => ['openai', '', null],
    'zero timeout' => ['openai', null, 0],
]);

it('rejects malformed package configuration before accepting a request', function (string $key, mixed $value): void {
    config()->set('extraction.'.$key, $value);

    expect(fn () => sourceRecorder()->fromString('source'))
        ->toThrow(ConfigurationException::class);
})->with([
    'empty provider map' => ['provider', []],
    'zero timeout' => ['timeout', 0],
    'invalid middleware' => ['middleware', 'not-an-array'],
    'invalid source limit' => ['limits.source_bytes', 0],
    'invalid OCR settings' => ['ocr', 'not-an-array'],
]);

it('rejects a configured provider map combined with a configured model', function (): void {
    config()->set('extraction.provider', ['openai' => 'model-a']);
    config()->set('extraction.model', 'separate-model');

    expect(fn () => sourceRecorder()->fromString('source'))
        ->toThrow(ConfigurationException::class);
});

it('normalizes omitted nullable routing keys in the request snapshot', function (): void {
    $configuration = config('extraction');
    assert(is_array($configuration));
    $ocr = $configuration['ocr'] ?? null;
    assert(is_array($ocr));
    unset($configuration['provider'], $configuration['model']);
    unset($ocr['provider'], $ocr['model']);
    $configuration['ocr'] = $ocr;
    config()->set('extraction', $configuration);

    $snapshot = sourceRecorder()->fromString('source')->configuration();

    expect($snapshot['provider'])->toBeNull()
        ->and($snapshot['model'])->toBeNull()
        ->and($snapshot['ocr']['provider'])->toBeNull()
        ->and($snapshot['ocr']['model'])->toBeNull();
});

it('rejects a provider list inherited with a purpose-specific model', function (): void {
    config()->set('extraction.provider', ['openai', 'anthropic']);
    config()->set('extraction.ocr.model', 'separate-model');
    $stream = fopen('php://temp', 'w+b');
    assert(is_resource($stream));
    fwrite($stream, 'unread source');
    rewind($stream);

    try {
        expect(fn () => sourceRecorder()->fromStream($stream)->text())
            ->toThrow(ConfigurationException::class)
            ->and(ftell($stream))->toBe(0);
    } finally {
        fclose($stream);
    }
});

it('rejects page selection for unpaginated content without inventing pages', function (): void {
    expect(fn () => sourceRecorder()->fromString('plain source', 'text/plain')->pages([1])->text())
        ->toThrow(ConfigurationException::class, 'unpaginated');
});

it('rejects unsupported structured-agent capabilities before source egress', function (string $agent): void {
    $stream = fopen('php://temp', 'w+b');
    assert(is_resource($stream));
    fwrite($stream, 'unread source');
    rewind($stream);

    try {
        expect(fn () => sourceRecorder()->fromStream($stream)->using($agent)->extract())
            ->toThrow(ConfigurationException::class)
            ->and(ftell($stream))->toBe(0);
    } finally {
        fclose($stream);
    }
})->with([
    ToolUsingStructuredTestAgent::class,
    ConversationalStructuredTestAgent::class,
    stdClass::class,
]);

it('fails explicitly at the not-yet-implemented live processing boundary', function (): void {
    expect(fn () => app(DocumentExtraction::class)
        ->fromString('source', 'text/plain')
        ->withoutAi()
        ->text())
        ->toThrow(ProcessingUnavailableException::class, 'not implemented');
});
