<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\ImportRunStatus;
use App\Enums\Compliance\ImportType;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ComplianceCorpusImportRun extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_corpus_import_runs';

    protected $fillable = [
        'uuid',
        'framework_id',
        'framework_release_id',
        'source_document_id',
        'format',
        'source_path',
        'content_hash',
        'import_type',
        'status',
        'dry_run',
        'initiated_by',
        'started_at',
        'completed_at',
        'failed_at',
        'summary',
        'stats',
        'rollback_data',
        'rollback_of_import_run_id',
        'failure_message',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'summary' => 'array',
        'stats' => 'array',
        'rollback_data' => 'array',
        'status' => ImportRunStatus::class,
        'import_type' => ImportType::class,
    ];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(ComplianceFramework::class, 'framework_id');
    }

    public function frameworkRelease(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkRelease::class, 'framework_release_id');
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(ComplianceSourceDocument::class, 'source_document_id');
    }

    public function rollbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_of_import_run_id');
    }

    public function rollbacks(): HasMany
    {
        return $this->hasMany(self::class, 'rollback_of_import_run_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ComplianceCorpusImportLog::class, 'import_run_id');
    }

    public function corpusRevision(): HasOne
    {
        return $this->hasOne(ComplianceCorpusRevision::class, 'import_run_id');
    }
}
