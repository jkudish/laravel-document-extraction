<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Illuminate\Support\Str;
use Jkudish\DocumentExtraction\Exceptions\AiExecutionException;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Jkudish\DocumentExtraction\ExtractionInvocation;
use Jkudish\DocumentExtraction\Preparation\PreparedDocument;
use Jkudish\DocumentExtraction\Preparation\PreparedPage;
use Jkudish\DocumentExtraction\Results\DocumentResult;
use Jkudish\DocumentExtraction\Results\EvidenceOrigin;
use Jkudish\DocumentExtraction\Results\ExtractionError;
use Jkudish\DocumentExtraction\Results\ExtractionResult;
use Jkudish\DocumentExtraction\Results\PageResult;
use Jkudish\DocumentExtraction\Source\SourceSnapshot;
use Jkudish\LaravelAiPricing\ResponseCostResolver;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\Image;

final readonly class NativeAiProcessor
{
    public function __construct(
        private NativeAiExecutor $ai,
        private ResponseCostResolver $pricing,
    ) {}

    public function text(
        ExtractionInvocation $invocation,
        SourceSnapshot $snapshot,
        PreparedDocument $prepared,
    ): ExtractionResult {
        $agent = new OcrAgent(
            $this->packageOptions($invocation, 'ocr'),
            $invocation->configuration['middleware'],
        );
        $session = $this->session($invocation, $snapshot, $agent, strlen($prepared->directText ?? ''));
        $pageResults = [];
        $errors = [];
        $activePages = [];

        try {
            foreach ($prepared->pages as $page) {
                $activePages = [$page->page];

                if (! $page->needsOcr) {
                    $pageResults[] = new PageResult($page->page, $page->text ?? '');

                    continue;
                }

                try {
                    $this->ensureAttachmentLimit($invocation, $page->visualBytes ?? 0);
                    $result = $this->ai->prompt(
                        session: $session,
                        agent: $agent,
                        stage: 'ocr',
                        pages: [$page->page],
                        prompt: "Transcribe original source page {$page->page}. Return only the transcription.",
                        attachments: [$this->pageAttachment($page)],
                        provider: $invocation->provider,
                        model: $invocation->model,
                        timeout: $invocation->timeout,
                    );
                    $pageResults[] = new PageResult($page->page, $result->text ?? '');
                } catch (InvalidAiOutputException $exception) {
                    $error = $this->error('invalid_output', $exception->getMessage(), [$page->page], $exception->path);
                    $errors[] = $error;
                    $pageResults[] = new PageResult($page->page, $page->text, complete: false, error: $error);
                } catch (AiException) {
                    $error = $this->error(
                        'provider_failed',
                        'The AI provider could not transcribe this page.',
                        [$page->page],
                        retryable: true,
                    );
                    $errors[] = $error;
                    $pageResults[] = new PageResult($page->page, $page->text, complete: false, error: $error);
                }
            }

            $activePages = $prepared->selectedPages ?? [];
            $text = $this->pageText($pageResults);
            $session->ensureFinalOutputFits($text);
            $firstError = $errors[0] ?? null;

            return new ExtractionResult(
                documents: [new DocumentResult(
                    pages: $prepared->selectedPages,
                    text: $text,
                    complete: $errors === [],
                    error: $firstError,
                )],
                sourceSha256: $snapshot->sha256,
                mediaType: $prepared->mediaType,
                pageCount: $prepared->pageCount,
                pages: $pageResults,
                calls: $session->calls(),
                errors: $errors,
                cost: $session->costSummary(),
                coverageComplete: $errors === [],
                evidenceOrigin: $session->evidenceOrigin,
            );
        } catch (AiExecutionException|ConfigurationException $exception) {
            $globalError = $this->error($exception->errorCode, $exception->getMessage(), $activePages);
            $errors[] = $globalError;

            throw $exception->withPartialResult(new ExtractionResult(
                documents: [new DocumentResult(
                    pages: $prepared->selectedPages,
                    text: $this->pageText($pageResults),
                    complete: false,
                    error: $globalError,
                )],
                sourceSha256: $snapshot->sha256,
                mediaType: $prepared->mediaType,
                pageCount: $prepared->pageCount,
                pages: $pageResults,
                calls: $session->calls(),
                errors: $errors,
                cost: $session->costSummary(),
                coverageComplete: false,
                evidenceOrigin: $session->evidenceOrigin,
            ));
        }
    }

    public function extract(
        ExtractionInvocation $invocation,
        SourceSnapshot $snapshot,
        PreparedDocument $prepared,
        ?Agent $applicationAgent,
    ): ExtractionResult {
        $agent = $applicationAgent ?? new InlineSchemaAgent(
            $invocation->schema ?? throw new \LogicException('An inline extraction schema is required.'),
            $invocation->instructions,
            $this->packageOptions($invocation, null),
            $invocation->configuration['middleware'],
        );
        $session = $this->session($invocation, $snapshot, $agent);
        $pages = $prepared->selectedPages ?? [];

        try {
            $this->ensureAttachmentLimit($invocation, $prepared->inlineAttachmentBytes());
            $result = $this->ai->prompt(
                session: $session,
                agent: $agent,
                stage: 'extraction',
                pages: $pages,
                prompt: $this->structuredPrompt($prepared),
                attachments: $this->attachments($prepared),
                provider: $invocation->provider,
                model: $invocation->model,
                timeout: $invocation->timeout,
            );

            return new ExtractionResult(
                documents: [new DocumentResult(
                    pages: $prepared->selectedPages,
                    data: $result->data ?? throw new \LogicException('Structured extraction returned no validated data.'),
                )],
                sourceSha256: $snapshot->sha256,
                mediaType: $prepared->mediaType,
                pageCount: $prepared->pageCount,
                calls: $session->calls(),
                cost: $session->costSummary(),
                evidenceOrigin: $session->evidenceOrigin,
            );
        } catch (InvalidAiOutputException $exception) {
            return $this->failedExtraction(
                $snapshot,
                $prepared,
                $session,
                $this->error('invalid_output', $exception->getMessage(), $pages, $exception->path),
            );
        } catch (AiException) {
            return $this->failedExtraction(
                $snapshot,
                $prepared,
                $session,
                $this->error(
                    'provider_failed',
                    'The AI provider could not complete structured extraction.',
                    $pages,
                    retryable: true,
                ),
            );
        } catch (AiExecutionException|ConfigurationException $exception) {
            throw $exception->withPartialResult($this->failedExtraction(
                $snapshot,
                $prepared,
                $session,
                $this->error($exception->errorCode, $exception->getMessage(), $pages),
            ));
        }
    }

    private function session(
        ExtractionInvocation $invocation,
        SourceSnapshot $snapshot,
        Agent $agent,
        int $initialRetainedBytes = 0,
    ): AiExecutionSession {
        return new AiExecutionSession(
            invocationId: (string) Str::uuid7(),
            deadline: $snapshot->deadline,
            attemptLimit: $invocation->configuration['limits']['ai_attempts'],
            attemptTimeout: $invocation->configuration['limits']['ai_attempt_timeout'],
            outputLimit: $invocation->configuration['limits']['retained_output_bytes'],
            attachmentLimit: $invocation->configuration['limits']['inline_attachment_bytes'],
            pricing: $this->pricing,
            evidenceOrigin: Ai::hasFakeGatewayFor($agent::class)
                ? EvidenceOrigin::Simulated
                : EvidenceOrigin::Live,
            initialRetainedBytes: $initialRetainedBytes,
        );
    }

    private function pageAttachment(PreparedPage $page): Image
    {
        if ($page->visualPath === null) {
            throw new \LogicException('A page requiring OCR has no prepared visual attachment.');
        }

        return Image::fromPath($page->visualPath, 'image/png')->as("page-{$page->page}.png");
    }

    /** @return list<Image> */
    private function attachments(PreparedDocument $prepared): array
    {
        $attachments = [];

        foreach ($prepared->pages as $page) {
            if ($page->visualPath !== null) {
                $attachments[] = $this->pageAttachment($page);
            }
        }

        return $attachments;
    }

    private function structuredPrompt(PreparedDocument $prepared): string
    {
        if ($prepared->hasVisualPages()) {
            $pages = implode(', ', $prepared->selectedPages ?? []);

            return "Extract the requested structured facts from the attached normalized source pages. The attachment names preserve original page numbers: {$pages}.";
        }

        return "Extract the requested structured facts from this normalized source content:\n\n".($prepared->directText ?? '');
    }

    /** @param list<PageResult> $pages */
    private function pageText(array $pages): string
    {
        return implode("\f", array_map(
            static fn (PageResult $page): string => $page->text ?? '',
            $pages,
        ));
    }

    private function ensureAttachmentLimit(ExtractionInvocation $invocation, int $bytes): void
    {
        if ($bytes > $invocation->configuration['limits']['inline_attachment_bytes']) {
            throw AiExecutionException::make(
                'input_too_large_for_model',
                'Prepared visual attachments exceed the configured pre-base64 AI request limit.',
            );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function packageOptions(ExtractionInvocation $invocation, ?string $purpose): array
    {
        $root = $invocation->configuration['options'];
        $purposeConfiguration = $purpose === null ? null : $invocation->configuration[$purpose];
        $specific = is_array($purposeConfiguration) && is_array($purposeConfiguration['options'] ?? null)
            ? $purposeConfiguration['options']
            : [];

        /** @var array<string, array<string, mixed>> */
        return array_replace($root, $specific);
    }

    /** @param list<int> $pages */
    private function error(
        string $code,
        string $message,
        array $pages,
        ?string $path = null,
        bool $retryable = false,
    ): ExtractionError {
        return new ExtractionError($code, $message, $pages, $path, $retryable);
    }

    private function failedExtraction(
        SourceSnapshot $snapshot,
        PreparedDocument $prepared,
        AiExecutionSession $session,
        ExtractionError $error,
    ): ExtractionResult {
        return new ExtractionResult(
            documents: [new DocumentResult(
                pages: $prepared->selectedPages,
                complete: false,
                error: $error,
            )],
            sourceSha256: $snapshot->sha256,
            mediaType: $prepared->mediaType,
            pageCount: $prepared->pageCount,
            calls: $session->calls(),
            errors: [$error],
            cost: $session->costSummary(),
            coverageComplete: false,
            evidenceOrigin: $session->evidenceOrigin,
        );
    }
}
