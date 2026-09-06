<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Jkudish\DocumentExtraction\Exceptions\AiExecutionException;
use Jkudish\DocumentExtraction\Exceptions\PreparationException;
use Jkudish\DocumentExtraction\Preparation\Deadline;
use Jkudish\DocumentExtraction\Results\CallRecord;
use Jkudish\DocumentExtraction\Results\CostSummary;

final class AiExecutionSession
{
    private int $attempts = 0;

    private int $retainedBytes;

    /** @var list<CallRecord> */
    private array $calls = [];

    public function __construct(
        private readonly Deadline $deadline,
        private readonly int $attemptLimit,
        private readonly int $attemptTimeout,
        public readonly int $outputLimit,
        public readonly int $attachmentLimit,
        int $initialRetainedBytes = 0,
    ) {
        $this->retainedBytes = $initialRetainedBytes;
    }

    public function beginAttempt(): int
    {
        if ($this->attempts >= $this->attemptLimit) {
            throw AiExecutionException::make(
                'ai_attempt_limit_exceeded',
                'The extraction exhausted its configured AI attempt budget.',
            );
        }

        $this->attempts++;

        return $this->remainingSeconds();
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

    /** @param list<int> $pages */
    public function record(
        string $stage,
        array $pages,
        string $provider,
        string $model,
        string $outcome,
        int $durationMilliseconds,
    ): void {
        $this->calls[] = new CallRecord(
            stage: $stage,
            outcome: $outcome,
            pages: $pages,
            provider: $provider,
            model: $model,
            durationMilliseconds: $durationMilliseconds,
        );
    }

    /** @return list<CallRecord> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function costSummary(): CostSummary
    {
        return new CostSummary(
            unpricedCalls: array_keys($this->calls),
            complete: false,
        );
    }
}
