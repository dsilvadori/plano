<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
        'tutory_product_id',
        'exam_date',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'exam_date' => 'date',
    ];

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order');
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
