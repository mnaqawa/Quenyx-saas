<?php

namespace App\Enums\Compliance;

enum ControlType: string
{
    case Governance = 'governance';
    case Technical = 'technical';
    case Operational = 'operational';
    case Physical = 'physical';
    case Privacy = 'privacy';
}
