<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Results;

enum EvidenceOrigin: string
{
    case Live = 'live';
    case Simulated = 'simulated';
}
