<?php

namespace App\Livewire;

use App\Models\Course;
use App\Models\StudyPlan;
use App\Models\StudyTrack;
use App\Services\StudyPlanGenerator;
use App\Support\StudyTime;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class StudyPlanBuilder extends Component
{
    public ?StudyPlan $studyPlan = null;
    public ?int $course_id = null;
    public ?int $study_track_id = null;
    public string $exam_date = '';
    public string $start_date = '';
    public array $available_days = [];
    public array $available_minutes_by_day = [];
    public string $intensity = 'balanced';

    public array $dayLabels = [
        'monday' => 'Segunda',
        'tuesday' => 'Terça',
        'wednesday' => 'Quarta',
        'thursday' => 'Quinta',
        'friday' => 'Sexta',
        'saturday' => 'Sábado',
        'sunday' => 'Domingo',
    ];

    public function mount(?StudyPlan $studyPlan = null): void
    {
        $this->studyPlan = $studyPlan;

        if ($this->studyPlan) {
            $this->fillFromStudyPlan($this->studyPlan);

            return;
        }

        $this->resetBuilder();
    }

    public function resetBuilder(): void
    {
        $this->course_id = null;
        $this->study_track_id = null;
        $this->start_date = now()->toDateString();
        $this->exam_date = '';
        $this->available_days = [];
        $this->intensity = 'balanced';

        foreach (array_keys($this->dayLabels) as $day) {
            $this->available_minutes_by_day[$day] = '00:00';
        }
    }

    public function fillFromStudyPlan(StudyPlan $studyPlan): void
    {
        $this->course_id = $studyPlan->course_id;
        $this->study_track_id = $studyPlan->study_track_id;
        $this->start_date = $studyPlan->start_date?->toDateString() ?? now()->toDateString();
        $this->exam_date = $studyPlan->exam_date?->toDateString() ?? '';
        $this->available_days = $studyPlan->available_days ?? [];
        $this->intensity = $studyPlan->intensity ?: 'balanced';

        foreach (array_keys($this->dayLabels) as $day) {
            $minutes = (int) ($studyPlan->available_minutes_by_day[$day] ?? 0);
            $this->available_minutes_by_day[$day] = $minutes > 0
                ? StudyTime::formatMinutes($minutes)
                : '00:00';
        }
    }

    public function updatedCourseId(): void
    {
        $this->study_track_id = null;
    }

    public function generate(StudyPlanGenerator $generator)
    {
        $data = $this->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'study_track_id' => ['nullable', 'exists:study_tracks,id'],
            'exam_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'available_days' => ['required', 'array', 'min:1'],
            'available_days.*' => ['required', Rule::in(array_keys($this->dayLabels))],
            'available_minutes_by_day' => ['required', 'array'],
            'intensity' => ['required', Rule::in(['light', 'balanced', 'intense'])],
        ]);

        $parsedAvailability = [];

        foreach ($this->available_days as $day) {
            $minutes = StudyTime::parseToMinutes($this->available_minutes_by_day[$day] ?? null);

            if ($minutes < 30) {
                $this->addError("available_minutes_by_day.$day", 'Informe o tempo no formato 1:20 e com pelo menos 30 minutos para cada dia selecionado.');
                return null;
            }

            $parsedAvailability[$day] = $minutes;
        }

        $course = Course::findOrFail($data['course_id']);
        $track = $data['study_track_id'] ? StudyTrack::findOrFail($data['study_track_id']) : null;

        $plan = $generator->generate(
            Auth::user(),
            $course,
            $track,
            $data['exam_date'] ?: null,
            $data['start_date'],
            $data['available_days'],
            $parsedAvailability,
            $data['intensity'],
        );

        session()->flash('status', 'Seu plano está pronto. Agora é execução.');

        return redirect()->route('study-plans.show', $plan);
    }

    public function render(): View
    {
        $courses = Auth::user()
            ->courses()
            ->where('courses.is_active', true)
            ->select('courses.id', 'courses.name')
            ->distinct()
            ->orderBy('name')
            ->get();

        $tracks = $this->course_id
            ? StudyTrack::where('course_id', $this->course_id)->where('is_active', true)->orderBy('name')->get()
            : collect();

        return view('livewire.study-plan-builder', [
            'courses' => $courses,
            'tracks' => $tracks,
        ]);
    }
}
