<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseModule extends Model
{
    /** @use HasFactory<\Database\Factories\CourseModuleFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'name',
        'type',
        'workload_minutes',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function studyTracks(): BelongsToMany
    {
        return $this->belongsToMany(StudyTrack::class, 'study_track_modules')
            ->withPivot(['weight', 'sort_order'])
            ->withTimestamps();
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(StudyPlanItem::class);
    }
}
