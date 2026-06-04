<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    /** @use HasFactory<\Database\Factories\WebhookEventFactory> */
    use HasFactory;

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'status',
        'payload',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
