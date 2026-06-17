<?php

/**
 * Configuration-driven alert metric definitions.
 * metric_condition on rules must match a key defined here.
 */
return [
    'open_statuses' => ['open', 'active', 'acknowledged'],

    'conditions' => [
        'cpu' => [
            'scope' => 'service',
            'source' => 'metric_history',
            'service_key' => 'cpu',
            'metric' => 'cpu',
        ],
        'memory' => [
            'scope' => 'service',
            'source' => 'metric_history',
            'service_key' => 'memory',
            'metric' => 'memory',
        ],
        'disk' => [
            'scope' => 'service',
            'source' => 'metric_history',
            'service_key' => 'disk',
            'metric' => 'disk',
        ],
        'load' => [
            'scope' => 'service',
            'source' => 'metric_history',
            'service_key' => 'load',
            'metric' => 'load',
        ],
        'network' => [
            'scope' => 'service',
            'source' => 'metric_history',
            'service_key' => 'network',
            'metric' => 'network',
        ],
        'host_unreachable' => [
            'scope' => 'host',
            'source' => 'host_state',
            'match_states' => ['unreachable', 'down', 'critical'],
        ],
        'service_critical' => [
            'scope' => 'service',
            'source' => 'service_state',
            'match_state' => 'critical',
        ],
        'service_warning' => [
            'scope' => 'service',
            'source' => 'service_state',
            'match_state' => 'warning',
        ],
        'service_status' => [
            'scope' => 'service',
            'source' => 'service_state_numeric',
        ],
        'capacity_risk_score' => [
            'scope' => 'workspace',
            'source' => 'capacity',
            'field' => 'capacity_risk_score',
        ],
        'cpu_runway_days' => [
            'scope' => 'workspace',
            'source' => 'capacity',
            'field' => 'cpu_runway_days',
        ],
        'memory_runway_days' => [
            'scope' => 'workspace',
            'source' => 'capacity',
            'field' => 'memory_runway_days',
        ],
        'storage_runway_days' => [
            'scope' => 'workspace',
            'source' => 'capacity',
            'field' => 'storage_runway_days',
        ],
    ],

    'state_severity_map' => [
        'critical' => 3,
        'warning' => 2,
        'unknown' => 1,
        'pending' => 0,
        'ok' => 0,
    ],
];
