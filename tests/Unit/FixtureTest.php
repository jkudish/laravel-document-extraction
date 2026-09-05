<?php

declare(strict_types=1);

it('loads package fixtures through the TIA watch boundary', function (): void {
    recordTiaExecution('fixture');

    expect(trim((string) file_get_contents(__DIR__.'/../Fixtures/source.txt')))->toBe('fixture source');
});
