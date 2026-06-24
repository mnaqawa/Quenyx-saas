<?php

namespace App\Contracts\Compliance\Mapping;

/**
 * Future seam for PDPL (Saudi Personal Data Protection Law) ⇄ other-framework control
 * mappings.
 *
 * Marker interface only — NO implementation in QCIF Sprint 8. A future sprint binds a
 * concrete provider once the PDPL corpus is onboarded.
 */
interface PdplMappingProviderInterface extends FrameworkMappingProviderInterface
{
}
