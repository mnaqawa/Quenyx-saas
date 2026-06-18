<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\ImportLogLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCorpusImportLog extends Model
{
    public $timestamps = false;

    protected $table = 'compliance_corpus_import_logs';

    protected $fillable = [
        'import_run_id',
        'level',
        'entity_type',
        'entity_key',
        'message',
        'row_number',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'level' => ImportLogLevel::class,
    ];

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ComplianceCorpusImportRun::class, 'import_run_id');
    }
}
