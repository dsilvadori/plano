<?php

namespace App\Models;

use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (Lesson $lesson): void {
            $lesson->syncVideoFromLegacyMedia();

            if (! $lesson->course_module_id) {
                return;
            }

            if (! $lesson->course_module_track_id) {
                $track = CourseModuleTrack::query()->firstOrCreate(
                    [
                        'course_module_id' => $lesson->course_module_id,
                        'slug' => 'aulas',
                    ],
                    [
                        'name' => 'Aulas',
                        'sort_order' => 1,
                        'status' => 'published',
                        'metadata' => ['source' => 'legacy_module'],
                    ],
                );

                $lesson->forceFill(['course_module_track_id' => $track->id])->saveQuietly();

                $courseIds = $lesson->module?->courses()->pluck('courses.id')->all() ?? [];

                if ($lesson->course_id) {
                    $courseIds[] = $lesson->course_id;
                }

                foreach (array_unique(array_filter($courseIds)) as $courseId) {
                    $track->courses()->syncWithoutDetaching([
                        $courseId => ['sort_order' => (int) $track->sort_order],
                    ]);
                }
            }

            $lesson->modules()->syncWithoutDetaching([
                $lesson->course_module_id => ['sort_order' => (int) $lesson->sort_order],
            ]);

            if ($lesson->course_module_track_id) {
                $lesson->tracks()->syncWithoutDetaching([
                    $lesson->course_module_track_id => ['sort_order' => (int) $lesson->sort_order],
                ]);
            }
        });
    }

    protected $fillable = [
        'course_id',
        'lesson_folder_id',
        'video_id',
        'course_module_id',
        'course_module_track_id',
        'title',
        'slug',
        'description',
        'type',
        'thumbnail_url',
        'duration_seconds',
        'sort_order',
        'status',
        'panda_video_id',
        'panda_embed_url',
        'panda_player_url',
        'panda_status',
        'google_doc_url',
        'digital_book_path',
        'source_status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(LessonFolder::class, 'lesson_folder_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(CourseModuleTrack::class, 'course_module_track_id');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(CourseModule::class, 'course_module_lessons')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(CourseModuleTrack::class, 'course_module_track_lessons')
            ->withPivot(['sort_order', 'status_override'])
            ->withTimestamps();
    }

    public function questionBanks(): BelongsToMany
    {
        return $this->belongsToMany(QuestionBank::class, 'question_bank_lesson')
            ->withTimestamps();
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(LessonComment::class);
    }

    public function aiArtifacts(): HasMany
    {
        return $this->hasMany(AiArtifact::class, 'source_id')
            ->where('source_type', self::class);
    }

    public function studyPlanItems(): BelongsToMany
    {
        return $this->belongsToMany(StudyPlanItem::class, 'study_plan_item_lessons')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function getDurationMinutesAttribute(): int
    {
        return (int) ceil($this->duration_seconds / 60);
    }

    public function getPlayerUrlAttribute(): ?string
    {
        return $this->panda_embed_url ?: $this->panda_player_url ?: $this->video?->embed_url ?: $this->video?->player_url;
    }

    public function syncVideoFromLegacyMedia(): ?Video
    {
        if (! Schema::hasTable('videos') || ! Schema::hasColumn('lessons', 'video_id')) {
            return null;
        }

        $hasLegacyPandaMedia = filled($this->panda_video_id)
            || filled($this->panda_embed_url)
            || filled($this->panda_player_url);

        $hasMedia = $hasLegacyPandaMedia
            || (! $this->video_id && in_array((string) $this->source_status, ['media_ready', 'panda_processing'], true));

        if (! $hasMedia) {
            return null;
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $videoMetadata = array_replace_recursive($metadata, [
            'lesson_id' => $this->id,
            'synced_from_lesson_at' => now()->toIso8601String(),
        ]);

        if (filled($this->panda_video_id)) {
            $video = Video::query()->firstOrNew(['provider_video_id' => (string) $this->panda_video_id]);
        } elseif ($this->video_id) {
            $video = Video::query()->firstOrNew(['id' => $this->video_id]);
        } else {
            $video = new Video();
        }

        $video->fill([
            'lesson_folder_id' => $this->lesson_folder_id,
            'title' => $this->title,
            'slug' => $this->slug ?: Str::slug($this->title),
            'description' => $this->description,
            'provider' => 'panda',
            'provider_video_id' => filled($this->panda_video_id) ? (string) $this->panda_video_id : $video->provider_video_id,
            'provider_status' => $this->panda_status ?: $video->provider_status,
            'embed_url' => $this->panda_embed_url ?: $video->getRawOriginal('embed_url'),
            'player_url' => $this->panda_player_url ?: $video->getRawOriginal('player_url'),
            'thumbnail_url' => $this->thumbnail_url ?: $video->thumbnail_url,
            'duration_seconds' => (int) $this->duration_seconds,
            'source_status' => (string) ($this->source_status ?: 'awaiting_media'),
            'metadata' => $videoMetadata,
        ])->save();

        if ((int) $this->video_id !== (int) $video->id) {
            $this->forceFill(['video_id' => $video->id])->saveQuietly();
        }

        $this->setRelation('video', $video);

        return $video;
    }
}
