<?php

namespace App\Enums\Compliance;

enum ImportRunStatus: string
{
    case Pending = 'pending';
    case Validating = 'validating';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
}
