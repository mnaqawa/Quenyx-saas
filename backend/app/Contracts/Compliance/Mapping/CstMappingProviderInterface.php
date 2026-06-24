<?php

namespace App\Contracts\Compliance\Mapping;

/**
 * Future seam for CST (Communications, Space & Technology Commission) ⇄ other-framework
 * control mappings.
 *
 * Marker interface only — NO implementation in QCIF Sprint 8. A future sprint binds a
 * concrete provider once the CST corpus is onboarded.
 */
interface CstMappingProviderInterface extends FrameworkMappingProviderInterface
{
}
