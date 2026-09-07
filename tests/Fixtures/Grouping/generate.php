<?php

declare(strict_types=1);

const PAGE_WIDTH = 612;
const PAGE_HEIGHT = 792;

$fixtures = [
    [
        'id' => 'single-three-page-document',
        'file' => 'bundle-01.pdf',
        'split' => 'prompt-example',
        'description' => 'One complete three-page inspection report.',
        'pages' => [
            ['MERIDIAN ENGINEERING', 'SAFETY INSPECTION REPORT', 'Report SI-2048', 'Riverside Warehouse', 'Inspection date: 2026-08-12', 'Page 1 of 3', '', 'Scope', 'Quarterly inspection of loading bays and fire exits.'],
            ['MERIDIAN ENGINEERING', 'SAFETY INSPECTION REPORT', 'Report SI-2048', 'Riverside Warehouse', 'Page 2 of 3', '', 'Findings', 'Bay 2 guard rail requires repainting.', 'All fire exits were unobstructed.'],
            ['MERIDIAN ENGINEERING', 'SAFETY INSPECTION REPORT', 'Report SI-2048', 'Riverside Warehouse', 'Page 3 of 3', '', 'Sign-off', 'Inspector: Morgan Lee', 'Next inspection: 2026-11-12'],
        ],
        'groups' => [[1, 2, 3]],
    ],
    [
        'id' => 'three-single-page-documents',
        'file' => 'bundle-02.pdf',
        'split' => 'prompt-example',
        'description' => 'Three unrelated complete single-page documents.',
        'pages' => [
            ['CEDAR TELECOM', 'INVOICE CT-8821', 'Bill to: Harbor Books', 'Invoice date: 2026-08-01', 'Internet service: $89.00', 'Total due: $89.00'],
            ['WESTFIELD COMMUNITY CENTRE', 'MEMBERSHIP CONFIRMATION', 'Member: Taylor Morgan', 'Membership: Autumn 2026', 'Issued: 2026-08-04'],
            ['PACIFIC PARKING', 'PAYMENT RECEIPT PP-4407', 'Location: Pier 6', 'Paid: $18.00', 'Date: 2026-08-09'],
        ],
        'groups' => [[1], [2], [3]],
    ],
    [
        'id' => 'mixed-document-lengths',
        'file' => 'bundle-03.pdf',
        'split' => 'prompt-example',
        'description' => 'A two-page claim, a one-page utility bill, and a three-page lease addendum.',
        'pages' => [
            ['SUMMIT INSURANCE', 'CLAIM SUMMARY', 'Claim CL-3915', 'Policy P-1840', 'Loss date: 2026-07-18', 'Page 1 of 2', '', 'Reported water damage in kitchen.'],
            ['SUMMIT INSURANCE', 'CLAIM SUMMARY', 'Claim CL-3915', 'Policy P-1840', 'Page 2 of 2', '', 'Assessment', 'Approved repair allowance: $2,450.00'],
            ['CITY WATER', 'UTILITY STATEMENT CW-7712', 'Account 500184', 'Service period: July 2026', 'Amount due: $64.20'],
            ['MAPLE PROPERTY MANAGEMENT', 'LEASE ADDENDUM LA-220', 'Tenant: Jordan Chen', 'Unit: 4B', 'Page 1 of 3', '', 'Purpose', 'Updated bicycle storage terms.'],
            ['MAPLE PROPERTY MANAGEMENT', 'LEASE ADDENDUM LA-220', 'Tenant: Jordan Chen', 'Unit: 4B', 'Page 2 of 3', '', 'Storage rules', 'Bicycles must use assigned rack 18.'],
            ['MAPLE PROPERTY MANAGEMENT', 'LEASE ADDENDUM LA-220', 'Tenant: Jordan Chen', 'Unit: 4B', 'Page 3 of 3', '', 'Acknowledgement', 'Effective: 2026-09-01'],
        ],
        'groups' => [[1, 2], [3], [4, 5, 6]],
    ],
    [
        'id' => 'same-issuer-invoices',
        'file' => 'bundle-04.pdf',
        'split' => 'holdout',
        'description' => 'Two visually similar two-page invoices from the same issuer, separated by natural invoice identifiers.',
        'pages' => [
            ['NORTHWIND OFFICE SUPPLY', 'INVOICE NO-10481', 'Bill to: Alpine Design', 'Invoice date: 2026-08-03', 'Page 1 of 2', '', 'Desk chairs (4): $720.00'],
            ['NORTHWIND OFFICE SUPPLY', 'INVOICE NO-10481', 'Bill to: Alpine Design', 'Page 2 of 2', '', 'Subtotal: $720.00', 'Tax: $86.40', 'Total: $806.40'],
            ['NORTHWIND OFFICE SUPPLY', 'INVOICE NO-10482', 'Bill to: Alpine Design', 'Invoice date: 2026-08-17', 'Page 1 of 2', '', 'Filing cabinets (2): $510.00'],
            ['NORTHWIND OFFICE SUPPLY', 'INVOICE NO-10482', 'Bill to: Alpine Design', 'Page 2 of 2', '', 'Subtotal: $510.00', 'Tax: $61.20', 'Total: $571.20'],
        ],
        'groups' => [[1, 2], [3, 4]],
    ],
    [
        'id' => 'blank-separator',
        'file' => 'bundle-05.pdf',
        'split' => 'prompt-example',
        'description' => 'Two documents separated by a truly blank page that remains unassigned.',
        'pages' => [
            ['CITY OF FAIRVIEW', 'RENOVATION PERMIT RP-650', 'Property: 88 Alder Street', 'Issued: 2026-08-05', 'Page 1 of 2'],
            ['CITY OF FAIRVIEW', 'RENOVATION PERMIT RP-650', 'Property: 88 Alder Street', 'Page 2 of 2', '', 'Approved work: interior partition changes.'],
            [],
            ['COASTAL FREIGHT', 'SHIPPING MANIFEST SF-901', 'Origin: Vancouver', 'Destination: Victoria', 'Page 1 of 2'],
            ['COASTAL FREIGHT', 'SHIPPING MANIFEST SF-901', 'Page 2 of 2', '', 'Crates: 6', 'Total weight: 840 kg'],
        ],
        'groups' => [[1, 2], [4, 5]],
        'unassigned' => [3],
        'unassigned_reasons' => ['3' => 'The page is truly blank and carries no observable document identity.'],
    ],
    [
        'id' => 'ambiguous-orphan',
        'file' => 'bundle-06.pdf',
        'split' => 'holdout',
        'description' => 'Two complete single-page documents followed by an orphan continuation page with no observable identity.',
        'pages' => [
            ['BRIGHT HORIZON FOUNDATION', 'DONATION RECEIPT BH-771', 'Donor: Casey Park', 'Received: $125.00', 'Date: 2026-08-08'],
            ['LAKESIDE CLINIC', 'APPOINTMENT CONFIRMATION', 'Patient: Riley Singh', 'Date: 2026-09-14', 'Time: 10:30 AM'],
            ['TERMS AND CONDITIONS - CONTINUED', '', 'Changes must be submitted in writing.', 'Records are retained according to applicable policy.', 'Thank you for reviewing these terms.'],
        ],
        'groups' => [[1], [2]],
        'unassigned' => [3],
        'ambiguous' => [3],
        'unassigned_reasons' => ['3' => 'The continuation page has no issuer, recipient, reference number, page count, or other evidence tying it to either preceding document.'],
    ],
    [
        'id' => 'scan-like-raster',
        'file' => 'bundle-07.pdf',
        'split' => 'holdout',
        'description' => 'Two two-page documents represented only as raster images with no PDF text layer.',
        'raster' => true,
        'pages' => [
            ['SERVICE REPORT SR-710', 'CLIENT HARBOR CAFE', 'PAGE 1 OF 2', 'VISIT 2026-08-11', 'REFRIGERATOR INSPECTION'],
            ['SERVICE REPORT SR-710', 'CLIENT HARBOR CAFE', 'PAGE 2 OF 2', 'TEMPERATURE WITHIN RANGE', 'TECHNICIAN A MARTIN'],
            ['FIELD LOG FL-315', 'SITE NORTH TRAIL', 'PAGE 1 OF 2', 'SURVEY 2026-08-20', 'WEATHER CLEAR'],
            ['FIELD LOG FL-315', 'SITE NORTH TRAIL', 'PAGE 2 OF 2', 'MARKER POSTS CHECKED', 'OBSERVER J REYES'],
        ],
        'groups' => [[1, 2], [3, 4]],
    ],
    [
        'id' => 'non-financial-documents',
        'file' => 'bundle-08.pdf',
        'split' => 'prompt-example',
        'description' => 'Meeting minutes and a course handbook demonstrate non-financial grouping.',
        'pages' => [
            ['RIVER DISTRICT GARDEN CLUB', 'MEETING MINUTES', 'Meeting: 2026-08-06', 'Page 1 of 2', '', 'Agenda', 'Community greenhouse maintenance.'],
            ['RIVER DISTRICT GARDEN CLUB', 'MEETING MINUTES', 'Meeting: 2026-08-06', 'Page 2 of 2', '', 'Decision', 'Volunteer day scheduled for September 12.'],
            ['NORTH SHORE FIRST AID', 'COURSE HANDBOOK FA-102', 'Autumn 2026', 'Page 1 of 3', '', 'Emergency scene assessment.'],
            ['NORTH SHORE FIRST AID', 'COURSE HANDBOOK FA-102', 'Autumn 2026', 'Page 2 of 3', '', 'CPR sequence and safety notes.'],
            ['NORTH SHORE FIRST AID', 'COURSE HANDBOOK FA-102', 'Autumn 2026', 'Page 3 of 3', '', 'Assessment checklist and course contacts.'],
        ],
        'groups' => [[1, 2], [3, 4, 5]],
    ],
];

$directory = __DIR__;
$manifest = [
    'schema_version' => 1,
    'provenance' => 'Entirely synthetic content authored for this repository; no private or production documents.',
    'page_numbering' => 'One-based physical PDF page numbers.',
    'splits' => [
        'prompt-example' => 'May be used as examples while developing grouping prompts.',
        'holdout' => 'Must not be used as prompt-tuning examples or exposed with expected labels during evaluation.',
    ],
    'fixtures' => [],
];

foreach ($fixtures as $fixture) {
    $path = $directory.'/'.$fixture['file'];
    $pdf = ($fixture['raster'] ?? false)
        ? rasterPdf($fixture['pages'])
        : textPdf($fixture['pages']);
    file_put_contents($path, $pdf);

    $unassigned = $fixture['unassigned'] ?? [];
    $manifest['fixtures'][] = [
        'id' => $fixture['id'],
        'file' => $fixture['file'],
        'split' => $fixture['split'],
        'description' => $fixture['description'],
        'page_count' => count($fixture['pages']),
        'sha256' => hash('sha256', $pdf),
        'size' => strlen($pdf),
        'content' => ($fixture['raster'] ?? false) ? 'raster-only' : 'text-layer',
        'expected' => [
            'groups' => $fixture['groups'],
            'unassigned_pages' => $unassigned,
            'ambiguous_pages' => $fixture['ambiguous'] ?? [],
            'unassigned_reasons' => (object) ($fixture['unassigned_reasons'] ?? []),
        ],
    ];
}

file_put_contents(
    $directory.'/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);

/** @param list<list<string>> $pages */
function textPdf(array $pages): string
{
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
    $kids = [];
    $next = 4;

    foreach ($pages as $lines) {
        $pageObject = $next++;
        $streamObject = $next++;
        $kids[] = $pageObject.' 0 R';
        $stream = pageTextStream($lines);
        $objects[$pageObject] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>',
            PAGE_WIDTH,
            PAGE_HEIGHT,
            $streamObject,
        );
        $objects[$streamObject] = streamObject($stream);
    }

    $objects[2] = sprintf('<< /Type /Pages /Count %d /Kids [%s] >>', count($pages), implode(' ', $kids));
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    return serializePdf($objects);
}

/** @param list<string> $lines */
function pageTextStream(array $lines): string
{
    if ($lines === []) {
        return '';
    }

    $commands = ['BT', '/F1 18 Tf', '72 720 Td'];

    foreach ($lines as $index => $line) {
        if ($index === 1) {
            $commands[] = '/F1 14 Tf';
        }

        $commands[] = '('.strtr($line, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']).') Tj';
        $commands[] = '0 -28 Td';
    }

    $commands[] = 'ET';

    return implode("\n", $commands)."\n";
}

/** @param list<list<string>> $pages */
function rasterPdf(array $pages): string
{
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
    $kids = [];
    $next = 3;

    foreach ($pages as $lines) {
        $pageObject = $next++;
        $streamObject = $next++;
        $imageObject = $next++;
        $kids[] = $pageObject.' 0 R';
        $pixels = rasterPage($lines);
        $compressed = gzcompress($pixels, 9);

        if (! is_string($compressed)) {
            throw new RuntimeException('Unable to compress raster fixture.');
        }

        $objects[$pageObject] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /XObject << /Scan %d 0 R >> >> /Contents %d 0 R >>',
            PAGE_WIDTH,
            PAGE_HEIGHT,
            $imageObject,
            $streamObject,
        );
        $objects[$streamObject] = streamObject("q\n612 0 0 792 0 0 cm\n/Scan Do\nQ\n");
        $objects[$imageObject] = sprintf(
            "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
            PAGE_WIDTH,
            PAGE_HEIGHT,
            strlen($compressed),
            $compressed,
        );
    }

    $objects[2] = sprintf('<< /Type /Pages /Count %d /Kids [%s] >>', count($pages), implode(' ', $kids));

    return serializePdf($objects);
}

/** @param list<string> $lines */
function rasterPage(array $lines): string
{
    $rows = array_fill(0, PAGE_HEIGHT, str_repeat("\xF4", PAGE_WIDTH));

    for ($y = 48; $y < 744; $y += 32) {
        for ($x = 40; $x < 572; $x++) {
            $rows[$y] = substr_replace($rows[$y], "\xDE", $x, 1);
        }
    }

    foreach ($lines as $lineNumber => $line) {
        drawBitmapText($rows, strtoupper($line), 54, 72 + ($lineNumber * 112), $lineNumber === 0 ? 4 : 3);
    }

    return implode('', $rows);
}

/** @param array<int, string> $rows */
function drawBitmapText(array &$rows, string $text, int $left, int $top, int $scale): void
{
    $glyphs = bitmapGlyphs();
    $x = $left;

    foreach (str_split($text) as $character) {
        $glyph = $glyphs[$character] ?? $glyphs['?'];

        foreach ($glyph as $row => $bits) {
            for ($column = 0; $column < 5; $column++) {
                if ($bits[$column] !== '1') {
                    continue;
                }

                for ($dy = 0; $dy < $scale; $dy++) {
                    for ($dx = 0; $dx < $scale; $dx++) {
                        $pixelY = $top + ($row * $scale) + $dy;
                        $pixelX = $x + ($column * $scale) + $dx;
                        $rows[$pixelY] = substr_replace($rows[$pixelY], "\x18", $pixelX, 1);
                    }
                }
            }
        }

        $x += 6 * $scale;

        if ($x + (5 * $scale) >= PAGE_WIDTH - $left) {
            break;
        }
    }
}

/** @return array<array-key, array<int, string>> */
function bitmapGlyphs(): array
{
    $encoded = [
        'A' => '01110/10001/10001/11111/10001/10001/10001', 'B' => '11110/10001/10001/11110/10001/10001/11110',
        'C' => '01111/10000/10000/10000/10000/10000/01111', 'D' => '11110/10001/10001/10001/10001/10001/11110',
        'E' => '11111/10000/10000/11110/10000/10000/11111', 'F' => '11111/10000/10000/11110/10000/10000/10000',
        'G' => '01111/10000/10000/10111/10001/10001/01111', 'H' => '10001/10001/10001/11111/10001/10001/10001',
        'I' => '11111/00100/00100/00100/00100/00100/11111', 'J' => '00111/00010/00010/00010/10010/10010/01100',
        'K' => '10001/10010/10100/11000/10100/10010/10001', 'L' => '10000/10000/10000/10000/10000/10000/11111',
        'M' => '10001/11011/10101/10101/10001/10001/10001', 'N' => '10001/11001/10101/10011/10001/10001/10001',
        'O' => '01110/10001/10001/10001/10001/10001/01110', 'P' => '11110/10001/10001/11110/10000/10000/10000',
        'Q' => '01110/10001/10001/10001/10101/10010/01101', 'R' => '11110/10001/10001/11110/10100/10010/10001',
        'S' => '01111/10000/10000/01110/00001/00001/11110', 'T' => '11111/00100/00100/00100/00100/00100/00100',
        'U' => '10001/10001/10001/10001/10001/10001/01110', 'V' => '10001/10001/10001/10001/10001/01010/00100',
        'W' => '10001/10001/10001/10101/10101/10101/01010', 'X' => '10001/10001/01010/00100/01010/10001/10001',
        'Y' => '10001/10001/01010/00100/00100/00100/00100', 'Z' => '11111/00001/00010/00100/01000/10000/11111',
        '0' => '01110/10001/10011/10101/11001/10001/01110', '1' => '00100/01100/00100/00100/00100/00100/01110',
        '2' => '01110/10001/00001/00010/00100/01000/11111', '3' => '11110/00001/00001/01110/00001/00001/11110',
        '4' => '00010/00110/01010/10010/11111/00010/00010', '5' => '11111/10000/10000/11110/00001/00001/11110',
        '6' => '01110/10000/10000/11110/10001/10001/01110', '7' => '11111/00001/00010/00100/01000/01000/01000',
        '8' => '01110/10001/10001/01110/10001/10001/01110', '9' => '01110/10001/10001/01111/00001/00001/01110',
        ' ' => '00000/00000/00000/00000/00000/00000/00000', '-' => '00000/00000/00000/11111/00000/00000/00000',
        ':' => '00000/00100/00100/00000/00100/00100/00000', '?' => '01110/10001/00001/00010/00100/00000/00100',
    ];

    $glyphs = [];

    foreach ($encoded as $character => $glyph) {
        $glyphs[$character] = explode('/', $glyph);
    }

    return $glyphs;
}

function streamObject(string $stream): string
{
    return sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($stream), $stream);
}

/** @param array<int, string> $objects */
function serializePdf(array $objects): string
{
    ksort($objects);
    $pdf = "%PDF-1.4\n% synthetic fixture\n";
    $offsets = [0];

    foreach ($objects as $number => $object) {
        $offsets[$number] = strlen($pdf);
        $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $number, $object);
    }

    $xref = strlen($pdf);
    $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f\n", count($objects) + 1);

    for ($number = 1; $number <= count($objects); $number++) {
        $pdf .= sprintf("%010d 00000 n\n", $offsets[$number]);
    }

    $pdf .= sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);

    return $pdf;
}
