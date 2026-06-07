<?php

namespace App\Livewire;

use App\Models\CourseModule;
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
        $orderedItems = $this->orderedPlanItems();

        $grouped = $orderedItems
            ->groupBy('week_number')
            ->map(fn ($items) => $items->groupBy(fn ($item) => $item->scheduled_date->format('d/m/Y')));

        $selectedWeekItems = $orderedItems->where('week_number', $this->selectedWeek);
        $weeklySummary = [
            'total_minutes' => (int) $selectedWeekItems->sum('estimated_minutes'),
            'review_minutes' => (int) $selectedWeekItems->where('type', 'review')->sum('estimated_minutes'),
            'questions_minutes' => (int) $selectedWeekItems->where('type', 'questions')->sum('estimated_minutes'),
            'tasks' => $selectedWeekItems->count(),
        ];
        $completedItems = $orderedItems->whereNotNull('completed_at');
        $completedMinutes = (int) $completedItems->sum('estimated_minutes');
        $pendingMinutes = (int) $orderedItems->whereNull('completed_at')->sum('estimated_minutes');
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
            ->map(function (string $label, string $type) use ($orderedItems) {
                $items = $orderedItems->where('type', $type);
                $completedItems = $items->whereNotNull('completed_at');
                $totalMinutes = (int) $items->sum('estimated_minutes');
                $completedMinutes = (int) $completedItems->sum('estimated_minutes');
                $pendingMinutes = max(0, $totalMinutes - $completedMinutes);
                $progress = match (true) {
                    $totalMinutes <= 0 => 0,
                    $pendingMinutes <= 0 => 100,
                    default => min(99, (int) round(($completedMinutes / $totalMinutes) * 100)),
                };

                return [
                    'key' => $type,
                    'label' => $label,
                    'tasks' => $items->count(),
                    'completed_tasks' => $completedItems->count(),
                    'total_minutes' => $totalMinutes,
                    'completed_minutes' => $completedMinutes,
                    'progress' => $progress,
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
        $itemLessons = $this->buildItemLessons();

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
            'itemLessons' => $itemLessons,
        ]);
    }

    protected function buildItemLessons(): array
    {
        $lessonIndexes = [];
        $lessonsByItem = [];
        $modulesById = CourseModule::query()
            ->whereIn('id', $this->studyPlan->items->pluck('course_module_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $this->studyPlan->items
            ->sortBy([
                ['scheduled_date', 'asc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->each(function (StudyPlanItem $item) use (&$lessonIndexes, &$lessonsByItem, $modulesById) {
                $module = $modulesById->get($item->course_module_id);

                if (! in_array($item->type, ['basic', 'specific', 'complementary'], true) || ! $module) {
                    return;
                }

                $moduleId = $module->id;
                $lessons = $module->planning_lessons;
                $index = $lessonIndexes[$moduleId] ?? 0;
                $minutes = 0;
                $itemLessons = [];

                while ($index < count($lessons)) {
                    $lessonMinutes = (int) ($lessons[$index]['minutes'] ?? 0);

                    if ($lessonMinutes <= 0) {
                        $index++;
                        continue;
                    }

                    if (($minutes + $lessonMinutes) > $item->estimated_minutes) {
                        break;
                    }

                    $itemLessons[] = [
                        'name' => (string) ($lessons[$index]['name'] ?? $module->name),
                        'minutes' => $lessonMinutes,
                        'minutes_label' => $this->formatLessonMinutes($lessonMinutes),
                    ];
                    $minutes += $lessonMinutes;
                    $index++;
                }

                $lessonIndexes[$moduleId] = $index;

                if ($itemLessons !== []) {
                    $lessonsByItem[$item->id] = $itemLessons;
                }
            });

        return $lessonsByItem;
    }

    protected function orderedPlanItems(): \Illuminate\Support\Collection
    {
        return $this->studyPlan->items
            ->sortBy(function (StudyPlanItem $item): string {
                $saturdayTypePriority = match ($item->type) {
                    'basic', 'specific', 'complementary' => 0,
                    'questions' => 1,
                    'review' => 2,
                    default => 3,
                };

                $typePriority = $item->day_of_week === 'saturday' ? $saturdayTypePriority : 0;

                return implode('|', [
                    $item->scheduled_date?->format('Y-m-d') ?? '',
                    str_pad((string) $typePriority, 2, '0', STR_PAD_LEFT),
                    str_pad((string) $item->sort_order, 8, '0', STR_PAD_LEFT),
                    str_pad((string) $item->id, 8, '0', STR_PAD_LEFT),
                ]);
            })
            ->values();
    }

    protected function formatLessonMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes === 0) {
            return $hours . 'h';
        }

        return $hours . 'h ' . $remainingMinutes . 'min';
    }
}
