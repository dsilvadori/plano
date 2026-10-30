<?php

namespace App\Models;

use App\Support\ThumbnailUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'thumbnail_url',
        'thumbnail_path',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tracks(): HasMany
    {
        return $this->hasMany(CourseModuleTrack::class);
    }

    public function getThumbnailDisplayUrlAttribute(): string
    {
        return ThumbnailUrl::fromPathOrUrl($this->thumbnail_path, $this->thumbnail_url);
    }
}
