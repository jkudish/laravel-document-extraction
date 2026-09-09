<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Illuminate\JsonSchema\Types\Type;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

final readonly class ScopedTextGateway implements StepTextGateway
{
    public function __construct(
        private StepTextGateway $gateway,
        private InvocationScopeRegistry $scopes,
    ) {}

    public function uses(InvocationScopeRegistry $scopes): bool
    {
        return $this->scopes === $scopes;
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
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
        $scope = $this->scopes->gateway($provider, $options);

        if ($scope === null) {
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

        if ($tools !== []) {
            throw ConfigurationException::make('unsupported_agent', 'Extraction requests must not contain executable tools.');
        }

        if ($schema !== null && $provider->driver() === 'bedrock') {
            throw ConfigurationException::make(
                'unsupported_ai_provider',
                'The current native Bedrock gateway does not preserve original structured JSON for local validation.',
            );
        }

        $messages = NativeRequestGuard::messages(
            $messages,
            $scope->session->attachmentLimit,
            $provider->driver(),
            $stepContext->stepNumber,
        );
        $options = $options === null ? null : new FrozenGenerationOptions(
            $options,
            $options->providerOptions($this->providerOptionsKey($provider, $options)) ?? [],
        );
        $compiled = $schema === null ? null : CompiledSchema::fromNative($schema);
        $attempt = $scope->session->beginAttempt();
        $resolvedTimeout = $timeout === null
            ? $attempt['remaining_seconds']
            : min($timeout, $attempt['remaining_seconds']);
        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $startedAtNanoseconds = hrtime(true);
        $response = null;
        $outcome = 'failed';

        try {
            $response = $this->gateway->generateTextStep(
                $provider,
                $model,
                $instructions,
                $messages,
                $tools,
                $schema,
                $options,
                $resolvedTimeout,
                $stepContext,
            );

            $scope->session->remainingSeconds();

            if (! in_array($response->finishReason, [FinishReason::Stop, FinishReason::Continue], true)) {
                throw new InvalidAiOutputException('The provider did not report a complete response.');
            }

            $text = $compiled === null ? $response->text : $this->structuredText($response, $provider, $scope->session->outputLimit);
            $scope->session->retain($text);

            if ($response->finishReason === FinishReason::Stop) {
                $result = $compiled === null
                    ? new NativeAiResult(text: $text)
                    : new NativeAiResult(data: $compiled->validate($text));
                $scope->complete($result, $compiled, $response->text, $text, $response->structured);
            }

            $scope->session->remainingSeconds();
            $outcome = $response->finishReason === FinishReason::Stop ? 'succeeded' : 'continued';

            return $response;
        } catch (Throwable $exception) {
            $outcome = $exception instanceof InvalidAiOutputException ? 'invalid_output' : 'failed';

            throw $exception;
        } finally {
            [$requestedProvider, $requestedModel] = $scope->requestedIdentity();
            $scope->session->record(
                $scope->stage,
                $scope->pages,
                $scope->invocationId(),
                $attempt['ordinal'],
                $requestedProvider,
                $requestedModel,
                $provider->name(),
                $model,
                $outcome,
                $this->elapsedMilliseconds($startedAtNanoseconds),
                $startedAt,
                $response,
                $scope->evidenceOrigin,
            );
        }
    }

    private function structuredText(StepResponse $response, TextProvider $provider, int $limit): string
    {
        if ($provider->driver() !== 'anthropic') {
            return $response->text;
        }

        $hasStructuredTool = false;

        foreach ($response->providerContentBlocks as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'output_structured_data') {
                $hasStructuredTool = true;
            }
        }

        if (! $hasStructuredTool) {
            return $response->text;
        }

        $stream = $response->raw?->toPsrResponse()->getBody();

        if ($stream === null || ! $stream->isSeekable()) {
            throw new InvalidAiOutputException('The provider did not preserve original structured JSON.');
        }

        $position = $stream->tell();
        // JSON escaping can expand retained text sixfold; bound the envelope too.
        $envelopeLimit = $limit * 6 + 65_536;

        try {
            $stream->rewind();
            $body = $stream->read($envelopeLimit + 1);
        } finally {
            $stream->seek($position);
        }

        if (strlen($body) > $envelopeLimit) {
            throw new InvalidAiOutputException('The provider structured-response envelope exceeded its bounded size.');
        }

        try {
            $envelope = json_decode($body, false, 512, JSON_THROW_ON_ERROR);

            foreach (is_object($envelope) && is_array($envelope->content ?? null) ? $envelope->content : [] as $block) {
                if (is_object($block) && ($block->type ?? null) === 'tool_use' && ($block->name ?? null) === 'output_structured_data') {
                    return json_encode($block->input ?? null, JSON_THROW_ON_ERROR);
                }
            }
        } catch (\JsonException) {
            throw new InvalidAiOutputException('The provider returned malformed structured JSON.');
        }

        throw new InvalidAiOutputException('The provider did not preserve original structured JSON.');
    }

    private function providerOptionsKey(TextProvider $provider, TextGenerationOptions $options): string
    {
        if ($options->agent instanceof DocumentDetectionAgent
            || $options->agent instanceof InlineSchemaAgent
            || $options->agent instanceof OcrAgent) {
            return $provider->name();
        }

        return $provider->driver() === 'openai-compatible'
            ? $provider->name()
            : $provider->driver();
    }

    /**
     * Package extraction is synchronous; unrelated native streams pass through untouched.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
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

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
