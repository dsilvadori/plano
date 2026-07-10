<?php

namespace App\Models;

use App\Support\ThumbnailUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CourseModuleTrack extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (CourseModuleTrack $track): void {
            $track->lessons()->detach();
            $track->courses()->detach();
            $track->questionBanks()->detach();

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

    public function questionBanks(): BelongsToMany
    {
        return $this->belongsToMany(QuestionBank::class, 'question_bank_course_module_track')
            ->withTimestamps();
    }

    public function getThumbnailDisplayUrlAttribute(): string
    {
        return ThumbnailUrl::fromPathOrUrl($this->thumbnail_path, $this->thumbnail_url);
    }
}
