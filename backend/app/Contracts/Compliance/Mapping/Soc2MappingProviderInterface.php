<?php

namespace App\Contracts\Compliance\Mapping;

/**
 * Future seam for SOC 2 (Trust Services Criteria) ⇄ other-framework control mappings.
 *
 * Marker interface only — NO implementation in QCIF Sprint 8. A future sprint binds a
 * concrete provider once the SOC 2 corpus is onboarded.
 */
interface Soc2MappingProviderInterface extends FrameworkMappingProviderInterface
{
}
