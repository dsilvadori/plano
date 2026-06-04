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

        if ($user->isAdmin()) {
            return redirect('/admin');
        }

        abort_unless($user->isStudent(), 403);

        $activePlans = $user->studyPlans()
            ->with(['course', 'items'])
            ->where('status', 'active')
            ->latest()
            ->get();

        $activePlan = $activePlans->first();

        $today = now()->toDateString();
        $nextTasks = $activePlan?->items()->whereDate('scheduled_date', '>=', $today)->whereNull('completed_at')->limit(5)->get() ?? collect();

        return view('dashboard.index', [
            'user' => $user->load('courses'),
            'activePlan' => $activePlan,
            'activePlans' => $activePlans,
            'nextTasks' => $nextTasks,
            'weekStart' => now()->startOfWeek(CarbonInterface::MONDAY),
            'weekEnd' => now()->endOfWeek(CarbonInterface::SUNDAY),
        ]);
    }
}
