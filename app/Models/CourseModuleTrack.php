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
        'teacher_name',
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

    public function getThumbnailAspectRatioAttribute(): string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $ratio = (string) ($metadata['thumbnail_aspect_ratio'] ?? '300/580');

        return in_array($ratio, array_keys(self::thumbnailAspectRatioOptions()), true)
            ? $ratio
            : '300/580';
    }

    public static function thumbnailAspectRatioOptions(): array
    {
        return [
            '300/580' => 'Vertical padrão (300x580)',
            '2/3' => 'Vertical 2:3',
            '3/4' => 'Vertical 3:4',
            '9/16' => 'Stories/Reels 9:16',
            '1/1' => 'Quadrada 1:1',
            '4/3' => 'Paisagem 4:3',
            '16/9' => 'Paisagem 16:9',
        ];
    }
}
