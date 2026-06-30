<?php

/*
|--------------------------------------------------------------------------
| Enterprise Knowledge Platform (Sprint 24)
|--------------------------------------------------------------------------
| Configuration for the shared, registry-driven Knowledge Platform: the Knowledge Source Registry
| (which providers exist and which are operational), Enterprise Search behaviour, and Global Timeline
| sources. The registry is the ONLY way knowledge providers are added — there is no provider-specific
| branching anywhere in the code. Sources that are not configured are reported honestly as
| "not connected"; they are never simulated and never return fake results.
*/

return [

    // The Internal Knowledge Base is always operational (it is backed by the knowledge_documents table).
    'internal_source_key' => 'internal',

    // Enterprise Search tuning. Semantic search here is DETERMINISTIC lexical similarity (token overlap)
    // — honest "semantic-style" ranking with no embeddings/vector store unless one is registered. Never
    // fabricates results; only real indexed rows are returned.
    'search' => [
        'default_limit' => 25,
        'max_limit' => 100,
        'snippet_length' => 240,
    ],

    'graph' => [
        // Per-type node cap keeps the Knowledge Graph v2 bounded and deterministic.
        'node_limit_per_type' => 50,
    ],

    // Knowledge Source Registry — providers discoverable by the platform. `operational` providers are
    // implemented and active; `planned` providers register so the catalog is complete and future
    // connectors plug in with one line, but they honestly report "not connected" until configured.
    // Each entry maps to a registered source instance (see App\Providers\AppServiceProvider).
    'planned_sources' => [
        ['key' => 'markdown',     'name' => 'Markdown Repository', 'category' => 'files'],
        ['key' => 'pdf',          'name' => 'PDF Library',         'category' => 'files'],
        ['key' => 'html',         'name' => 'HTML Pages',          'category' => 'files'],
        ['key' => 'git',          'name' => 'Git Repository',      'category' => 'repository'],
        ['key' => 'github_wiki',  'name' => 'GitHub Wiki',         'category' => 'repository'],
        ['key' => 'gitlab_wiki',  'name' => 'GitLab Wiki',         'category' => 'repository'],
        ['key' => 'confluence',   'name' => 'Confluence',          'category' => 'collaboration'],
        ['key' => 'sharepoint',   'name' => 'SharePoint',          'category' => 'collaboration'],
        ['key' => 'google_drive', 'name' => 'Google Drive',        'category' => 'cloud_storage'],
        ['key' => 'onedrive',     'name' => 'OneDrive',            'category' => 'cloud_storage'],
        ['key' => 'mediawiki',    'name' => 'MediaWiki',           'category' => 'wiki'],
        ['key' => 'elastic',      'name' => 'Elastic / OpenSearch', 'category' => 'search_index'],
        ['key' => 'vector_store', 'name' => 'Vector Store',        'category' => 'semantic'],
    ],

    // Ticket Intelligence — deterministic SLA matrix (hours) by priority. Suggestions are evidence-based
    // and editable; nothing is auto-applied.
    'service_desk' => [
        'sla_hours' => [
            'critical' => 4,
            'high' => 8,
            'medium' => 24,
            'low' => 72,
        ],
        'categories' => ['incident', 'request', 'access', 'hardware', 'software', 'network', 'security', 'other'],
    ],

    // Notification Intelligence — deterministic urgency weighting by severity and the correlation window.
    'notifications' => [
        'severity_weight' => [
            'critical' => 100,
            'high' => 75,
            'medium' => 50,
            'low' => 25,
            'info' => 10,
        ],
        'correlation_window_minutes' => 30,
        'channels' => ['in_app', 'email', 'sms', 'webhook'],
    ],

    // Global Timeline — the event sources aggregated into the platform-wide chronological view. Each is a
    // real table; adding a source here (not branching) extends the timeline.
    'timeline' => [
        'default_limit' => 100,
        'max_limit' => 300,
    ],
];
