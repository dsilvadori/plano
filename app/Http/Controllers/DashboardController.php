<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\LessonProgress;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);

        $activePlans = $user->studyPlans()
            ->with(['course', 'items'])
            ->where('status', 'active')
            ->latest()
            ->get();

        $activePlan = $activePlans->first();

        $today = now()->toDateString();
        $nextTasks = $activePlan?->items()->whereDate('scheduled_date', '>=', $today)->whereNull('completed_at')->limit(5)->get() ?? collect();
        $availableCourseIds = $user->availableCoursesQuery()->pluck('courses.id');
        $featuredOnlineCourses = Course::query()
            ->where('is_active', true)
            ->where('status', 'published')
            ->where('is_featured', true)
            ->with(['sphere', 'educationLevel'])
            ->withCount(['modules', 'lessons'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(3)
            ->get();

        $courseProgress = $this->progressForCourses($featuredOnlineCourses, $user);

        return view('dashboard.index', [
            'user' => $user,
            'availableCourses' => $user->availableCoursesQuery()->orderBy('name')->get(),
            'availableCourseIds' => $availableCourseIds,
            'featuredOnlineCourses' => $featuredOnlineCourses,
            'courseProgress' => $courseProgress,
            'activePlan' => $activePlan,
            'activePlans' => $activePlans,
            'nextTasks' => $nextTasks,
            'weekStart' => now()->startOfWeek(CarbonInterface::MONDAY),
            'weekEnd' => now()->endOfWeek(CarbonInterface::SUNDAY),
        ]);
    }

    protected function progressForCourses(Collection $courses, User $user): array
    {
        $courseIds = $courses->pluck('id')->filter()->unique()->values();

        if ($courseIds->isEmpty()) {
            return [];
        }

        $lessonTotals = DB::table('course_module_lessons')
            ->join('course_module_course', 'course_module_course.course_module_id', '=', 'course_module_lessons.course_module_id')
            ->join('course_modules', 'course_modules.id', '=', 'course_module_lessons.course_module_id')
            ->join('lessons', 'lessons.id', '=', 'course_module_lessons.lesson_id')
            ->whereIn('course_module_course.course_id', $courseIds)
            ->where('lessons.status', 'published')
            ->selectRaw('course_module_course.course_id, count(distinct lessons.id) as total')
            ->groupBy('course_module_course.course_id')
            ->pluck('total', 'course_module_course.course_id');

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
}
