<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Services\StudyPlanGenerator;
use App\Support\StudyTime;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class StudyPlanController extends Controller
{
    public function index(): View
    {
        $user = request()->user();

        abort_unless($user?->canAccessStudentArea(), 403);

        $plans = $user->studyPlans()
            ->with(['course', 'items'])
            ->latest()
            ->get();
        $plannedCourseIds = $plans
            ->where('status', 'active')
            ->pluck('course_id')
            ->filter()
            ->unique();
        $canCreatePlan = $user->availableCoursesQuery()
            ->whereNotIn('courses.id', $plannedCourseIds)
            ->exists();

        return view('dashboard.study-plans.index', [
            'plans' => $plans,
            'canCreatePlan' => $canCreatePlan,
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

        $plan = $generator->regenerateFromDate(
            $studyPlan,
            $course,
            null,
            $data['exam_date'] ?: null,
            $data['start_date'],
            $data['available_days'],
            $parsedAvailability,
            $data['intensity'],
            now()->addWeek()->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
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
            'studyPlan' => $studyPlan->load(['items.courseModule', 'items.lessons', 'course', 'studyTrack']),
        ]);
    }

    public function toggle(StudyPlan $studyPlan, StudyPlanItem $item): RedirectResponse
    {
        $this->authorize('view', $studyPlan);
        abort_unless($item->study_plan_id === $studyPlan->id, 404);

        $item->toggleCompleted();

        return back();
    }

    public function lesson(StudyPlan $studyPlan, StudyPlanItem $item, Lesson $lesson): RedirectResponse
    {
        $this->authorize('view', $studyPlan);
        abort_unless($item->study_plan_id === $studyPlan->id, 404);
        abort_unless($item->lessons()->whereKey($lesson->id)->exists(), 404);

        abort_unless($studyPlan->course, 404);

        return redirect()->route('courses.lessons.show', [$studyPlan->course->slug, $lesson]);
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
                $this->throwValidationWithInput([
                    "available_minutes_by_day.$day" => 'Informe o tempo no formato 1:20, com pelo menos 30 minutos e no máximo 8 horas por dia.',
                ]);
            }

            $parsedAvailability[$day] = $minutes;
        }

        $minimumWeeklyMinutes = $this->minimumWeeklyMinutesForExam($course, $data['start_date'], $data['exam_date'], $data['intensity']);
        $weeklyMinutes = array_sum($parsedAvailability);

        if ($minimumWeeklyMinutes && $weeklyMinutes < $minimumWeeklyMinutes) {
            $this->throwValidationWithInput([
                'available_days' => 'Para gerar este plano até a prova, informe pelo menos '
                    .StudyTime::formatMinutes($minimumWeeklyMinutes)
                    .' por semana. Hoje você informou '
                    .StudyTime::formatMinutes($weeklyMinutes)
                    .'.',
            ]);
        }

        return [$data, $parsedAvailability];
    }

    protected function minimumWeeklyMinutesForExam(Course $course, string $startDate, ?string $examDate, string $intensity): ?int
    {
        if (blank($examDate)) {
            return null;
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $exam = Carbon::parse($examDate)->startOfDay();

        if ($exam->lt($start)) {
            return null;
        }

        $theoryMinutes = (int) $this->modulesForMinimumWeeklyCalculation($course)->sum('workload_minutes');

        if ($theoryMinutes <= 0) {
            return null;
        }

        $practicePercent = match ($intensity) {
            'light' => 0.35,
            'intense' => 0.25,
            default => 0.30,
        };
        $requiredMinutes = $theoryMinutes + (int) ceil($theoryMinutes * $practicePercent);
        $weeks = max(1, (int) ceil(($start->diffInDays($exam) + 1) / 7));

        return (int) ceil(($requiredMinutes / $weeks) / 15) * 15;
    }

    protected function modulesForMinimumWeeklyCalculation(Course $course): Collection
    {
        $officialTrack = $course->studyTracks()
            ->where('is_active', true)
            ->where('name', 'like', 'Trilha Oficial -%')
            ->orderBy('id')
            ->first();
        $modules = $officialTrack
            ? $officialTrack->modules()->where('course_modules.is_active', true)->get()
            : $course->modules()->where('is_active', true)->get();

        return $modules
            ->reject(fn (CourseModule $module): bool => $this->shouldSkipModuleForMinimumWeeklyCalculation($module))
            ->values();
    }

    protected function shouldSkipModuleForMinimumWeeklyCalculation(CourseModule $module): bool
    {
        $normalizedName = str($module->name)->lower()->ascii()->value();

        return str_contains($normalizedName, 'apresentacao')
            || str_contains($normalizedName, 'boas-vindas')
            || str_contains($normalizedName, 'boas vindas')
            || str_contains($normalizedName, 'bem-vindo')
            || str_contains($normalizedName, 'bem vindo');
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

    protected function throwValidationWithInput(array $messages): never
    {
        $validator = \Illuminate\Support\Facades\Validator::make([], []);

        foreach ($messages as $field => $message) {
            $validator->errors()->add($field, $message);
        }

        throw new \Illuminate\Validation\ValidationException(
            $validator,
            back()->withInput()->withErrors($validator),
        );
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
        if (session()->has('errors') || session()->hasOldInput()) {
            return;
        }

        session()->forget('_old_input');
        session()->forget('errors');
    }
}
