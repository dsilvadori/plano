<?php

namespace App\Http\Controllers;

use App\Models\AiArtifact;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\CourseSphere;
use App\Models\EducationLevel;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\QuestionBank;
use App\Models\StudyPlanItem;
use App\Models\User;
use App\Services\LessonSummaryPdfGenerator;
use App\Services\PandaAiResourceActivator;
use App\Services\PandaTutorActivator;
use App\Services\PandaVideoClient;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class CourseCatalogController extends Controller
{
    protected const LESSON_AI_ARTIFACT_TYPES = ['summary', 'quiz', 'mindmap'];

    protected const PANDA_AI_REQUEST_RETRY_MINUTES = 60;

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
        return $this->modules($course);
    }

    public function modules(Course $course): View
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
        ])->loadCount(['lessons']);

        $modules = $this->modulesForCourse($course);
        $course->setRelation('modules', $modules);
        $course->setAttribute('modules_count', $modules->count());
        $course->setAttribute('lessons_count', $this->publishedLessonsForCourse($course)->count('lessons.id'));

        return view('dashboard.courses.show', [
            'course' => $course,
            'hasAccess' => $hasAccess,
            'progressSummary' => $this->progressForCourse($course, $user),
            'completedLessonIds' => $this->completedLessonIdsForCourse($course, $user),
            'inProgressLessonIds' => $this->inProgressLessonIdsForCourse($course, $user),
            'continueLesson' => $hasAccess ? $this->continueLessonForCourse($course, $user) : null,
            'trackEntryLessons' => $hasAccess ? $this->trackEntryLessonsForCourse($course, $user) : collect(),
        ]);
    }

    public function moduleTracks(Course $course, CourseModule $module): View
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);
        abort_unless($course->is_active && $course->status === 'published', 404);
        abort_unless($this->moduleBelongsToCourse($module, $course), 404);

        $hasAccess = $this->userCanAccessCourse($user, $course);

        $module->load(['tracks' => fn ($trackQuery) => $trackQuery
            ->where('status', 'published')
            ->where(function (Builder $query) use ($course): void {
                $query
                    ->whereHas('courses', fn (Builder $query) => $query->whereKey($course->id))
                    ->orWhereHas('module', fn (Builder $query) => $query
                        ->where('course_id', $course->id)
                        ->orWhereHas('courses', fn (Builder $query) => $query->whereKey($course->id))
                        ->orWhereHas('studyTracks', fn (Builder $query) => $query->where('course_id', $course->id)));
            })
            ->with(['lessons' => fn ($lessonQuery) => $lessonQuery
                ->where('status', '!=', 'archived')
                ->orderBy('sort_order')
                ->orderBy('title'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name'),
        ]);

        return view('dashboard.courses.module-tracks', [
            'course' => $course,
            'module' => $module,
            'hasAccess' => $hasAccess,
            'completedLessonIds' => $this->completedLessonIdsForCourse($course, $user),
            'inProgressLessonIds' => $this->inProgressLessonIdsForCourse($course, $user),
            'trackEntryLessons' => $hasAccess ? $this->trackEntryLessonsForCourse($course, $user) : collect(),
        ]);
    }

    public function trackLessons(Course $course, CourseModule $module, CourseModuleTrack $track): View
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);
        abort_unless($course->is_active && $course->status === 'published', 404);
        abort_unless($this->moduleBelongsToCourse($module, $course), 404);
        abort_unless($this->trackBelongsToModuleAndCourse($track, $module, $course), 404);

        $hasAccess = $this->userCanAccessCourse($user, $course);

        $track->load(['lessons' => fn ($lessonQuery) => $lessonQuery
            ->where('status', '!=', 'archived')
            ->orderBy('sort_order')
            ->orderBy('title'),
        ]);

        return view('dashboard.courses.track-lessons', [
            'course' => $course,
            'module' => $module,
            'track' => $track,
            'hasAccess' => $hasAccess,
            'completedLessonIds' => $this->completedLessonIdsForCourse($course, $user),
            'inProgressLessonIds' => $this->inProgressLessonIdsForCourse($course, $user),
        ]);
    }

    public function lesson(Course $course, Lesson $lesson, PandaVideoClient $panda, PandaTutorActivator $tutor): View
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);
        abort_unless($course->is_active && $course->status === 'published', 404);
        abort_unless($lesson->status !== 'archived' && $this->lessonBelongsToCourse($lesson, $course), 404);
        abort_unless($this->userCanAccessCourse($user, $course), 403);

        $lesson->load(['course', 'module']);

        $this->ensureLessonAiArtifactsAreCached($lesson, $panda);
        $this->ensurePandaTutorAvailabilityIsCached($lesson, $tutor);

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

        $planLessonContext = $this->planLessonContextForLesson($user, $course, $lesson);

        return view('dashboard.courses.lesson', [
            'course' => $course,
            'lesson' => $lesson,
            'progress' => $progress->fresh(),
            'previousLesson' => $currentIndex === false ? null : $orderedLessons->get($currentIndex - 1),
            'nextLesson' => $currentIndex === false ? null : $orderedLessons->get($currentIndex + 1),
            'progressSummary' => $this->progressForCourse($course, $user),
            'aiArtifacts' => $lesson->aiArtifacts,
            'planLessonContext' => $planLessonContext,
            'lessonQuestionLinks' => $this->questionLinksForLesson($user, $course, $lesson),
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
        abort_unless($lesson->status !== 'archived' && $this->lessonBelongsToCourse($lesson, $course), 404);
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

    public function downloadLessonSummary(Course $course, Lesson $lesson, LessonSummaryPdfGenerator $pdf): Response
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);
        abort_unless($course->is_active && $course->status === 'published', 404);
        abort_unless($lesson->status !== 'archived' && $this->lessonBelongsToCourse($lesson, $course), 404);
        abort_unless($this->userCanAccessCourse($user, $course), 403);

        $summary = $this->lessonSummaryText($lesson);

        abort_if($summary === '', 404);

        $filename = str($lesson->title)
            ->ascii()
            ->slug('-')
            ->prepend('resumo-')
            ->append('.pdf')
            ->toString();

        return response($pdf->generate($course, $lesson, $summary), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function syncPandaAi(Course $course, Lesson $lesson, PandaAiResourceActivator $activator): RedirectResponse
    {
        $user = request()->user();

        abort_unless($user->isAdmin(), 403);
        abort_unless($course->is_active && $course->status === 'published', 404);
        abort_unless($lesson->status !== 'archived' && $this->lessonBelongsToCourse($lesson, $course), 404);

        $pandaVideoId = $lesson->panda_video_id ?: (string) data_get($lesson->metadata, 'payload.id');

        if (! $pandaVideoId) {
            return back()->with('error', 'Esta aula não tem ID de vídeo para gerar IA.');
        }

        try {
            $result = $activator->generate($lesson);
            $createdArtifacts = (int) $result['created_artifacts'];

            if ($createdArtifacts === 0) {
                return back()->with('status', 'Pacote de IA em português solicitado. O resultado ainda não está disponível; tente sincronizar novamente em alguns minutos.');
            }

            return back()->with('status', $createdArtifacts > 0
                ? 'IA da aula sincronizada com sucesso.'
                : 'A IA retornou dados, mas ainda não encontrei resumo, questões ou mapa mental no payload.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível sincronizar a IA da aula: '.$exception->getMessage());
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

        $completedTotals = LessonProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->where('status', 'completed')
            ->selectRaw('course_id, count(*) as completed')
            ->groupBy('course_id')
            ->pluck('completed', 'course_id');

        return $courseIds
            ->mapWithKeys(function (int $courseId) use ($completedTotals) {
                $course = Course::query()->find($courseId);
                $total = $course instanceof Course
                    ? (int) $this->publishedLessonsForCourse($course)->count('lessons.id')
                    : 0;
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

    protected function inProgressLessonIdsForCourse(Course $course, User $user): Collection
    {
        return LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'in_progress')
            ->whereNull('completed_at')
            ->pluck('lesson_id');
    }

    protected function continueLessonForCourse(Course $course, User $user): ?Lesson
    {
        $completedLessonIds = $this->completedLessonIdsForCourse($course, $user);

        return $this->publishedLessonsForCourse($course)
            ->when($completedLessonIds->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('lessons.id', $completedLessonIds))
            ->first();
    }

    protected function trackEntryLessonsForCourse(Course $course, User $user): Collection
    {
        $trackIds = $course->modules
            ->flatMap(fn (CourseModule $module) => $module->tracks)
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        if ($trackIds->isEmpty()) {
            return collect();
        }

        $orderedLessonsByTrack = DB::table('course_module_track_lessons')
            ->join('lessons', 'lessons.id', '=', 'course_module_track_lessons.lesson_id')
            ->whereIn('course_module_track_lessons.course_module_track_id', $trackIds)
            ->where('lessons.status', '!=', 'archived')
            ->select([
                'course_module_track_lessons.course_module_track_id',
                'lessons.id as lesson_id',
                'course_module_track_lessons.sort_order',
                'lessons.sort_order as lesson_sort_order',
                'lessons.title',
            ])
            ->orderBy('course_module_track_lessons.course_module_track_id')
            ->orderBy('course_module_track_lessons.sort_order')
            ->orderBy('lessons.sort_order')
            ->orderBy('lessons.title')
            ->get()
            ->groupBy('course_module_track_id');

        $lessonIds = $orderedLessonsByTrack
            ->flatMap(fn (Collection $lessons) => $lessons->pluck('lesson_id'))
            ->unique()
            ->values();

        if ($lessonIds->isEmpty()) {
            return collect();
        }

        $lessonsById = Lesson::query()
            ->whereIn('id', $lessonIds)
            ->get()
            ->keyBy('id');

        $progressByLesson = LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('lesson_id', $lessonIds)
            ->orderByDesc('updated_at')
            ->get()
            ->keyBy('lesson_id');

        return $orderedLessonsByTrack
            ->mapWithKeys(function (Collection $trackLessons, int $trackId) use ($lessonsById, $progressByLesson): array {
                $inProgressLessonId = $trackLessons
                    ->pluck('lesson_id')
                    ->filter(function (int $lessonId) use ($progressByLesson): bool {
                        $progress = $progressByLesson->get($lessonId);

                        return $progress
                            && $progress->status === 'in_progress'
                            && $progress->completed_at === null;
                    })
                    ->sortByDesc(fn (int $lessonId) => $progressByLesson->get($lessonId)?->updated_at?->timestamp ?? 0)
                    ->first();

                if ($inProgressLessonId) {
                    $lesson = $lessonsById->get($inProgressLessonId);

                    return $lesson ? [$trackId => $lesson] : [];
                }

                $nextLessonId = $trackLessons
                    ->pluck('lesson_id')
                    ->first(function (int $lessonId) use ($progressByLesson): bool {
                        $progress = $progressByLesson->get($lessonId);

                        return ! $progress || $progress->status !== 'completed';
                    });

                $lesson = $lessonsById->get($nextLessonId ?: (int) $trackLessons->first()->lesson_id);

                return $lesson ? [$trackId => $lesson] : [];
            });
    }

    protected function publishedLessonsForCourse(Course $course): Builder
    {
        return Lesson::query()
            ->select('lessons.*')
            ->join('course_module_track_lessons', 'course_module_track_lessons.lesson_id', '=', 'lessons.id')
            ->join('course_module_tracks', 'course_module_tracks.id', '=', 'course_module_track_lessons.course_module_track_id')
            ->join('course_modules', 'course_modules.id', '=', 'course_module_tracks.course_module_id')
            ->leftJoin('course_module_course', 'course_module_course.course_module_id', '=', 'course_modules.id')
            ->leftJoin('course_module_track_course', 'course_module_track_course.course_module_track_id', '=', 'course_module_tracks.id')
            ->where(function (Builder $query) use ($course): void {
                $query
                    ->where('lessons.course_id', $course->id)
                    ->orWhere('course_modules.course_id', $course->id)
                    ->orWhere('course_module_course.course_id', $course->id)
                    ->orWhere('course_module_track_course.course_id', $course->id)
                    ->orWhereExists(function ($query) use ($course): void {
                        $query->selectRaw('1')
                            ->from('study_track_modules')
                            ->join('study_tracks', 'study_tracks.id', '=', 'study_track_modules.study_track_id')
                            ->whereColumn('study_track_modules.course_module_id', 'course_modules.id')
                            ->where('study_tracks.course_id', $course->id);
                    });
            })
            ->where('course_module_tracks.status', 'published')
            ->where('lessons.status', '!=', 'archived')
            ->distinct()
            ->orderBy('course_module_course.sort_order')
            ->orderBy('course_modules.sort_order')
            ->orderBy('course_modules.name')
            ->orderBy('course_module_tracks.sort_order')
            ->orderBy('course_module_track_lessons.sort_order')
            ->orderBy('lessons.title');
    }

    protected function lessonBelongsToCourse(Lesson $lesson, Course $course): bool
    {
        if ((int) $lesson->course_id === (int) $course->id) {
            return true;
        }

        return $lesson->tracks()
            ->where(function (Builder $query) use ($course): void {
                $query
                    ->whereHas('courses', fn (Builder $query) => $query->whereKey($course->id))
                    ->orWhereHas('module.courses', fn (Builder $query) => $query->whereKey($course->id))
                    ->orWhereHas('module', fn (Builder $query) => $query->where('course_id', $course->id));
            })
            ->exists();
    }

    protected function moduleBelongsToCourse(CourseModule $module, Course $course): bool
    {
        if ((int) $module->course_id === (int) $course->id) {
            return true;
        }

        if ($module->courses()->whereKey($course->id)->exists()) {
            return true;
        }

        if ($module->studyTracks()->where('course_id', $course->id)->exists()) {
            return true;
        }

        return $module->tracks()
            ->whereHas('courses', fn (Builder $query) => $query->whereKey($course->id))
            ->exists();
    }

    protected function trackBelongsToModuleAndCourse(CourseModuleTrack $track, CourseModule $module, Course $course): bool
    {
        if ((int) $track->course_module_id !== (int) $module->id) {
            return false;
        }

        if ($track->courses()->whereKey($course->id)->exists()) {
            return true;
        }

        return $this->moduleBelongsToCourse($module, $course);
    }

    protected function modulesForCourse(Course $course): EloquentCollection
    {
        return CourseModule::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($course): void {
                $query
                    ->where('course_id', $course->id)
                    ->orWhereHas('courses', fn (Builder $query) => $query->whereKey($course->id))
                    ->orWhereHas('studyTracks', fn (Builder $query) => $query->where('course_id', $course->id))
                    ->orWhereHas('tracks.courses', fn (Builder $query) => $query->whereKey($course->id));
            })
            ->with(['tracks' => fn ($trackQuery) => $trackQuery
                ->where('status', 'published')
                ->where(function (Builder $query) use ($course): void {
                    $query
                        ->whereHas('courses', fn (Builder $query) => $query->whereKey($course->id))
                        ->orWhereHas('module', fn (Builder $query) => $query
                            ->where('course_id', $course->id)
                            ->orWhereHas('courses', fn (Builder $query) => $query->whereKey($course->id))
                            ->orWhereHas('studyTracks', fn (Builder $query) => $query->where('course_id', $course->id)));
                })
                ->with(['lessons' => fn ($lessonQuery) => $lessonQuery
                    ->where('status', '!=', 'archived')
                    ->orderBy('sort_order')
                    ->orderBy('title'),
                ])
                ->orderBy('sort_order')
                ->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
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

    protected function questionLinksForLesson(User $user, Course $course, Lesson $lesson): Collection
    {
        $moduleIds = collect([$lesson->course_module_id])
            ->merge($lesson->modules()->pluck('course_modules.id'))
            ->filter()
            ->unique()
            ->values();

        $lessonBanks = $this->publishedQuestionBanks()
            ->whereHas('lessons', fn (Builder $query) => $query->whereKey($lesson->id))
            ->with(['lessons:id,title', 'tracks:id,name', 'modules:id,name'])
            ->orderBy('title')
            ->get();

        $banks = $lessonBanks->isNotEmpty()
            ? $lessonBanks
            : ($moduleIds->isEmpty()
                ? collect()
                : $this->publishedQuestionBanks()
                    ->whereHas('modules', fn (Builder $query) => $query->whereIn('course_modules.id', $moduleIds))
                    ->with(['lessons:id,title', 'tracks:id,name', 'modules:id,name'])
                    ->orderBy('title')
                    ->get());

        $plan = $user->studyPlans()
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        return $banks
            ->map(function (QuestionBank $bank) use ($lesson, $moduleIds, $plan): array {
                $bankModuleIds = $bank->modules->pluck('id');

                $hasLessonMatch = $bank->lessons->contains(fn (Lesson $linkedLesson): bool => (int) $linkedLesson->id === (int) $lesson->id);
                $matchedModuleId = $bankModuleIds->first(fn (int $moduleId): bool => $moduleIds->contains($moduleId));

                $params = $hasLessonMatch
                    ? ['lesson_id' => $lesson->id]
                    : ['module_id' => $matchedModuleId];

                if ($plan) {
                    $params['plan_id'] = $plan->id;
                }

                $scope = $hasLessonMatch ? 'Aula atual' : 'Módulo';

                return [
                    'label' => 'Resolver questões: '.$bank->title,
                    'scope' => $scope,
                    'url' => route('questions.show', [$bank] + $params),
                    'priority' => $hasLessonMatch ? 1 : 2,
                ];
            })
            ->sortBy([
                ['priority', 'asc'],
                ['label', 'asc'],
            ])
            ->values();
    }

    protected function publishedQuestionBanks(): Builder
    {
        return QuestionBank::query()
            ->where('status', 'published')
            ->whereHas('questions', fn (Builder $query) => $query->where('status', 'published'));
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

            try {
                $this->syncMissingLessonAiArtifacts($lesson, $panda, $pandaVideoId);
            } catch (Throwable $exception) {
                report($exception);

                $metadata = $lesson->metadata ?? [];
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

    protected function syncMissingLessonAiArtifacts(Lesson $lesson, PandaVideoClient $panda, string $pandaVideoId, bool $forceRequest = false): int
    {
        if ($this->missingLessonAiArtifactTypes($lesson) === []) {
            return -1;
        }

        $metadata = $lesson->metadata ?? [];
        $pullzoneName = $this->pandaPullzoneName($lesson);
        $videoExternalId = $this->pandaVideoExternalId($lesson);
        $createdArtifacts = 0;

        if ($pullzoneName && $videoExternalId && $this->shouldPollLessonAiPackage($metadata)) {
            $aiPayload = $panda->aiPackage($pullzoneName, $videoExternalId);

            $metadata = array_replace_recursive($metadata, [
                'panda_ai' => [
                    'auto_sync_enabled' => true,
                    'pullzone_name' => $pullzoneName,
                    'video_external_id' => $videoExternalId,
                    'last_auto_sync_attempt_at' => now()->toIso8601String(),
                    'last_synced_at' => now()->toIso8601String(),
                    'last_payload_status' => $aiPayload ? 'ready' : 'not_ready',
                ],
            ]);

            if ($aiPayload) {
                $createdArtifacts = $this->syncPandaAiArtifactsFromPayload($lesson, $aiPayload);
                $lesson->refresh();
            }
        }

        if ($this->missingLessonAiArtifactTypes($lesson) !== [] && $this->shouldRequestLessonAiPackage($metadata, $forceRequest)) {
            $this->clearLessonPandaAiCache($lesson);

            $metadata = array_replace_recursive($metadata, [
                'panda_ai' => [
                    'auto_sync_enabled' => true,
                    'requested_at' => now()->toIso8601String(),
                    'last_request_status' => 'requested',
                    'last_request_language' => (string) config('services.panda.ai_from_lang', 'pt-BR'),
                    'last_auto_sync_attempt_at' => now()->toIso8601String(),
                    'last_payload_status' => 'regenerating',
                    'workflow_response' => $panda->createAiPackage($pandaVideoId),
                    'request_count' => ((int) data_get($metadata, 'panda_ai.request_count', 0)) + 1,
                ],
            ]);
        }

        $lesson->forceFill(['metadata' => $metadata])->save();

        return $createdArtifacts;
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
        $requestedAt = data_get($metadata, 'panda_ai.requested_at');
        $requestStatus = data_get($metadata, 'panda_ai.last_request_status');

        if ($requestStatus === 'requested' && filled($requestedAt)) {
            return now()->diffInMinutes(Carbon::parse($requestedAt)) >= (int) config('services.panda.ai_regeneration_poll_delay_minutes', 10);
        }

        $lastAttempt = data_get($metadata, 'panda_ai.last_auto_sync_attempt_at');

        if (blank($lastAttempt)) {
            return true;
        }

        return now()->diffInMinutes(Carbon::parse($lastAttempt)) >= 1;
    }

    protected function shouldRequestLessonAiPackage(array $metadata, bool $forceRequest = false): bool
    {
        $requestedAt = data_get($metadata, 'panda_ai.requested_at');

        if (blank($requestedAt)) {
            return true;
        }

        if (! $forceRequest) {
            return now()->diffInMinutes(Carbon::parse($requestedAt)) >= self::PANDA_AI_REQUEST_RETRY_MINUTES;
        }

        return now()->diffInMinutes(Carbon::parse($requestedAt)) >= 1;
    }

    protected function clearLessonPandaAiCache(Lesson $lesson): void
    {
        AiArtifact::query()
            ->where('source_type', Lesson::class)
            ->where('source_id', $lesson->id)
            ->where('provider', 'panda')
            ->whereIn('artifact_type', [...self::LESSON_AI_ARTIFACT_TYPES, 'panda_payload'])
            ->delete();

        Cache::forget("lesson:{$lesson->id}:ai-artifacts");
        Cache::forget("lesson:{$lesson->id}:ai-payload");
        $lesson->unsetRelation('aiArtifacts');
    }

    protected function ensurePandaTutorAvailabilityIsCached(Lesson $lesson, PandaTutorActivator $tutor): void
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

        if (filled($lastCheckedAt) && now()->diffInMinutes(Carbon::parse($lastCheckedAt)) < $cacheMinutes) {
            return;
        }

        Cache::lock("lesson:{$lesson->id}:panda-tutor-detect", 30)->get(function () use ($lesson, $tutor): void {
            $lesson->refresh();

            $lastCheckedAt = data_get($lesson->metadata, 'panda_ai.tutor_checked_at');
            $cacheMinutes = (bool) data_get($lesson->metadata, 'panda_ai.tutor_available', false) ? 10 : 1;

            if (filled($lastCheckedAt) && now()->diffInMinutes(Carbon::parse($lastCheckedAt)) < $cacheMinutes) {
                return;
            }

            try {
                $tutor->syncAvailability($lesson);
            } catch (Throwable $exception) {
                report($exception);

                $metadata = $lesson->metadata ?? [];
                $metadata = array_replace_recursive($metadata, [
                    'panda_ai' => [
                        'tutor_available' => (bool) data_get($metadata, 'panda_ai.tutor_available', false),
                        'tutor_checked_at' => now()->toIso8601String(),
                        'tutor_last_error' => $exception->getMessage(),
                    ],
                ]);

                $lesson->forceFill(['metadata' => $metadata])->save();
            }
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

        return $playerEmbedBase.'/assist_chat.html?'.http_build_query(['v' => $videoExternalId, 'l' => $pullzoneName]);
    }

    protected function pandaTutorConfigUrlForLesson(Lesson $lesson): ?string
    {
        $pullzoneName = $this->pandaPullzoneName($lesson);
        $videoExternalId = $this->pandaVideoExternalId($lesson);

        if (! $pullzoneName || ! $videoExternalId) {
            return null;
        }

        return rtrim((string) config('services.panda.ai_config_base_url'), '/')
            .'/'.trim($pullzoneName, '/')
            .'/'.$videoExternalId
            .'.json';
    }

    protected function lessonSummaryText(Lesson $lesson): string
    {
        $artifact = AiArtifact::query()
            ->where('source_type', Lesson::class)
            ->where('source_id', $lesson->id)
            ->where('status', 'ready')
            ->whereIn('artifact_type', ['summary', 'abstract'])
            ->orderByRaw("case when provider = 'panda' then 0 else 1 end")
            ->first();

        return $artifact ? $this->aiContentToText($artifact->content) : '';
    }

    protected function aiContentToText(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (! is_array($value)) {
            return '';
        }

        foreach (['text', 'content', 'summary', 'abstract', 'answer', 'description'] as $key) {
            if (array_key_exists($key, $value)) {
                $text = $this->aiContentToText($value[$key]);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return collect($value)
            ->map(fn (mixed $item): string => $this->aiContentToText($item))
            ->filter()
            ->join("\n\n");
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
