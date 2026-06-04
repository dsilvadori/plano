<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyPlanItem extends Model
{
    /** @use HasFactory<\Database\Factories\StudyPlanItemFactory> */
    use HasFactory;

    protected $fillable = [
        'study_plan_id',
        'course_module_id',
        'scheduled_date',
        'week_number',
        'day_of_week',
        'title',
        'description',
        'type',
        'estimated_minutes',
        'completed_at',
        'sort_order',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function studyPlan(): BelongsTo
    {
        return $this->belongsTo(StudyPlan::class);
    }

    public function courseModule(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class);
    }

    public function toggleCompleted(): void
    {
        $this->forceFill([
            'completed_at' => $this->completed_at ? null : now(),
        ])->save();
    }
}
