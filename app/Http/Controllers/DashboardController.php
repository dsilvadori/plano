<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

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

        return view('dashboard.index', [
            'user' => $user,
            'availableCourses' => $user->availableCoursesQuery()->orderBy('name')->get(),
            'availableCourseIds' => $availableCourseIds,
            'featuredOnlineCourses' => $featuredOnlineCourses,
            'activePlan' => $activePlan,
            'activePlans' => $activePlans,
            'nextTasks' => $nextTasks,
            'weekStart' => now()->startOfWeek(CarbonInterface::MONDAY),
            'weekEnd' => now()->endOfWeek(CarbonInterface::SUNDAY),
        ]);
    }
}
