<?php

namespace App\Enums\Compliance;

enum DomainBatchStatus: string
{
    case Draft = 'draft';
    case Curated = 'curated';
    case Validated = 'validated';
    case Approved = 'approved';
    case Imported = 'imported';
}
