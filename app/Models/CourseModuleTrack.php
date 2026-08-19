<?php

namespace App\Models;

use App\Support\ThumbnailUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

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
        'teacher_id',
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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
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
        $teacher = $this->teacher ?: $this->module?->teacher ?: $this->teacherFromText() ?: $this->moduleTeacherFromText();

        if ($teacher && (filled($teacher->thumbnail_path) || filled($teacher->thumbnail_url))) {
            return $teacher->thumbnail_display_url;
        }

        return ThumbnailUrl::fromPathOrUrl($this->thumbnail_path, $this->thumbnail_url);
    }

    public function getTeacherDisplayNameAttribute(): ?string
    {
        $name = $this->teacher?->name
            ?: $this->module?->teacher?->name
            ?: $this->teacherFromText()?->name
            ?: $this->moduleTeacherFromText()?->name
            ?: $this->teacher_name
            ?: $this->module?->teacher_name;

        return filled($name) ? $name : null;
    }

    protected function teacherFromText(): ?Teacher
    {
        if (blank($this->teacher_name)) {
            return null;
        }

        $normalizedName = $this->normalizeTeacherName($this->teacher_name);

        if ($normalizedName === '') {
            return null;
        }

        return Teacher::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Teacher $teacher): bool => $this->normalizeTeacherName($teacher->name) === $normalizedName);
    }

    protected function moduleTeacherFromText(): ?Teacher
    {
        $moduleTeacherName = $this->module?->teacher_name;

        if (blank($moduleTeacherName)) {
            return null;
        }

        $normalizedName = $this->normalizeTeacherName($moduleTeacherName);

        if ($normalizedName === '') {
            return null;
        }

        return Teacher::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Teacher $teacher): bool => $this->normalizeTeacherName($teacher->name) === $normalizedName);
    }

    protected function normalizeTeacherName(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->ascii()
            ->replaceMatches('/\b(professor|professora|prof|profa|doutor|doutora|dr|dra)\b\.?/u', '')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
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
