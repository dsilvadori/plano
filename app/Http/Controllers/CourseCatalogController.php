<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseSphere;
use App\Models\EducationLevel;
use App\Models\AiArtifact;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\StudyPlanItem;
use App\Models\User;
use App\Services\PandaVideoClient;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class CourseCatalogController extends Controller
{
    protected const LESSON_AI_ARTIFACT_TYPES = ['summary', 'quiz', 'mindmap'];

    public function index(): View
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);

        $accessibleCourseIds = $user->availableCoursesQuery()->pluck('courses.id');

        $featuredCourses = $this->publishedCourses()
            ->where('is_featured', true)
            ->with(['sphere', 'educationLevel'])
            ->withCount(['modules', 'lessons'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(8)
            ->get();

        $latestCourses = $this->publishedCourses()
            ->with(['sphere', 'educationLevel'])
            ->withCount(['modules', 'lessons'])
            ->latest()
            ->limit(8)
            ->get();

        $coursesBySphere = CourseSphere::query()
            ->where('is_active', true)
            ->whereHas('courses', fn (Builder $query) => $this->publishedCourses($query))
            ->with(['courses' => fn ($query) => $this->publishedCourses($query)
                ->with(['sphere', 'educationLevel'])
                ->withCount(['modules', 'lessons'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit(6),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $educationLevels = EducationLevel::query()
            ->where('is_active', true)
            ->whereHas('courses', fn (Builder $query) => $this->publishedCourses($query))
            ->withCount(['courses' => fn (Builder $query) => $this->publishedCourses($query)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $courseProgress = $this->progressForCourses(
            $featuredCourses
                ->concat($latestCourses)
                ->concat($coursesBySphere->flatMap->courses),
            $user,
        );

        return view('dashboard.courses.index', [
            'user' => $user,
            'accessibleCourseIds' => $accessibleCourseIds,
            'featuredCourses' => $featuredCourses,
            'latestCourses' => $latestCourses,
            'coursesBySphere' => $coursesBySphere,
            'educationLevels' => $educationLevels,
            'courseProgress' => $courseProgress,
        ]);
    }

    public function mine(): View
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);

        $courses = $user->availableCoursesQuery()
            ->where('status', 'published')
            ->with(['sphere', 'educationLevel'])
            ->withCount(['modules', 'lessons'])
            ->orderBy('name')
            ->get();

        return view('dashboard.courses.mine', [
            'courses' => $courses,
            'courseProgress' => $this->progressForCourses($courses, $user),
        ]);
    }

    public function show(Course $course): View
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);
        abort_unless($course->is_active && $course->status === 'published', 404);

        $hasAccess = $user->availableCoursesQuery()
            ->whereKey($course->getKey())
            ->exists();

        $course->load([
            'sphere',
            'educationLevel',
            'modules' => fn ($query) => $query
                ->where('is_active', true)
                ->with(['onlineLessons' => fn ($lessonQuery) => $lessonQuery
                    ->where('status', 'published')
                    ->orderBy('sort_order')
                    ->orderBy('title'),
                ])
                ->orderBy('sort_order')
                ->orderBy('name'),
        ])->loadCount(['modules', 'lessons']);

        return view('dashboard.courses.show', [
            'course' => $course,
            'hasAccess' => $hasAccess,
            'progressSummary' => $this->progressForCourse($course, $user),
            'completedLessonIds' => $this->completedLessonIdsForCourse($course, $user),
            'continueLesson' => $hasAccess ? $this->continueLessonForCourse($course, $user) : null,
        ]);
    }

    public function lesson(Course $course, Lesson $lesson, PandaVideoClient $panda): View
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);
        abort_unless($course->is_active && $course->status === 'published', 404);
        abort_unless($lesson->status === 'published' && $this->lessonBelongsToCourse($lesson, $course), 404);
        abort_unless($this->userCanAccessCourse($user, $course), 403);

        $lesson->load(['course', 'module']);

        $this->ensureLessonAiArtifactsAreCached($lesson, $panda);
        $this->ensurePandaTutorAvailabilityIsCached($lesson, $panda);

        $lesson->load('aiArtifacts');

        $progress = LessonProgress::query()
            ->firstOrCreate([
                'user_id' => $user->id,
                'lesson_id' => $lesson->id,
            ], [
                'course_id' => $course->id,
                'status' => 'in_progress',
                'progress_seconds' => 0,
            ]);

        if ($progress->status !== 'completed' && $progress->status !== 'in_progress') {
            $progress->forceFill(['status' => 'in_progress'])->save();
        }

        $orderedLessons = $this->publishedLessonsForCourse($course)->get();
        $currentIndex = $orderedLessons->search(fn (Lesson $orderedLesson) => $orderedLesson->is($lesson));

        return view('dashboard.courses.lesson', [
            'course' => $course,
            'lesson' => $lesson,
            'progress' => $progress->fresh(),
            'previousLesson' => $currentIndex === false ? null : $orderedLessons->get($currentIndex - 1),
            'nextLesson' => $currentIndex === false ? null : $orderedLessons->get($currentIndex + 1),
            'progressSummary' => $this->progressForCourse($course, $user),
            'aiArtifacts' => $lesson->aiArtifacts,
            'planLessonContext' => $this->planLessonContextForLesson($user, $course, $lesson),
            'pandaTutorUrl' => $this->pandaTutorUrlForLesson($lesson),
            'pandaTutorCandidateUrl' => $this->pandaTutorCandidateUrlForLesson($lesson),
            'pandaTutorConfigUrl' => $this->pandaTutorConfigUrlForLesson($lesson),
        ]);
    }

    public function completeLesson(Course $course, Lesson $lesson): RedirectResponse
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);
        abort_unless($course->is_active && $course->status === 'published', 404);
        abort_unless($lesson->status === 'published' && $this->lessonBelongsToCourse($lesson, $course), 404);
        abort_unless($this->userCanAccessCourse($user, $course), 403);

        LessonProgress::query()->updateOrCreate([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
        ], [
            'course_id' => $course->id,
            'status' => 'completed',
            'progress_seconds' => max((int) $lesson->duration_seconds, 0),
            'completed_at' => now(),
        ]);

        $this->markLinkedPlanItemsIfReady($user, $lesson);

        return back()->with('status', 'Aula marcada como concluída.');
    }

    public function syncPandaAi(Course $course, Lesson $lesson, PandaVideoClient $panda): RedirectResponse
    {
        $user = request()->user();

        abort_unless($user->isAdmin(), 403);
        abort_unless($course->is_active && $course->status === 'published', 404);
        abort_unless($lesson->status === 'published' && $this->lessonBelongsToCourse($lesson, $course), 404);

        if ($this->missingLessonAiArtifactTypes($lesson) === []) {
            return back()->with('status', 'A IA desta aula já está em cache para todos os alunos.');
        }

        $pandaVideoId = $lesson->panda_video_id ?: (string) data_get($lesson->metadata, 'payload.id');

        if (! $pandaVideoId) {
            return back()->with('error', 'Esta aula não tem ID de vídeo para gerar IA.');
        }

        try {
            $metadata = $lesson->metadata ?? [];

            if (blank(data_get($metadata, 'panda_ai.requested_at'))) {
                $metadata = array_replace_recursive($metadata, [
                    'panda_ai' => [
                        'requested_at' => now()->toIso8601String(),
                        'workflow_response' => $panda->createAiPackage($pandaVideoId),
                    ],
                ]);
            }

            $aiPayload = null;
            $pullzoneName = $this->pandaPullzoneName($lesson);
            $videoExternalId = $this->pandaVideoExternalId($lesson);

            if ($pullzoneName && $videoExternalId) {
                $aiPayload = $panda->aiPackage($pullzoneName, $videoExternalId);
            }

            $metadata = array_replace_recursive($metadata, [
                'panda_ai' => [
                    'pullzone_name' => $pullzoneName,
                    'video_external_id' => $videoExternalId,
                    'last_synced_at' => now()->toIso8601String(),
                ],
            ]);

            $lesson->forceFill(['metadata' => $metadata])->save();

            if (! $aiPayload) {
                return back()->with('status', 'Pacote de IA solicitado. O resultado ainda não está disponível; tente sincronizar novamente em alguns minutos.');
            }

            $createdArtifacts = $this->syncPandaAiArtifactsFromPayload($lesson, $aiPayload);

            return back()->with('status', $createdArtifacts > 0
                ? 'IA da aula sincronizada com sucesso.'
                : 'A IA retornou dados, mas ainda não encontrei resumo, questões ou mapa mental no payload.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível sincronizar a IA da aula: ' . $exception->getMessage());
        }
    }

    protected function publishedCourses(?Builder $query = null): Builder
    {
        return ($query ?? Course::query())
            ->where('is_active', true)
            ->where('status', 'published');
    }

    protected function userCanAccessCourse(User $user, Course $course): bool
    {
        return $user->availableCoursesQuery()
            ->whereKey($course->getKey())
            ->exists();
    }

    protected function progressForCourses(EloquentCollection|Collection $courses, User $user): array
    {
        $courseIds = $courses
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        if ($courseIds->isEmpty()) {
            return [];
        }

        $lessonTotals = DB::table('course_module_lessons')
            ->join('course_modules', 'course_modules.id', '=', 'course_module_lessons.course_module_id')
            ->join('lessons', 'lessons.id', '=', 'course_module_lessons.lesson_id')
            ->whereIn('course_modules.course_id', $courseIds)
            ->where('lessons.status', 'published')
            ->selectRaw('course_modules.course_id, count(distinct lessons.id) as total')
            ->groupBy('course_modules.course_id')
            ->pluck('total', 'course_modules.course_id');

        $completedTotals = LessonProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->where('status', 'completed')
            ->selectRaw('course_id, count(*) as completed')
            ->groupBy('course_id')
            ->pluck('completed', 'course_id');

        return $courseIds
            ->mapWithKeys(function (int $courseId) use ($lessonTotals, $completedTotals) {
                $total = (int) ($lessonTotals[$courseId] ?? 0);
                $completed = min((int) ($completedTotals[$courseId] ?? 0), $total);

                return [$courseId => [
                    'total' => $total,
                    'completed' => $completed,
                    'percentage' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
                ]];
            })
            ->all();
    }

    protected function progressForCourse(Course $course, User $user): array
    {
        return $this->progressForCourses(collect([$course]), $user)[$course->id] ?? [
            'total' => 0,
            'completed' => 0,
            'percentage' => 0,
        ];
    }

    protected function completedLessonIdsForCourse(Course $course, User $user): Collection
    {
        return LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'completed')
            ->pluck('lesson_id');
    }

    protected function continueLessonForCourse(Course $course, User $user): ?Lesson
    {
        $completedLessonIds = $this->completedLessonIdsForCourse($course, $user);

        return $this->publishedLessonsForCourse($course)
            ->when($completedLessonIds->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('lessons.id', $completedLessonIds))
            ->first();
    }

    protected function publishedLessonsForCourse(Course $course): Builder
    {
        return Lesson::query()
            ->select('lessons.*')
            ->join('course_module_lessons', 'course_module_lessons.lesson_id', '=', 'lessons.id')
            ->join('course_modules', 'course_modules.id', '=', 'course_module_lessons.course_module_id')
            ->where('course_modules.course_id', $course->id)
            ->where('lessons.status', 'published')
            ->distinct()
            ->orderBy('course_modules.sort_order')
            ->orderBy('course_modules.name')
            ->orderBy('course_module_lessons.sort_order')
            ->orderBy('lessons.title');
    }

    protected function lessonBelongsToCourse(Lesson $lesson, Course $course): bool
    {
        return $lesson->modules()
            ->where('course_modules.course_id', $course->id)
            ->exists();
    }

    protected function planLessonContextForLesson(User $user, Course $course, Lesson $lesson): ?array
    {
        $currentItem = StudyPlanItem::query()
            ->whereHas('studyPlan', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('status', 'active'))
            ->whereHas('lessons', fn (Builder $query) => $query->whereKey($lesson->id))
            ->with('studyPlan')
            ->orderBy('scheduled_date')
            ->orderBy('sort_order')
            ->first();

        if (! $currentItem || ! $currentItem->scheduled_date) {
            return null;
        }

        $dayItems = StudyPlanItem::query()
            ->where('study_plan_id', $currentItem->study_plan_id)
            ->whereDate('scheduled_date', $currentItem->scheduled_date)
            ->with(['courseModule', 'lessons'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $completedLessonIds = LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereIn('lesson_id', $dayItems->flatMap->lessons->pluck('id')->unique())
            ->pluck('lesson_id')
            ->all();

        return [
            'plan' => $currentItem->studyPlan,
            'current_item_id' => $currentItem->id,
            'date_label' => $currentItem->scheduled_date->translatedFormat('d/m/Y'),
            'items' => $dayItems,
            'completed_lesson_ids' => $completedLessonIds,
        ];
    }

    protected function markLinkedPlanItemsIfReady(User $user, Lesson $lesson): void
    {
        $items = StudyPlanItem::query()
            ->whereNull('completed_at')
            ->whereHas('studyPlan', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('status', 'active'))
            ->whereHas('lessons', fn (Builder $query) => $query->whereKey($lesson->id))
            ->with('lessons')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $completedLessonIds = LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereIn('lesson_id', $items->flatMap->lessons->pluck('id')->unique())
            ->pluck('lesson_id');

        foreach ($items as $item) {
            $linkedLessonIds = $item->lessons->pluck('id');

            if ($linkedLessonIds->isNotEmpty() && $linkedLessonIds->diff($completedLessonIds)->isEmpty()) {
                $item->forceFill(['completed_at' => now()])->save();
            }
        }
    }

    protected function syncPandaAiArtifactsFromPayload(Lesson $lesson, array $payload): int
    {
        $artifacts = [
            'summary' => $this->firstAiPayloadValue($payload, ['summary', 'abstract', 'ebook', 'eBook', 'data.summary', 'data.abstract']),
            'quiz' => $this->firstAiPayloadValue($payload, ['quiz', 'questions', 'data.quiz', 'data.questions']),
            'mindmap' => $this->firstAiPayloadValue($payload, ['mindmap', 'mind_map', 'mindMap', 'data.mindmap', 'data.mind_map', 'data.mindMap']),
        ];

        $created = 0;

        foreach ($artifacts as $type => $content) {
            if (blank($content)) {
                continue;
            }

            AiArtifact::query()->updateOrCreate([
                'source_type' => Lesson::class,
                'source_id' => $lesson->id,
                'artifact_type' => $type,
                'provider' => 'panda',
            ], [
                'status' => 'ready',
                'content' => is_array($content) ? $content : ['text' => (string) $content],
                'metadata' => [
                    'panda_video_id' => $lesson->panda_video_id,
                    'video_external_id' => $this->pandaVideoExternalId($lesson),
                    'imported_at' => now()->toIso8601String(),
                ],
            ]);

            $created++;
        }

        AiArtifact::query()->updateOrCreate([
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'artifact_type' => 'panda_payload',
            'provider' => 'panda',
        ], [
            'status' => 'ready',
            'content' => $payload,
            'metadata' => [
                'panda_video_id' => $lesson->panda_video_id,
                'video_external_id' => $this->pandaVideoExternalId($lesson),
                'imported_at' => now()->toIso8601String(),
            ],
        ]);

        return $created;
    }

    protected function ensureLessonAiArtifactsAreCached(Lesson $lesson, PandaVideoClient $panda): void
    {
        if (! config('services.panda.ai_auto_sync', true) || ! $this->lessonAiAutoSyncEnabled($lesson) || blank(config('services.panda.api_key'))) {
            return;
        }

        if ($this->missingLessonAiArtifactTypes($lesson) === []) {
            return;
        }

        $pandaVideoId = $lesson->panda_video_id ?: (string) data_get($lesson->metadata, 'payload.id');

        if (! $pandaVideoId) {
            return;
        }

        Cache::lock("lesson:{$lesson->id}:ai-sync", 30)->get(function () use ($lesson, $panda, $pandaVideoId): void {
            $lesson->refresh();

            if ($this->missingLessonAiArtifactTypes($lesson) === []) {
                return;
            }

            $metadata = $lesson->metadata ?? [];
            $metadata = array_replace_recursive($metadata, [
                'panda_ai' => [
                    'auto_sync_enabled' => true,
                ],
            ]);

            try {
                if (blank(data_get($metadata, 'panda_ai.requested_at'))) {
                    $metadata = array_replace_recursive($metadata, [
                        'panda_ai' => [
                            'requested_at' => now()->toIso8601String(),
                            'workflow_response' => $panda->createAiPackage($pandaVideoId),
                        ],
                    ]);
                }

                if ($this->shouldPollLessonAiPackage($metadata)) {
                    $pullzoneName = $this->pandaPullzoneName($lesson);
                    $videoExternalId = $this->pandaVideoExternalId($lesson);
                    $aiPayload = null;

                    if ($pullzoneName && $videoExternalId) {
                        $aiPayload = $panda->aiPackage($pullzoneName, $videoExternalId);
                    }

                    $metadata = array_replace_recursive($metadata, [
                        'panda_ai' => [
                            'pullzone_name' => $pullzoneName,
                            'video_external_id' => $videoExternalId,
                            'last_auto_sync_attempt_at' => now()->toIso8601String(),
                            'last_synced_at' => now()->toIso8601String(),
                        ],
                    ]);

                    if ($aiPayload) {
                        $this->syncPandaAiArtifactsFromPayload($lesson, $aiPayload);
                    }
                }

                $lesson->forceFill(['metadata' => $metadata])->save();
            } catch (Throwable $exception) {
                report($exception);

                $metadata = array_replace_recursive($metadata, [
                    'panda_ai' => [
                        'last_auto_sync_attempt_at' => now()->toIso8601String(),
                        'last_auto_sync_error' => $exception->getMessage(),
                    ],
                ]);

                $lesson->forceFill(['metadata' => $metadata])->save();
            }
        });
    }

    protected function lessonAiAutoSyncEnabled(Lesson $lesson): bool
    {
        return (bool) data_get($lesson->metadata, 'panda_ai.auto_sync_enabled', true);
    }

    protected function missingLessonAiArtifactTypes(Lesson $lesson): array
    {
        $readyTypes = AiArtifact::query()
            ->where('source_type', Lesson::class)
            ->where('source_id', $lesson->id)
            ->where('provider', 'panda')
            ->where('status', 'ready')
            ->whereIn('artifact_type', self::LESSON_AI_ARTIFACT_TYPES)
            ->pluck('artifact_type')
            ->all();

        return array_values(array_diff(self::LESSON_AI_ARTIFACT_TYPES, $readyTypes));
    }

    protected function shouldPollLessonAiPackage(array $metadata): bool
    {
        $lastAttempt = data_get($metadata, 'panda_ai.last_auto_sync_attempt_at');

        if (blank($lastAttempt)) {
            return true;
        }

        return now()->diffInMinutes(\Illuminate\Support\Carbon::parse($lastAttempt)) >= 1;
    }

    protected function ensurePandaTutorAvailabilityIsCached(Lesson $lesson, PandaVideoClient $panda): void
    {
        if (! config('services.panda.tutor_auto_detect', true)) {
            return;
        }

        $pullzoneName = $this->pandaPullzoneName($lesson);
        $videoExternalId = $this->pandaVideoExternalId($lesson);

        if (! $pullzoneName || ! $videoExternalId) {
            return;
        }

        $lastCheckedAt = data_get($lesson->metadata, 'panda_ai.tutor_checked_at');

        $cacheMinutes = (bool) data_get($lesson->metadata, 'panda_ai.tutor_available', false) ? 10 : 1;

        if (filled($lastCheckedAt) && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($lastCheckedAt)) < $cacheMinutes) {
            return;
        }

        Cache::lock("lesson:{$lesson->id}:panda-tutor-detect", 30)->get(function () use ($lesson, $panda, $pullzoneName, $videoExternalId): void {
            $lesson->refresh();

            $lastCheckedAt = data_get($lesson->metadata, 'panda_ai.tutor_checked_at');
            $cacheMinutes = (bool) data_get($lesson->metadata, 'panda_ai.tutor_available', false) ? 10 : 1;

            if (filled($lastCheckedAt) && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($lastCheckedAt)) < $cacheMinutes) {
                return;
            }

            $metadata = $lesson->metadata ?? [];

            try {
                $config = $panda->playerConfig($pullzoneName, $videoExternalId) ?? [];
                $assistantId = data_get($config, 'assistant_id');
                $available = filled($assistantId);

                $metadata = array_replace_recursive($metadata, [
                    'panda_ai' => [
                        'tutor_available' => $available,
                        'tutor_assistant_id' => $available ? (string) $assistantId : null,
                        'tutor_checked_at' => now()->toIso8601String(),
                    ],
                ]);
            } catch (Throwable $exception) {
                report($exception);

                $metadata = array_replace_recursive($metadata, [
                    'panda_ai' => [
                        'tutor_available' => (bool) data_get($metadata, 'panda_ai.tutor_available', false),
                        'tutor_checked_at' => now()->toIso8601String(),
                        'tutor_last_error' => $exception->getMessage(),
                    ],
                ]);
            }

            $lesson->forceFill(['metadata' => $metadata])->save();
        });
    }

    protected function pandaTutorUrlForLesson(Lesson $lesson): ?string
    {
        if (! (bool) data_get($lesson->metadata, 'panda_ai.tutor_available', false)) {
            return null;
        }

        return $this->pandaTutorCandidateUrlForLesson($lesson);
    }

    protected function pandaTutorCandidateUrlForLesson(Lesson $lesson): ?string
    {
        $playerUrl = $lesson->player_url;
        $pullzoneName = $this->pandaPullzoneName($lesson);
        $videoExternalId = $this->pandaVideoExternalId($lesson);

        if (! $playerUrl || ! $pullzoneName || ! $videoExternalId) {
            return null;
        }

        $playerEmbedBase = preg_replace('/\?.*$/', '', $playerUrl);
        $playerEmbedBase = rtrim(str_ends_with($playerEmbedBase, '/embed/') ? $playerEmbedBase : dirname($playerEmbedBase), '/');

        return $playerEmbedBase . '/assist_chat.html?' . http_build_query(['v' => $videoExternalId, 'l' => $pullzoneName]);
    }

    protected function pandaTutorConfigUrlForLesson(Lesson $lesson): ?string
    {
        $pullzoneName = $this->pandaPullzoneName($lesson);
        $videoExternalId = $this->pandaVideoExternalId($lesson);

        if (! $pullzoneName || ! $videoExternalId) {
            return null;
        }

        return rtrim((string) config('services.panda.ai_config_base_url'), '/')
            . '/' . trim($pullzoneName, '/')
            . '/' . $videoExternalId
            . '.json';
    }

    protected function firstAiPayloadValue(array $payload, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);

            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    protected function pandaVideoExternalId(Lesson $lesson): ?string
    {
        $payload = (array) data_get($lesson->metadata, 'payload', []);
        $externalId = data_get($payload, 'video_external_id')
            ?? data_get($payload, 'external_id');

        if (filled($externalId)) {
            return (string) $externalId;
        }

        foreach ([$lesson->panda_embed_url, $lesson->panda_player_url, data_get($payload, 'video_player')] as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $query = parse_url($url, PHP_URL_QUERY);
            parse_str((string) $query, $params);

            if (filled($params['v'] ?? null)) {
                return (string) $params['v'];
            }
        }

        return null;
    }

    protected function pandaPullzoneName(Lesson $lesson): ?string
    {
        $payload = (array) data_get($lesson->metadata, 'payload', []);

        foreach ([
            data_get($payload, 'pullzone_name'),
            data_get($payload, 'pullzone'),
        ] as $pullzoneName) {
            if (filled($pullzoneName)) {
                return (string) $pullzoneName;
            }
        }

        foreach ([
            data_get($payload, 'video_player'),
            data_get($payload, 'video_hls'),
            data_get($payload, 'thumbnail'),
            data_get($payload, 'preview'),
            $lesson->panda_embed_url,
            $lesson->panda_player_url,
            $lesson->thumbnail_url,
        ] as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            if (preg_match('/(?:^|[.\/-])(vz-[a-z0-9-]+)/i', $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

}
