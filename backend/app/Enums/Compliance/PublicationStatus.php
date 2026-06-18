<?php

namespace App\Enums\Compliance;

enum PublicationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Deprecated = 'deprecated';
    case Retired = 'retired';
}
