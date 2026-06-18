<?php

namespace App\Enums\Compliance;

enum SourceDocumentStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Superseded = 'superseded';
    case Archived = 'archived';
}
