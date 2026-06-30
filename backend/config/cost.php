<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cost Intelligence (QynBalance) — Sprint 25
    |--------------------------------------------------------------------------
    |
    | Rates are NULL by default on purpose. Quenyx never fabricates financial values: when a rate is
    | not configured, Cost Intelligence reports the real resource COUNT and clearly states that pricing
    | is unavailable, rather than inventing a currency figure. Operators provide real rates (from their
    | own contracts / cloud bills) via these settings or env vars to unlock monetary estimates.
    |
    */

    'currency' => env('COST_CURRENCY', 'USD'),

    // Monthly unit rates. NULL = unknown → estimates are withheld and labelled "pricing unavailable".
    'rates' => [
        'host_per_month' => env('COST_HOST_PER_MONTH'),       // per monitored host
        'agent_per_month' => env('COST_AGENT_PER_MONTH'),     // per enrolled agent
        'service_per_month' => env('COST_SERVICE_PER_MONTH'), // per monitored service check
        'license_per_seat' => env('COST_LICENSE_PER_SEAT'),   // per workspace member seat
        'automation_run_minute' => env('COST_AUTOMATION_RUN_MINUTE'), // per automation runtime minute saved
    ],

    // Optional monthly budget (NULL = not set → forecasting is relative/qualitative only).
    'monthly_budget' => env('COST_MONTHLY_BUDGET'),

    // Heuristic thresholds for utilization-based optimization suggestions (counts/ratios, not money).
    'optimization' => [
        // Agents not seen within this many hours are flagged as potentially idle (decommission candidate).
        'idle_agent_hours' => env('COST_IDLE_AGENT_HOURS', 72),
        // Hosts with zero services attached are flagged as under-utilized.
        'flag_hosts_without_services' => true,
    ],
];
