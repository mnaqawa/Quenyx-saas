<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\SourceDocumentLanguage;
use App\Enums\Compliance\SourceDocumentStatus;
use App\Enums\Compliance\SourceDocumentType;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceSourceDocument extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_source_documents';

    protected $fillable = [
        'uuid',
        'framework_release_id',
        'title_en',
        'title_ar',
        'document_type',
        'language',
        'source_url',
        'file_name',
        'file_mime',
        'file_size',
        'checksum_sha256',
        'source_reference',
        'publication_date',
        'effective_date',
        'status',
        'metadata',
    ];

    protected $casts = [
        'publication_date' => 'date',
        'effective_date' => 'date',
        'metadata' => 'array',
        'document_type' => SourceDocumentType::class,
        'language' => SourceDocumentLanguage::class,
        'status' => SourceDocumentStatus::class,
    ];

    public function frameworkRelease(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkRelease::class, 'framework_release_id');
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(ComplianceCorpusImportRun::class, 'source_document_id');
    }
}
