<?php

namespace App\Contracts\QuenyxAI\QynSight;

use App\Contracts\QuenyxAI\AiModuleAdapterInterface;

/**
 * QynSight AI adapter contract — PREPARATION ONLY (QCIF Sprint 19).
 *
 * This interface reserves QynSight's seat in the Quenyx AI Platform so future work can plug
 * monitoring/observability intelligence into the SAME shared runtime QynShield uses. It is a
 * contract only.
 *
 * Sprint 19 deliberately implements NONE of the following — there is NO monitoring AI, NO RCA,
 * NO incident AI, NO log analysis, and NO metrics AI in this codebase yet. When QynSight's AI is
 * built (a later sprint), its adapter will implement this interface and register with the platform
 * exactly like {@see \App\Services\QuenyxAI\Adapters\QynShieldAiAdapter} does — no platform change
 * required.
 */
interface QynSightAiAdapterInterface extends AiModuleAdapterInterface
{
    // Intentionally empty: QynSight inherits the generic adapter contract. Monitoring/observability
    // specific methods will be added when QynSight AI is actually implemented.
}
