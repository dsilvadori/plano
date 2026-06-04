<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyTrack extends Model
{
    /** @use HasFactory<\Database\Factories\StudyTrackFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(CourseModule::class, 'study_track_modules')
            ->withPivot(['weight', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function studyPlans(): HasMany
    {
        return $this->hasMany(StudyPlan::class);
    }
}
