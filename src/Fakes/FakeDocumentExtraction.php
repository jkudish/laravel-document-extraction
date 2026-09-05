<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Fakes;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Collection;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Exceptions\StrayExtractionException;
use Jkudish\DocumentExtraction\ExtractionInvocation;
use Jkudish\DocumentExtraction\Results\ExtractionResult;
use PHPUnit\Framework\Assert as PHPUnit;
use UnexpectedValueException;

final class FakeDocumentExtraction extends DocumentExtraction
{
    /** @var list<ExtractionInvocation> */
    private array $invocations = [];

    /** @var list<ExtractionResult>|Closure(ExtractionInvocation): mixed */
    private array|Closure $results;

    /**
     * @param  list<ExtractionResult>|Closure(ExtractionInvocation): mixed  $results
     */
    public function __construct(
        Repository $config,
        FilesystemFactory $filesystems,
        Container $container,
        array|Closure $results = [],
    ) {
        parent::__construct($config, $filesystems, $container);
        $this->results = $results;
    }

    public function execute(ExtractionInvocation $invocation): ExtractionResult
    {
        $this->invocations[] = $invocation;

        if ($this->results instanceof Closure) {
            $result = ($this->results)($invocation);

            if (! $result instanceof ExtractionResult) {
                throw new UnexpectedValueException('Extraction fake callbacks must return an ExtractionResult.');
            }

            return $result->asSimulated();
        }

        $result = array_shift($this->results);

        if (! $result instanceof ExtractionResult) {
            throw StrayExtractionException::make(
                'stray_extraction',
                'An extraction was invoked without a configured fake result.',
            );
        }

        return $result->asSimulated();
    }

    public function assertCalled(callable|int|null $callback = null): void
    {
        if (is_int($callback)) {
            PHPUnit::assertCount($callback, $this->invocations, "Expected {$callback} extraction invocation(s).");

            return;
        }

        if ($callback === null) {
            PHPUnit::assertNotEmpty($this->invocations, 'An expected extraction was not invoked.');

            return;
        }

        PHPUnit::assertTrue(
            collect($this->invocations)->contains(
                static fn (ExtractionInvocation $invocation): bool => $callback($invocation) === true,
            ),
            'An expected extraction invocation was not recorded.',
        );
    }

    public function assertNothingCalled(): void
    {
        PHPUnit::assertEmpty($this->invocations, 'Unexpected extraction invocations were recorded.');
    }

    /** @return Collection<int, ExtractionInvocation> */
    public function recorded(): Collection
    {
        return collect($this->invocations);
    }
}
