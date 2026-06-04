<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Services\StudyPlanGenerator;
use App\Support\StudyTime;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudyPlanController extends Controller
{
    public function create(): View
    {
        abort_unless(request()->user()?->isStudent(), 403);

        $this->resetBuilderSession();

        return view('dashboard.study-plans.create', [
            'studyPlan' => null,
            'builderKey' => 'study-plan-builder-create-' . now()->timestamp,
        ]);
    }

    public function store(Request $request, StudyPlanGenerator $generator): RedirectResponse
    {
        abort_unless($request->user()?->isStudent(), 403);

        [$data, $parsedAvailability] = $this->validatePlanRequest($request);

        $course = Course::findOrFail($data['course_id']);
        $plan = $generator->generate(
            $request->user(),
            $course,
            null,
            $data['exam_date'] ?: null,
            $data['start_date'],
            $data['available_days'],
            $parsedAvailability,
            $data['intensity'],
        );

        sleep(2);

        return redirect()->route('study-plans.show', $plan);
    }

    public function edit(StudyPlan $studyPlan): View
    {
        $this->authorize('update', $studyPlan);
        abort_unless(request()->user()?->isStudent(), 403);

        $this->resetBuilderSession();

        return view('dashboard.study-plans.create', [
            'studyPlan' => $studyPlan->loadMissing('course'),
            'builderKey' => 'study-plan-builder-edit-' . $studyPlan->id . '-' . now()->timestamp,
        ]);
    }

    public function update(Request $request, StudyPlan $studyPlan, StudyPlanGenerator $generator): RedirectResponse
    {
        $this->authorize('update', $studyPlan);
        abort_unless($request->user()?->isStudent(), 403);

        [$data, $parsedAvailability] = $this->validatePlanRequest($request);

        $course = Course::findOrFail($data['course_id']);
        $plan = $generator->regenerate(
            $studyPlan,
            $course,
            null,
            $data['exam_date'] ?: null,
            $data['start_date'],
            $data['available_days'],
            $parsedAvailability,
            $data['intensity'],
        );

        sleep(2);

        return redirect()
            ->route('study-plans.show', $plan)
            ->with('status', 'Plano atualizado com sucesso. Reorganizamos seu ciclo com a nova configuração.');
    }

    public function rebalance(StudyPlan $studyPlan, StudyPlanGenerator $generator): RedirectResponse
    {
        $this->authorize('update', $studyPlan);
        abort_unless(request()->user()?->isStudent(), 403);

        $plan = $generator->smartRebalance($studyPlan);

        sleep(2);

        return redirect()
            ->route('study-plans.show', $plan)
            ->with('status', 'Reajuste concluído. Preservamos o que você já concluiu e reorganizamos automaticamente só o restante do plano a partir de hoje.');
    }

    public function show(StudyPlan $studyPlan): View
    {
        $this->authorize('view', $studyPlan);

        return view('dashboard.study-plans.show', [
            'studyPlan' => $studyPlan,
        ]);
    }

    public function toggle(StudyPlan $studyPlan, StudyPlanItem $item): RedirectResponse
    {
        $this->authorize('view', $studyPlan);
        abort_unless($item->study_plan_id === $studyPlan->id, 404);

        $item->toggleCompleted();

        return back();
    }

    public function destroy(StudyPlan $studyPlan): RedirectResponse
    {
        $this->authorize('delete', $studyPlan);

        $studyPlan->delete();

        return redirect()
            ->route('dashboard')
            ->with('status', 'Plano apagado com sucesso. Agora você pode montar um novo ciclo.');
    }

    protected function validatePlanRequest(Request $request): array
    {
        $baseData = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
        ]);

        $course = Course::findOrFail($baseData['course_id']);

        $data = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'exam_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'available_days' => ['required', 'array', 'min:1'],
            'available_days.*' => ['required', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'available_minutes_by_day' => ['required', 'array'],
            'intensity' => ['required', Rule::in(['light', 'balanced', 'intense'])],
        ]);

        $data['exam_date'] = $course->exam_date?->toDateString() ?? ($data['exam_date'] ?: null);

        $parsedAvailability = [];

        foreach ($data['available_days'] as $day) {
            $minutes = StudyTime::parseToMinutes($data['available_minutes_by_day'][$day] ?? null);

            if ($minutes < 30) {
                return throw \Illuminate\Validation\ValidationException::withMessages([
                    "available_minutes_by_day.$day" => 'Informe o tempo no formato 1:20 e com pelo menos 30 minutos por dia selecionado.',
                ]);
            }

            $parsedAvailability[$day] = $minutes;
        }

        return [$data, $parsedAvailability];
    }

    protected function resetBuilderSession(): void
    {
        session()->forget('_old_input');
        session()->forget('errors');
    }
}
