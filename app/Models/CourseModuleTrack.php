<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class CourseModuleTrack extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (CourseModuleTrack $track): void {
            $track->lessons()->detach();
            $track->courses()->detach();

            Lesson::query()
                ->where('course_module_track_id', $track->id)
                ->update(['course_module_track_id' => null]);
        });
    }

    protected $fillable = [
        'course_module_id',
        'name',
        'slug',
        'description',
        'thumbnail_url',
        'thumbnail_path',
        'sort_order',
        'status',
        'panda_folder_id',
        'google_doc_url',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_module_track_course')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'course_module_track_lessons')
            ->withPivot(['sort_order', 'status_override'])
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('lessons.sort_order')
            ->orderBy('lessons.title');
    }

    public function getThumbnailDisplayUrlAttribute(): string
    {
        if ($this->thumbnail_path) {
            return Storage::disk('public')->url($this->thumbnail_path);
        }

        return $this->thumbnail_url ?: 'https://vencendoconcursos.com.br/wp-content/uploads/2026/04/logo-vc-transparente.png';
    }
}
