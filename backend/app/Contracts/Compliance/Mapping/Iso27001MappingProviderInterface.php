<?php

namespace App\Contracts\Compliance\Mapping;

/**
 * Future seam for ISO/IEC 27001 ⇄ other-framework control mappings.
 *
 * Marker interface only — NO implementation in QCIF Sprint 8. A future sprint binds a
 * concrete provider once the ISO 27001 corpus is onboarded.
 */
interface Iso27001MappingProviderInterface extends FrameworkMappingProviderInterface
{
}
