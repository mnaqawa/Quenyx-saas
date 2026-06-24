<?php

namespace App\Enums\Compliance;

/**
 * Confidence BASIS for a compliance mapping — describes WHERE a mapping came from, not a
 * numeric strength. QCIF never emits numeric mapping scores.
 *
 * - official: asserted by the official corpus source (e.g. a control's native objective
 *   assignment imported from the regulator's document).
 * - manual:   curated by a human reviewer (a published mapping row).
 * - derived:  computed deterministically by QCIF (e.g. two controls share an objective →
 *   they are "related"). Not asserted by any source.
 */
enum MappingConfidence: string
{
    case Official = 'official';
    case Manual = 'manual';
    case Derived = 'derived';
}
