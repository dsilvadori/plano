<?php

namespace App\Models;

use App\Support\ThumbnailUrl;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            StudyTrack::query()->where('course_id', $course->id)->delete();
        });

        static::created(function (Course $course): void {
            self::ensureStartHereModuleIsAttached($course);

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

    public static function ensureStartHereModuleIsAttached(Course $course): void
    {
        $module = CourseModule::query()->firstOrCreate([
            'name' => 'Comece por aqui',
        ], [
            'description' => 'Orientações iniciais do curso, com instruções em vídeo, texto e links úteis para os alunos.',
            'type' => 'complementary',
            'workload_minutes' => 0,
            'sort_order' => 0,
            'metadata' => ['source' => 'system_start_here'],
            'is_active' => true,
        ]);

        $track = CourseModuleTrack::query()->firstOrCreate([
            'course_module_id' => $module->id,
            'slug' => 'instrucoes',
        ], [
            'name' => 'Instruções',
            'description' => 'Aulas e materiais de orientação para começar o curso.',
            'sort_order' => 0,
            'status' => 'published',
            'metadata' => ['source' => 'system_start_here'],
        ]);

        $module->courses()->syncWithoutDetaching([
            $course->id => ['sort_order' => 0],
        ]);

        $track->courses()->syncWithoutDetaching([
            $course->id => ['sort_order' => 0],
        ]);
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

    public function linkedLessonsQuery(): Builder
    {
        return Lesson::query()
            ->where(function (Builder $query): void {
                $query->where('course_id', $this->id)
                    ->orWhereHas('module', fn (Builder $query) => $query
                        ->where('course_id', $this->id)
                        ->orWhereHas('courses', fn (Builder $query) => $query->whereKey($this->id))
                        ->orWhereHas('studyTracks', fn (Builder $query) => $query->where('course_id', $this->id)))
                    ->orWhereHas('modules', fn (Builder $query) => $query
                        ->where('course_id', $this->id)
                        ->orWhereHas('courses', fn (Builder $query) => $query->whereKey($this->id))
                        ->orWhereHas('studyTracks', fn (Builder $query) => $query->where('course_id', $this->id)))
                    ->orWhereHas('track', fn (Builder $query) => $query
                        ->whereHas('courses', fn (Builder $query) => $query->whereKey($this->id))
                        ->orWhereHas('module', fn (Builder $query) => $query
                            ->where('course_id', $this->id)
                            ->orWhereHas('courses', fn (Builder $query) => $query->whereKey($this->id))
                            ->orWhereHas('studyTracks', fn (Builder $query) => $query->where('course_id', $this->id))))
                    ->orWhereHas('tracks', fn (Builder $query) => $query
                        ->whereHas('courses', fn (Builder $query) => $query->whereKey($this->id))
                        ->orWhereHas('module', fn (Builder $query) => $query
                            ->where('course_id', $this->id)
                            ->orWhereHas('courses', fn (Builder $query) => $query->whereKey($this->id))
                            ->orWhereHas('studyTracks', fn (Builder $query) => $query->where('course_id', $this->id))));
            })
            ->with(['module', 'track', 'modules', 'tracks'])
            ->distinct()
            ->orderBy('sort_order')
            ->orderBy('title');
    }

    public function linkedLessons()
    {
        return $this->linkedLessonsQuery()->get();
    }

    public function linkedLessonsCount(): int
    {
        return $this->linkedLessonsQuery()->count('lessons.id');
    }

    public function linkedMediaLessonsCount(): int
    {
        return $this->linkedLessonsQuery()
            ->where(function (Builder $query): void {
                $query->where('source_status', 'media_ready')
                    ->orWhereNotNull('panda_video_id')
                    ->orWhereNotNull('panda_embed_url')
                    ->orWhereNotNull('panda_player_url');
            })
            ->count('lessons.id');
    }

    public function getThumbnailDisplayUrlAttribute(): string
    {
        return ThumbnailUrl::fromPathOrUrl($this->thumbnail_path, $this->thumbnail_url);
    }
}
