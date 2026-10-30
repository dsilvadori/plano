<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PandaImportItem extends Model
{
    protected $fillable = [
        'panda_import_run_id',
        'external_type',
        'external_id',
        'local_type',
        'local_id',
        'status',
        'payload',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PandaImportRun::class, 'panda_import_run_id');
    }
}
