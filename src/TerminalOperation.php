<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction;

enum TerminalOperation: string
{
    case Text = 'text';
    case Extract = 'extract';
}
