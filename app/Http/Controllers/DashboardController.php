<?php

namespace App\Http\Controllers;

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

        return view('dashboard.index', [
            'user' => $user,
            'availableCourses' => $user->availableCoursesQuery()->orderBy('name')->get(),
            'activePlan' => $activePlan,
            'activePlans' => $activePlans,
            'nextTasks' => $nextTasks,
            'weekStart' => now()->startOfWeek(CarbonInterface::MONDAY),
            'weekEnd' => now()->endOfWeek(CarbonInterface::SUNDAY),
        ]);
    }
}
