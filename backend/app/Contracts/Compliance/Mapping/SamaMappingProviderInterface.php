<?php

namespace App\Contracts\Compliance\Mapping;

/**
 * Future seam for SAMA (Saudi Central Bank) ⇄ other-framework control mappings.
 *
 * Marker interface only — NO implementation in QCIF Sprint 8. A future sprint binds a
 * concrete provider once the SAMA corpus is onboarded.
 */
interface SamaMappingProviderInterface extends FrameworkMappingProviderInterface
{
}
