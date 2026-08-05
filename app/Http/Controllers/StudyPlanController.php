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
    public function index(): View
    {
        $user = request()->user();

        abort_unless($user?->canAccessStudentArea(), 403);

        return view('dashboard.study-plans.index', [
            'plans' => $user->studyPlans()
                ->with(['course', 'items'])
                ->latest()
                ->get(),
        ]);
    }

    public function create(): View
    {
        abort_unless(request()->user()?->canAccessStudentArea(), 403);

        $this->resetBuilderSession();

        return view('dashboard.study-plans.create', [
            'studyPlan' => null,
            'builderKey' => 'study-plan-builder-create-' . now()->timestamp,
        ]);
    }

    public function store(Request $request, StudyPlanGenerator $generator): RedirectResponse
    {
        abort_unless($request->user()?->canAccessStudentArea(), 403);

        [$data, $parsedAvailability] = $this->validatePlanRequest($request);

        $course = $request->user()->availableCoursesQuery()->findOrFail($data['course_id']);

        if ($existingPlan = $this->activePlanForCourse($request, (int) $course->id)) {
            return redirect()
                ->route('study-plans.show', $existingPlan)
                ->with('status', 'Este curso já tem um plano ativo. Você pode continuar ou editar o plano existente.');
        }

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

        return redirect()->route('study-plans.show', $plan);
    }

    public function edit(StudyPlan $studyPlan): View
    {
        $this->authorize('update', $studyPlan);
        abort_unless(request()->user()?->canAccessStudentArea(), 403);

        $this->resetBuilderSession();

        return view('dashboard.study-plans.create', [
            'studyPlan' => $studyPlan->loadMissing('course'),
            'builderKey' => 'study-plan-builder-edit-' . $studyPlan->id . '-' . now()->timestamp,
        ]);
    }

    public function update(Request $request, StudyPlan $studyPlan, StudyPlanGenerator $generator): RedirectResponse
    {
        $this->authorize('update', $studyPlan);
        abort_unless($request->user()?->canAccessStudentArea(), 403);

        [$data, $parsedAvailability] = $this->validatePlanRequest($request);

        $course = $request->user()->availableCoursesQuery()->findOrFail($data['course_id']);

        if ($existingPlan = $this->activePlanForCourse($request, (int) $course->id, $studyPlan)) {
            return back()
                ->withErrors(['course_id' => 'Este curso já possui outro plano ativo. Edite o plano existente desse curso ou escolha outro curso.'])
                ->withInput();
        }

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

        return redirect()
            ->route('study-plans.show', $plan)
            ->with('status', 'Plano atualizado com sucesso. Reorganizamos o restante do ciclo sem perder o progresso já concluído.');
    }

    public function rebalance(StudyPlan $studyPlan, StudyPlanGenerator $generator): RedirectResponse
    {
        $this->authorize('update', $studyPlan);
        abort_unless(request()->user()?->canAccessStudentArea(), 403);

        $plan = $generator->smartRebalance($studyPlan);

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
        $messages = $this->validationMessages();
        $attributes = $this->validationAttributes();

        $baseData = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
        ], $messages, $attributes);

        $course = $request->user()->availableCoursesQuery()->findOrFail($baseData['course_id']);

        $data = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'exam_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'available_days' => ['required', 'array', 'min:1'],
            'available_days.*' => ['required', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'available_minutes_by_day' => ['required', 'array'],
            'intensity' => ['required', Rule::in(['light', 'balanced', 'intense'])],
        ], $messages, $attributes);

        $data['exam_date'] = $course->exam_date?->toDateString() ?? ($data['exam_date'] ?: null);

        $parsedAvailability = [];

        foreach ($data['available_days'] as $day) {
            $minutes = StudyTime::parseToMinutes($data['available_minutes_by_day'][$day] ?? null);

            $data['available_minutes_by_day'][$day] = StudyTime::formatMinutes($minutes);

            if ($minutes < 30) {
                return throw \Illuminate\Validation\ValidationException::withMessages([
                    "available_minutes_by_day.$day" => 'Informe o tempo no formato 1:20, com pelo menos 30 minutos e no máximo 8 horas por dia.',
                ]);
            }

            $parsedAvailability[$day] = $minutes;
        }

        return [$data, $parsedAvailability];
    }

    protected function validationMessages(): array
    {
        return [
            'required' => 'O campo :attribute é obrigatório.',
            'exists' => 'O :attribute selecionado não está disponível.',
            'date' => 'Informe uma data válida para :attribute.',
            'after_or_equal' => 'O campo :attribute deve ser uma data igual ou posterior a :date.',
            'exam_date.after_or_equal' => 'A data da prova deve ser igual ou posterior à data de início.',
            'start_date.after_or_equal' => 'A data de início deve ser hoje ou uma data futura.',
            'array' => 'O campo :attribute deve ser preenchido corretamente.',
            'min' => 'Selecione pelo menos :min opção em :attribute.',
            'in' => 'O valor selecionado para :attribute é inválido.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'course_id' => 'curso',
            'exam_date' => 'data da prova',
            'start_date' => 'data de início',
            'available_days' => 'dias disponíveis',
            'available_days.*' => 'dia disponível',
            'available_minutes_by_day' => 'tempo disponível',
            'intensity' => 'intensidade',
        ];
    }

    protected function activePlanForCourse(Request $request, int $courseId, ?StudyPlan $except = null): ?StudyPlan
    {
        return $request->user()
            ->studyPlans()
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->latest('id')
            ->first();
    }

    protected function resetBuilderSession(): void
    {
        session()->forget('_old_input');
        session()->forget('errors');
    }
}
