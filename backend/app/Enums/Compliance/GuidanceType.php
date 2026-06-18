<?php

namespace App\Enums\Compliance;

enum GuidanceType: string
{
    case Implementation = 'implementation';
    case Assessment = 'assessment';
    case AuditorNote = 'auditor_note';
}
