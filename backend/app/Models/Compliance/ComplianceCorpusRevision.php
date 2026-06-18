<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\CorpusRevisionStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceCorpusRevision extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_corpus_revisions';

    protected $fillable = [
        'uuid',
        'framework_release_id',
        'revision_number',
        'parent_revision_id',
        'import_run_id',
        'description',
        'status',
        'entity_counts',
        'checksum_sha256',
        'created_by',
        'activated_at',
        'superseded_at',
        'metadata',
    ];

    protected $casts = [
        'entity_counts' => 'array',
        'metadata' => 'array',
        'status' => CorpusRevisionStatus::class,
        'activated_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function frameworkRelease(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkRelease::class, 'framework_release_id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ComplianceCorpusImportRun::class, 'import_run_id');
    }

    public function parentRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    public function childRevisions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
