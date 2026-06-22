<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiArtifact extends Model
{
    protected $fillable = [
        'source_type',
        'source_id',
        'artifact_type',
        'provider',
        'status',
        'content',
        'metadata',
    ];

    protected $casts = [
        'content' => 'array',
        'metadata' => 'array',
    ];
}
