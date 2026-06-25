<?php

/*
|--------------------------------------------------------------------------
| Quenyx AI Platform (QCIF Sprint 19)
|--------------------------------------------------------------------------
| The Quenyx AI Platform is a SHARED, module-agnostic AI runtime for the whole
| Quenyx vOPS HUB — not a QynShield-only feature. This file is the canonical,
| backend-authoritative catalog of every vOPS HUB module the platform is aware
| of, INDEPENDENT of frontend sidebar visibility flags.
|
| `ai_candidate` marks modules slated to become first-class AI consumers. A
| module becomes `production` automatically once it registers an adapter with
| the platform (see App\Providers\AppServiceProvider). QynShield is the first
| production adapter; QynSight is the next first-class AI consumer (contract
| only in this sprint — NO QynSight AI is implemented here).
*/

return [

    'modules' => [
        ['key' => 'qynshield',      'name' => 'QynShield',      'ai_candidate' => true],
        ['key' => 'qynsight',       'name' => 'QynSight',       'ai_candidate' => true],
        ['key' => 'qynasset',       'name' => 'QynAsset',       'ai_candidate' => false],
        ['key' => 'qynrun',         'name' => 'QynRun',         'ai_candidate' => false],
        ['key' => 'qynreact',       'name' => 'QynReact',       'ai_candidate' => false],
        ['key' => 'qynnotify',      'name' => 'QynNotify',      'ai_candidate' => false],
        ['key' => 'qynknow',        'name' => 'QynKnow',        'ai_candidate' => false],
        ['key' => 'qynva',          'name' => 'QynVA',          'ai_candidate' => false],
        ['key' => 'qynsupport',     'name' => 'QynSupport',     'ai_candidate' => false],
        ['key' => 'qynbalance',     'name' => 'QynBalance',     'ai_candidate' => false],
        ['key' => 'qyncore',        'name' => 'QynCore',        'ai_candidate' => false],
        ['key' => 'qynintegrations', 'name' => 'QynIntegrations', 'ai_candidate' => false],
    ],

    /*
    | Reserved adapter contracts (interfaces only — NOT implementations). Maps a module key to the
    | interface a future adapter will implement, so the platform can report a module as "reserved"
    | rather than merely "planned". QynShield is omitted because it is already production.
    */
    'reserved_adapters' => [
        'qynsight' => App\Contracts\QuenyxAI\QynSight\QynSightAiAdapterInterface::class,
    ],

];
