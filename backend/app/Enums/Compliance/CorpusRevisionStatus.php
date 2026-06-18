<?php

namespace App\Enums\Compliance;

enum CorpusRevisionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Superseded = 'superseded';
    case RolledBack = 'rolled_back';
}
