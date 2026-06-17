<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObserveReportExport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'export_type',
        'format',
        'title',
        'file_size_bytes',
        'exported_by',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }
}
