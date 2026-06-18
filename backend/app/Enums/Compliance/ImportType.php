<?php

namespace App\Enums\Compliance;

enum ImportType: string
{
    case DryRun = 'dry_run';
    case Import = 'import';
    case Rollback = 'rollback';
}
