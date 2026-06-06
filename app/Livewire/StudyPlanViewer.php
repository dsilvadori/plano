<?php

namespace App\Livewire;

use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Support\StudyTime;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StudyPlanViewer extends Component
{
    use AuthorizesRequests;

    public StudyPlan $studyPlan;
    public int $selectedWeek = 1;

    protected $listeners = ['study-plan-item-toggled' => '$refresh'];

    public function mount(StudyPlan $studyPlan): void
    {
        $this->authorize('view', $studyPlan);
        $this->studyPlan = $studyPlan->load(['items.courseModule', 'course', 'studyTrack']);
        $this->selectedWeek = (int) $this->studyPlan->items->min('week_number') ?: 1;
    }

    public function selectWeek(int $week): void
    {
        $this->selectedWeek = $week;
    }

    public function render(): View
    {
        $this->studyPlan->load(['items.courseModule']);

        $grouped = $this->studyPlan->items
            ->groupBy('week_number')
            ->map(fn ($items) => $items->groupBy(fn ($item) => $item->scheduled_date->format('d/m/Y')));

        $selectedWeekItems = $this->studyPlan->items->where('week_number', $this->selectedWeek);
        $weeklySummary = [
            'total_minutes' => (int) $selectedWeekItems->sum('estimated_minutes'),
            'review_minutes' => (int) $selectedWeekItems->where('type', 'review')->sum('estimated_minutes'),
            'questions_minutes' => (int) $selectedWeekItems->where('type', 'questions')->sum('estimated_minutes'),
            'tasks' => $selectedWeekItems->count(),
        ];
        $completedItems = $this->studyPlan->items->whereNotNull('completed_at');
        $completedMinutes = (int) $completedItems->sum('estimated_minutes');
        $pendingMinutes = max(0, (int) $this->studyPlan->total_required_minutes - $completedMinutes);
        $overviewSummary = [
            'tasks_total' => $this->studyPlan->items->count(),
            'tasks_completed' => $completedItems->count(),
            'tasks_pending' => max(0, $this->studyPlan->items->count() - $completedItems->count()),
            'minutes_completed' => $completedMinutes,
            'minutes_pending' => $pendingMinutes,
            'completion_percentage' => $this->studyPlan->progress_percentage,
        ];
        $typeLabels = [
            'basic' => 'Matérias Básicas',
            'specific' => 'Conhecimentos Específicos',
            'complementary' => 'Conhecimentos Complementares',
            'review' => 'Revisões',
            'questions' => 'Resolução de Questões',
            'other' => 'Complementar',
        ];
        $typeOverview = collect($typeLabels)
            ->map(function (string $label, string $type) {
                $items = $this->studyPlan->items->where('type', $type);
                $completedItems = $items->whereNotNull('completed_at');
                $totalMinutes = (int) $items->sum('estimated_minutes');
                $completedMinutes = (int) $completedItems->sum('estimated_minutes');

                return [
                    'key' => $type,
                    'label' => $label,
                    'tasks' => $items->count(),
                    'completed_tasks' => $completedItems->count(),
                    'total_minutes' => $totalMinutes,
                    'completed_minutes' => $completedMinutes,
                    'progress' => $totalMinutes > 0 ? min(100, (int) round(($completedMinutes / $totalMinutes) * 100)) : 0,
                    'minutes_label' => StudyTime::formatMinutes($completedMinutes) . ' / ' . StudyTime::formatMinutes($totalMinutes),
                ];
            })
            ->filter(fn (array $row) => $row['tasks'] > 0)
            ->values();

        $theoryMinutes = max(0, $weeklySummary['total_minutes'] - $weeklySummary['review_minutes'] - $weeklySummary['questions_minutes']);
        $weeklyFocusMessage = match (true) {
            $weeklySummary['total_minutes'] === 0 => 'Esta semana ainda não tem blocos planejados.',
            $weeklySummary['review_minutes'] > 0 && $weeklySummary['questions_minutes'] > 0
                => 'Nesta semana você vai avançar em teoria, revisar o que estudou e testar retenção com questões.',
            $weeklySummary['review_minutes'] > 0
                => 'Nesta semana o plano reserva um espaço especial para revisão e consolidação.',
            $weeklySummary['questions_minutes'] > 0
                => 'Nesta semana o plano já separa tempo de prática para você medir evolução com questões.',
            default => 'Nesta semana o foco está concentrado em construir base e avançar no conteúdo principal.',
        };

        $weeklyBreakdownMessage = $weeklySummary['total_minutes'] > 0
            ? 'Você vai dedicar ' . StudyTime::formatMinutes($theoryMinutes) . ' para teoria, '
                . StudyTime::formatMinutes($weeklySummary['review_minutes']) . ' para revisão e '
                . StudyTime::formatMinutes($weeklySummary['questions_minutes']) . ' para questões.'
            : 'Assim que o plano tiver blocos nesta semana, mostramos a distribuição aqui.';

        $selectedWeekRange = null;
        $itemDescriptions = $this->buildItemDescriptions();

        if ($selectedWeekItems->isNotEmpty()) {
            $firstItem = $selectedWeekItems->first();
            $weekStart = $firstItem->scheduled_date->copy()->startOfWeek(CarbonInterface::MONDAY);
            $weekEnd = $firstItem->scheduled_date->copy()->endOfWeek(CarbonInterface::SUNDAY);
            $selectedWeekRange = $weekStart->format('d/m') . ' até ' . $weekEnd->format('d/m');
        }

        return view('livewire.study-plan-viewer', [
            'groupedItems' => $grouped,
            'availableWeeks' => $grouped->keys(),
            'selectedWeekItems' => $grouped->get($this->selectedWeek, collect()),
            'weeklySummary' => $weeklySummary,
            'overviewSummary' => $overviewSummary,
            'typeOverview' => $typeOverview,
            'weeklyFocusMessage' => $weeklyFocusMessage,
            'weeklyBreakdownMessage' => $weeklyBreakdownMessage,
            'selectedWeekRange' => $selectedWeekRange,
            'itemDescriptions' => $itemDescriptions,
        ]);
    }

    protected function buildItemDescriptions(): array
    {
        $lessonIndexes = [];
        $descriptionsByItem = [];

        $this->studyPlan->items
            ->sortBy([
                ['scheduled_date', 'asc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->each(function (StudyPlanItem $item) use (&$lessonIndexes, &$descriptionsByItem) {
                if (! in_array($item->type, ['basic', 'specific', 'complementary'], true) || ! $item->courseModule) {
                    return;
                }

                $moduleId = $item->courseModule->id;
                $lessons = $item->courseModule->planning_lessons;
                $index = $lessonIndexes[$moduleId] ?? 0;
                $minutes = 0;
                $names = [];

                while ($index < count($lessons)) {
                    $lessonMinutes = (int) ($lessons[$index]['minutes'] ?? 0);

                    if ($lessonMinutes <= 0) {
                        $index++;
                        continue;
                    }

                    if (($minutes + $lessonMinutes) > $item->estimated_minutes) {
                        break;
                    }

                    $names[] = (string) ($lessons[$index]['name'] ?? $item->courseModule->name);
                    $minutes += $lessonMinutes;
                    $index++;
                }

                $lessonIndexes[$moduleId] = $index;

                if ($names !== []) {
                    $descriptionsByItem[$item->id] = 'Bloco de ' . StudyTime::formatMinutes((int) $item->estimated_minutes)
                        . '. Aulas do bloco: ' . $this->formatLessonNames($names) . '.';
                }
            });

        return $descriptionsByItem;
    }

    protected function formatLessonNames(array $lessonNames): string
    {
        $lessonNames = array_values(array_filter(array_map(fn ($name) => trim((string) $name), $lessonNames)));

        return match (count($lessonNames)) {
            0 => '',
            1 => $lessonNames[0],
            2 => $lessonNames[0] . ' e ' . $lessonNames[1],
            default => implode(', ', array_slice($lessonNames, 0, -1)) . ' e ' . $lessonNames[array_key_last($lessonNames)],
        };
    }
}
