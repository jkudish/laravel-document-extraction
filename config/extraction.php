<?php

declare(strict_types=1);

return [
    'provider' => env('EXTRACTION_PROVIDER'),
    'model' => env('EXTRACTION_MODEL'),
    'timeout' => 120,
    'options' => [],
    'middleware' => [],

    'ocr' => [
        'provider' => null,
        'model' => null,
        'timeout' => null,
        'options' => [],
    ],

    'detection' => [
        'provider' => null,
        'model' => null,
        'timeout' => null,
        'options' => [],
    ],

    'preparation' => [
        'render_dpi' => 150,
        'native_memory_bytes' => 1_073_741_824,
        'php_memory_bytes' => 402_653_184,
        'binaries' => [
            'pdfinfo' => 'pdfinfo',
            'pdfimages' => 'pdfimages',
            'pdftoppm' => 'pdftoppm',
            'pdftotext' => 'pdftotext',
            'prlimit' => 'prlimit',
            'php' => PHP_BINARY,
        ],
    ],

    'limits' => [
        'source_bytes' => 100_000_000,
        'physical_pages' => 100,
        'decoded_pixels_per_page' => 50_000_000,
        'parser_process_timeout' => 30,
        'ai_attempt_timeout' => 120,
        'invocation_deadline' => 600,
        'retained_output_bytes' => 10_000_000,
        'temporary_bytes' => 512_000_000,
        'ai_attempts' => 128,
        'inline_attachment_bytes' => 20_000_000,
    ],
];
