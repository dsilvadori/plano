<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseSphere;
use App\Models\EducationLevel;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\StudyPlanItem;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CourseCatalogController extends Controller
{
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

    public function lesson(Course $course, Lesson $lesson): View
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);
        abort_unless($course->is_active && $course->status === 'published', 404);
        abort_unless($lesson->status === 'published' && $this->lessonBelongsToCourse($lesson, $course), 404);
        abort_unless($this->userCanAccessCourse($user, $course), 403);

        $lesson->load(['course', 'module', 'aiArtifacts']);

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
}
