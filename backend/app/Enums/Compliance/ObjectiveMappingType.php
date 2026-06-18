<?php

namespace App\Enums\Compliance;

enum ObjectiveMappingType: string
{
    case Equivalent = 'equivalent';
    case Partial = 'partial';
    case Related = 'related';
    case Superset = 'superset';
    case Subset = 'subset';
}
