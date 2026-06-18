<?php

namespace App\Enums\Compliance;

enum SourceDocumentType: string
{
    case Regulation = 'regulation';
    case Framework = 'framework';
    case Guideline = 'guideline';
    case Circular = 'circular';
    case Appendix = 'appendix';
    case Mapping = 'mapping';
    case Other = 'other';
}
