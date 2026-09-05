<?php

declare(strict_types=1);

it('loads package prompts through the TIA watch boundary', function (): void {
    recordTiaExecution('prompt');

    expect(trim((string) file_get_contents(__DIR__.'/../../resources/prompts/extraction.txt')))
        ->toBe('Extract only facts present in the supplied document.');
});
