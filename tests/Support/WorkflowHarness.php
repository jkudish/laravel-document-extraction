<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use Jkudish\DocumentExtraction\Dev\PrWorkflow\Workflow;

final readonly class WorkflowHarness
{
    public FakeCommandRunner $runner;

    public Workflow $workflow;

    public FakeOutput $output;

    /** @var array<string, string> */
    public array $environment;

    public function __construct(public string $temporaryDirectory, bool $withToken = true)
    {
        $canaries = self::canaries();
        $this->runner = new FakeCommandRunner($temporaryDirectory.'/receipts');
        $this->output = new FakeOutput;
        $this->environment = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'GH_TOKEN' => $canaries['ambient'],
            'GH_SIGNOFF_TOKEN' => $withToken ? $canaries['dedicated'] : '',
            'GH_HOST' => 'adversarial.invalid',
            'GH_REPO' => 'attacker/redirected-repository',
            'OPENAI_API_KEY' => $canaries['provider'],
        ];
        $this->workflow = new Workflow(
            dirname(__DIR__, 2),
            $this->runner,
            $this->environment,
            static fn (): string => '2026-09-05T09:00:00Z',
            true,
            $this->output,
        );
    }

    /**
     * @return array{ambient: string, dedicated: string, provider: string}
     */
    public static function canaries(): array
    {
        return [
            'ambient' => implode('-', ['ambient', 'token', 'must', 'not', 'pass']),
            'dedicated' => implode('-', ['dedicated', 'token']),
            'provider' => implode('-', ['provider', 'secret', 'must', 'not', 'pass']),
        ];
    }

    /**
     * @return array{sha: string, receiptPath: string, receipt: array<string, mixed>}
     */
    public function check(): array
    {
        return $this->workflow->check($this->runner->base);
    }

    public function remove(): void
    {
        if (! is_dir($this->temporaryDirectory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($this->temporaryDirectory);
    }
}
