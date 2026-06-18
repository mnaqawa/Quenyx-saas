<?php

namespace App\Enums\Compliance;

enum ImportLogLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
