<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

final class FakeOutput
{
    public string $contents = '';

    public function __invoke(string $contents, bool $stderr): void
    {
        $this->contents .= $contents;
    }
}
