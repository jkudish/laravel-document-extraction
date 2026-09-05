<?php

declare(strict_types=1);

it('loads package schemas through the TIA watch boundary', function (): void {
    recordTiaExecution('schema');

    $schema = json_decode(
        (string) file_get_contents(__DIR__.'/../../resources/schemas/document.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($schema)->toBe([
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'type' => 'object',
    ]);
});
