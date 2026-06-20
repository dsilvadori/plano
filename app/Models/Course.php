<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    /** @use HasFactory<\Database\Factories\CourseFactory> */
    use HasFactory;

    protected static function booted(): void
    {
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

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('sort_order');
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
}
