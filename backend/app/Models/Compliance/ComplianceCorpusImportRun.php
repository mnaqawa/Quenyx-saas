<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\ImportRunStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceCorpusImportRun extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_corpus_import_runs';

    protected $fillable = [
        'uuid',
        'framework_id',
        'format',
        'source_path',
        'content_hash',
        'status',
        'dry_run',
        'initiated_by',
        'started_at',
        'completed_at',
        'stats',
        'rollback_data',
        'failure_message',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'stats' => 'array',
        'rollback_data' => 'array',
        'status' => ImportRunStatus::class,
    ];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(ComplianceFramework::class, 'framework_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ComplianceCorpusImportLog::class, 'import_run_id');
    }
}
