<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'study_plan_item_lessons')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function orderedLessonsForDisplay(): Collection
    {
        return $this->lessons
            ->values()
            ->sortBy(function (Lesson $lesson, int $index): string {
                preg_match('/^\s*(?:aula\s*)?(\d{1,3})(?:\s*[-–.)]|\s+)/iu', $lesson->title, $matches);

                return implode('|', [
                    isset($matches[1]) ? '0' : '1',
                    str_pad((string) ((int) ($matches[1] ?? 0)), 8, '0', STR_PAD_LEFT),
                    str_pad((string) $index, 8, '0', STR_PAD_LEFT),
                ]);
            })
            ->values();
    }

    public function toggleCompleted(): void
    {
        $this->forceFill([
            'completed_at' => $this->completed_at ? null : now(),
        ])->save();
    }

    public function getDisplayTitleAttribute(): string
    {
        return Str::of($this->title)
            ->replace('Matéria de Conhecimento Específico', 'Conhecimentos Específicos')
            ->replace('Matérias Básicas', 'Matéria Básica')
            ->replace('Conhecimento Específico', 'Conhecimentos Específicos')
            ->replace('Conhecimentos Complementares', 'Conhecimentos Complementares')
            ->replace('Revisões gerais', 'Revisão')
            ->replace('Revisões', 'Revisão')
            ->replace('Bloco complementar', 'Bloco de apoio')
            ->value();
    }

    public function getDisplayDescriptionAttribute(): string
    {
        return Str::of($this->description)
            ->replace('matéria de conhecimento específico', 'conhecimentos específicos')
            ->replace('matérias básicas', 'matéria básica')
            ->replace('conhecimento específico', 'conhecimentos específicos')
            ->replace('conhecimento complementar', 'conhecimentos complementares')
            ->replace('Sábado de revisões', 'Sábado de revisão')
            ->value();
    }
}
