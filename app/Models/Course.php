<?php

namespace App\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Course $course): void {
            $course->modules()->detach();
            $course->moduleTracks()->detach();
            $course->students()->detach();
            $course->lessons()->update(['course_id' => null]);
            CourseModule::query()->where('course_id', $course->id)->update(['course_id' => null]);
            StudyTrack::query()->where('course_id', $course->id)->update(['course_id' => null]);
        });

        static::created(function (Course $course): void {
            $testStudent = User::query()
                ->where('email', 'aluno@teste.com')
                ->first();

            if (! $testStudent) {
                return;
            }

            $testStudent->courses()->syncWithoutDetaching([
                $course->id => [
                    'source' => 'manual',
                    'external_purchase_id' => null,
                ],
            ]);
        });
    }

    protected $fillable = [
        'name',
        'slug',
        'description',
        'short_description',
        'thumbnail_url',
        'thumbnail_path',
        'checkout_url',
        'sphere_id',
        'education_level_id',
        'tutory_product_id',
        'combo_name',
        'exam_date',
        'status',
        'is_active',
        'is_featured',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'exam_date' => 'date',
        'metadata' => 'array',
    ];

    public function sphere(): BelongsTo
    {
        return $this->belongsTo(CourseSphere::class, 'sphere_id');
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(CourseModule::class, 'course_module_course')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('course_modules.sort_order')
            ->orderBy('course_modules.name');
    }

    public function moduleTracks(): BelongsToMany
    {
        return $this->belongsToMany(CourseModuleTrack::class, 'course_module_track_course')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('course_module_tracks.sort_order')
            ->orderBy('course_module_tracks.name');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('sort_order');
    }

    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function studyTracks(): HasMany
    {
        return $this->hasMany(StudyTrack::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'student_courses')
            ->withPivot(['source', 'external_purchase_id'])
            ->withTimestamps();
    }

    public function studyPlans(): HasMany
    {
        return $this->hasMany(StudyPlan::class);
    }

    public function questionBanks(): HasMany
    {
        return $this->hasMany(QuestionBank::class);
    }

    public function getThumbnailDisplayUrlAttribute(): string
    {
        if ($this->thumbnail_path) {
            return Storage::disk('public')->url($this->thumbnail_path);
        }

        return $this->thumbnail_url ?: 'https://vencendoconcursos.com.br/wp-content/uploads/2026/04/logo-vc-transparente.png';
    }
}
