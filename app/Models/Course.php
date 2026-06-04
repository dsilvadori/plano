<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    /** @use HasFactory<\Database\Factories\CourseFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'tutory_product_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
