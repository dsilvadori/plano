<?php

namespace App\Livewire;

use App\Models\CourseModule;
use App\Models\QuestionBank;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Support\LessonTitleNormalizer;
use App\Support\StudyTime;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class StudyPlanViewer extends Component
{
    use AuthorizesRequests;

    public StudyPlan $studyPlan;

    public int $selectedWeek = 1;

    public ?string $editingDate = null;

    public array $manualDayRows = [];

    public ?array $manualDayConfirmation = null;

    protected $listeners = ['study-plan-item-toggled' => '$refresh'];

    public function mount(StudyPlan $studyPlan): void
    {
        $this->authorize('view', $studyPlan);
        $this->studyPlan = $studyPlan->load(['items.courseModule', 'items.lessons', 'course', 'studyTrack']);
        $this->selectedWeek = (int) $this->studyPlan->items->min('week_number') ?: 1;
    }

    public function selectWeek(int $week): void
    {
        if ($week === $this->selectedWeek) {
            return;
        }

        $this->selectedWeek = $week;
    }

    public function editDay(string $date): void
    {
        $this->editingDate = Carbon::parse($date)->toDateString();
        $this->manualDayConfirmation = null;
        $this->resetErrorBag();

        $lessonSelections = $this->buildItemLessonSelections();
        $rows = $this->orderedPlanItems()
            ->filter(fn (StudyPlanItem $item): bool => $item->scheduled_date?->toDateString() === $this->editingDate)
            ->values()
            ->map(fn (StudyPlanItem $item, int $index): ?array => filled($item->completed_at) ? null : [
                'item_id' => (string) $item->id,
                'block_number' => $this->blockNumberFromItem($item, $index + 1),
                'module_id' => $item->course_module_id ? (string) $item->course_module_id : '',
                'lesson_index' => (string) ($lessonSelections[$item->id]['lesson_index'] ?? ''),
                'minutes' => StudyTime::formatMinutes((int) $item->estimated_minutes),
                'sort_order' => (int) $item->sort_order,
                'original_module_id' => $item->course_module_id ? (string) $item->course_module_id : '',
                'original_lesson_index' => (string) ($lessonSelections[$item->id]['lesson_index'] ?? ''),
                'original_minutes' => StudyTime::formatMinutes((int) $item->estimated_minutes),
            ])
            ->filter()
            ->values()
            ->all();

        $this->manualDayRows = $rows !== [] ? $rows : [$this->blankManualDayRow()];
    }

    public function updatedManualDayRows(mixed $value, string $key): void
    {
        [$rowIndex, $field] = array_pad(explode('.', $key, 2), 2, null);
        $rowIndex = is_numeric($rowIndex) ? (int) $rowIndex : null;

        if ($rowIndex === null || ! isset($this->manualDayRows[$rowIndex])) {
            return;
        }

        if ($field === 'module_id') {
            $module = $this->editableModules()->firstWhere('id', (int) $value);
            $moduleLessons = $module ? $this->planningLessonsForModule($module) : [];
            $firstLesson = $moduleLessons[0] ?? null;

            $this->manualDayRows[$rowIndex]['lesson_index'] = $firstLesson ? '0' : '';
            $this->manualDayRows[$rowIndex]['minutes'] = $firstLesson
                ? StudyTime::formatMinutes((int) ($firstLesson['minutes'] ?? 0))
                : '0:30';
        }

        if ($field === 'lesson_index') {
            $module = $this->editableModules()->firstWhere('id', (int) ($this->manualDayRows[$rowIndex]['module_id'] ?? 0));
            $moduleLessons = $module ? $this->planningLessonsForModule($module) : [];
            $lesson = ($value !== '' && $module) ? ($moduleLessons[(int) $value] ?? null) : null;

            if ($lesson) {
                $this->manualDayRows[$rowIndex]['minutes'] = StudyTime::formatMinutes((int) ($lesson['minutes'] ?? 0));
            }
        }
    }

    public function cancelDayEdit(): void
    {
        $this->editingDate = null;
        $this->manualDayRows = [];
        $this->manualDayConfirmation = null;
        $this->resetErrorBag();
    }

    public function addManualDayRow(): void
    {
        $this->manualDayRows[] = $this->blankManualDayRow();
    }

    public function removeManualDayRow(int $index): void
    {
        unset($this->manualDayRows[$index]);
        $this->manualDayRows = array_values($this->manualDayRows);

        if ($this->manualDayRows === []) {
            $this->manualDayRows[] = $this->blankManualDayRow();
        }
    }

    public function saveManualDay(): void
    {
        $this->persistManualDay(false);
    }

    public function confirmManualDay(): void
    {
        $this->persistManualDay(true);
    }

    public function cancelManualDayConfirmation(): void
    {
        $this->manualDayConfirmation = null;
    }

    protected function persistManualDay(bool $confirmed): void
    {
        $this->authorize('update', $this->studyPlan);

        if (! $this->editingDate) {
            return;
        }

        $date = Carbon::parse($this->editingDate)->startOfDay();
        $modules = $this->editableModules()->keyBy('id');
        $this->studyPlan->load(['items.courseModule', 'items.lessons', 'course']);
        $pendingItemsById = $this->studyPlan->items()
            ->whereDate('scheduled_date', $date->toDateString())
            ->whereNull('completed_at')
            ->get()
            ->keyBy('id');
        $rows = collect($this->manualDayRows)
            ->map(function (array $row) use ($modules, $pendingItemsById): ?array {
                $itemId = (int) ($row['item_id'] ?? 0);
                $hasExistingItem = $itemId > 0 && $pendingItemsById->has($itemId);

                if ($hasExistingItem && $this->manualDayRowIsUnchanged($row)) {
                    return [
                        'item_id' => $itemId,
                        'preserve' => true,
                    ];
                }

                $module = $modules->get((int) ($row['module_id'] ?? 0));

                if (! $module) {
                    return $hasExistingItem
                        ? [
                            'item_id' => $itemId,
                            'preserve' => true,
                        ]
                        : null;
                }

                $lessons = $this->planningLessonsForModule($module);
                $lessonIndex = ($row['lesson_index'] ?? '') !== '' ? (int) $row['lesson_index'] : null;
                $lesson = $lessonIndex !== null ? ($lessons[$lessonIndex] ?? null) : null;
                $minutes = StudyTime::parseToMinutes($row['minutes'] ?? null);

                if ($lesson && $minutes <= 0) {
                    $minutes = (int) ($lesson['minutes'] ?? 0);
                }

                if ($minutes <= 0) {
                    return null;
                }

                return [
                    'item_id' => $itemId,
                    'preserve' => false,
                    'module_id' => (int) $module->id,
                    'lesson_index' => $lessonIndex,
                    'original_module_id' => filled($row['original_module_id'] ?? null) ? (int) $row['original_module_id'] : null,
                    'original_lesson_index' => filled($row['original_lesson_index'] ?? null) ? (int) $row['original_lesson_index'] : null,
                    'original_minutes' => StudyTime::parseToMinutes($row['original_minutes'] ?? null),
                    'module' => $module,
                    'lesson' => $lesson,
                    'minutes' => $minutes,
                    'block_number' => (int) ($row['block_number'] ?? 0),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ];
            })
            ->filter()
            ->values();

        if ($rows->isEmpty()) {
            $this->addError('manualDayRows', 'Escolha pelo menos uma aula ou módulo para este dia.');

            return;
        }

        $swaps = $this->buildManualDaySwaps($rows, $modules);
        $changesCount = $rows->filter(fn (array $row): bool => ! ($row['preserve'] ?? false))->count();

        if (! $confirmed && $changesCount > 0) {
            $this->manualDayConfirmation = [
                'swaps' => $swaps['summaries'],
                'changes_count' => $changesCount,
            ];

            return;
        }

        $this->manualDayConfirmation = null;
        $rowItemIds = $rows
            ->pluck('item_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
        $swapItemIds = collect($swaps['updates'])
            ->map(fn (array $swap) => (int) $swap['item']->id)
            ->filter()
            ->values()
            ->all();
        $preservedItemIds = array_values(array_unique([...$rowItemIds, ...$swapItemIds]));
        $sortOrder = ((int) $this->studyPlan->items()
            ->whereDate('scheduled_date', $date->toDateString())
            ->max('sort_order')) + 1;
        $nextBlockNumber = ((int) $this->studyPlan->items()
            ->whereDate('scheduled_date', $date->toDateString())
            ->count()) + 1;

        $this->studyPlan->items()
            ->whereDate('scheduled_date', $date->toDateString())
            ->whereNull('completed_at')
            ->when($preservedItemIds !== [], fn ($query) => $query->whereNotIn('id', $preservedItemIds))
            ->delete();

        foreach ($rows as $row) {
            if ($row['preserve'] ?? false) {
                continue;
            }

            $module = $row['module'];
            $lesson = $row['lesson'];
            $type = $this->normalizeModuleType((string) $module->type);
            $lessonName = $lesson ? (string) ($lesson['name'] ?? '') : '';
            $blockNumber = max(1, (int) ($row['block_number'] ?? $nextBlockNumber++));

            $attributes = [
                'course_module_id' => $module->id,
                'scheduled_date' => $date->toDateString(),
                'week_number' => $this->weekNumberForDate($date),
                'day_of_week' => strtolower($date->englishDayOfWeek),
                'title' => $this->manualItemTitle($type, $module->name, $blockNumber),
                'description' => $lessonName !== ''
                    ? 'Ajuste manual do aluno. Aulas do bloco: '.$lessonName.'.'
                    : 'Ajuste manual do aluno para estudar '.$module->name.'.',
                'type' => $type,
                'estimated_minutes' => (int) $row['minutes'],
                'sort_order' => (int) ($row['sort_order'] ?? 0) ?: $sortOrder++,
            ];
            $existingItem = ((int) ($row['item_id'] ?? 0)) > 0
                ? $pendingItemsById->get((int) $row['item_id'])
                : null;

            if ($existingItem) {
                $existingItem->forceFill($attributes)->save();
            } else {
                $this->studyPlan->items()->create($attributes);
            }
        }

        foreach ($swaps['updates'] as $swap) {
            $swap['item']->forceFill($swap['attributes'])->save();
        }

        $this->studyPlan = $this->studyPlan->fresh(['items.courseModule', 'course', 'studyTrack']);
        $this->editingDate = null;
        $this->manualDayRows = [];
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
                    'minutes_label' => StudyTime::formatMinutes($completedMinutes).' / '.StudyTime::formatMinutes($totalMinutes),
                ];
            })
            ->filter(fn (array $row) => $row['tasks'] > 0)
            ->values();

        $theoryMinutes = max(0, $weeklySummary['total_minutes'] - $weeklySummary['review_minutes'] - $weeklySummary['questions_minutes']);
        $weeklyFocusMessage = match (true) {
            $weeklySummary['total_minutes'] === 0 => 'Esta semana ainda não tem blocos planejados.',
            $weeklySummary['review_minutes'] > 0 && $weeklySummary['questions_minutes'] > 0 => 'Nesta semana você vai avançar em teoria, revisar o que estudou e testar retenção com questões.',
            $weeklySummary['review_minutes'] > 0 => 'Nesta semana o plano reserva um espaço especial para revisão e consolidação.',
            $weeklySummary['questions_minutes'] > 0 => 'Nesta semana o plano já separa tempo de prática para você medir evolução com questões.',
            default => 'Nesta semana o foco está concentrado em construir base e avançar no conteúdo principal.',
        };

        $weeklyBreakdownMessage = $weeklySummary['total_minutes'] > 0
            ? 'Você vai dedicar '.StudyTime::formatMinutes($theoryMinutes).' para teoria, '
                .StudyTime::formatMinutes($weeklySummary['review_minutes']).' para revisão e '
                .StudyTime::formatMinutes($weeklySummary['questions_minutes']).' para questões.'
            : 'Assim que o plano tiver blocos nesta semana, mostramos a distribuição aqui.';

        $selectedWeekRange = null;
        $itemLessons = $this->buildItemLessons();
        $itemQuestionLinks = $this->buildItemQuestionLinks($selectedWeekItems->where('type', 'questions')->values());

        if ($selectedWeekItems->isNotEmpty()) {
            $firstItem = $selectedWeekItems->first();
            $weekStart = $firstItem->scheduled_date->copy()->startOfWeek(CarbonInterface::MONDAY);
            $weekEnd = $firstItem->scheduled_date->copy()->endOfWeek(CarbonInterface::SUNDAY);
            $selectedWeekRange = $weekStart->format('d/m').' até '.$weekEnd->format('d/m');
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
            'itemQuestionLinks' => $itemQuestionLinks,
            'editableModules' => $this->editableModules(),
        ]);
    }

    protected function buildItemLessons(): array
    {
        $lessonsByItem = collect($this->buildItemLessonSelections())
            ->map(fn (array $selection): array => $selection['lessons'])
            ->filter()
            ->all();

        $this->studyPlan->items
            ->where('week_number', $this->selectedWeek)
            ->filter(fn (StudyPlanItem $item): bool => $item->lessons->isNotEmpty())
            ->each(function (StudyPlanItem $item) use (&$lessonsByItem): void {
                $linkedLessons = $item->orderedLessonsForDisplay()
                    ->map(fn ($lesson): array => [
                        'name' => $lesson->title,
                        'minutes' => $lesson->duration_minutes,
                        'minutes_label' => $this->formatLessonMinutes($lesson->duration_minutes),
                        'url' => route('study-plans.items.lessons.show', [$this->studyPlan, $item, $lesson]),
                        'is_online' => true,
                    ])
                    ->values()
                    ->all();

                if (isset($lessonsByItem[$item->id])) {
                    $linkedLessonsByName = collect($linkedLessons)
                        ->keyBy(fn (array $lesson): string => $this->normalizeLessonName((string) ($lesson['name'] ?? '')));
                    $matchedLinkedLessons = 0;

                    $lessonsByItem[$item->id] = collect($lessonsByItem[$item->id])
                        ->map(function (array $lesson) use ($linkedLessonsByName, &$matchedLinkedLessons): array {
                            $linkedLesson = $linkedLessonsByName->get($this->normalizeLessonName((string) ($lesson['name'] ?? '')));

                            if (! $linkedLesson) {
                                return $lesson;
                            }

                            $matchedLinkedLessons++;

                            return array_merge($lesson, [
                                'url' => $linkedLesson['url'] ?? null,
                                'is_online' => true,
                            ]);
                        })
                        ->values()
                        ->all();

                    if ($matchedLinkedLessons === 0) {
                        $lessonsByItem[$item->id] = $linkedLessons;
                    }

                    return;
                }

                $lessonsByItem[$item->id] = $linkedLessons;
            });

        return collect($lessonsByItem)
            ->map(fn (array $lessons): array => collect($lessons)
                ->map(fn (array $lesson): array => $this->withResolvedLessonUrl($lesson))
                ->values()
                ->all())
            ->all();
    }

    protected function buildItemQuestionLinks(Collection $questionItems): array
    {
        $links = [];
        $referencesByItem = [];
        $allLessonIds = collect();

        $questionItems->each(function (StudyPlanItem $item) use (&$referencesByItem, &$allLessonIds): void {
            $lessonReferences = $this->questionLessonReferencesForDate($item);

            if ($lessonReferences->isEmpty()) {
                return;
            }

            $referencesByItem[$item->id] = $lessonReferences;
            $allLessonIds = $allLessonIds->merge($lessonReferences->pluck('id')->filter());
        });

        if ($referencesByItem === []) {
            return [];
        }

        $lessonIds = $allLessonIds->unique()->values();

        if ($lessonIds->isEmpty()) {
            return [];
        }

        $candidateBanks = QuestionBank::query()
            ->where('status', 'published')
            ->with('lessons')
            ->whereHas('lessons', fn ($query) => $query->whereIn('lessons.id', $lessonIds))
            ->whereHas('questions', fn ($query) => $query->where('status', 'published'))
            ->withCount(['questions as related_questions_count' => fn ($query) => $query->where('status', 'published')])
            ->orderByDesc('related_questions_count')
            ->orderBy('title')
            ->get();

        foreach ($referencesByItem as $itemId => $lessonReferences) {
            $itemLinks = [];

            foreach ($lessonReferences as $lessonReference) {
                $banks = $candidateBanks
                    ->filter(fn (QuestionBank $bank): bool => ($lessonReference['id'] ?? null)
                        && $bank->lessons->contains(fn ($lesson): bool => (int) $lesson->id === (int) $lessonReference['id']));

                foreach ($banks as $linkedBank) {
                    $matchedLesson = $linkedBank->lessons->first(fn ($lesson): bool => (int) $lesson->id === (int) $lessonReference['id']);

                    $itemLinks[] = [
                        'label' => 'Resolver questões: '.$linkedBank->title,
                        'url' => route('questions.show', [$linkedBank, 'plan_id' => $this->studyPlan->id, 'lesson_id' => $matchedLesson?->id]),
                    ];
                }
            }

            $itemLinks = collect($itemLinks)
                ->unique('url')
                ->values()
                ->all();

            if ($itemLinks === []) {
                continue;
            }

            $links[$itemId] = $itemLinks;
        }

        return $links;
    }

    protected function questionLessonReferencesForDate(StudyPlanItem $questionItem): Collection
    {
        return $this->studyPlan->items
            ->where('scheduled_date', $questionItem->scheduled_date)
            ->whereIn('type', ['basic', 'specific', 'complementary'])
            ->flatMap(function (StudyPlanItem $item): array {
                $linkedLessons = $item->lessons
                    ->map(fn ($lesson): array => [
                        'id' => $lesson->id,
                        'key' => LessonTitleNormalizer::matchKey($lesson->title),
                    ])
                    ->all();

                if ($linkedLessons !== []) {
                    return $linkedLessons;
                }

                return collect($this->plannedLessonNamesFromDescription((string) $item->description))
                    ->map(fn (string $name): array => [
                        'id' => null,
                        'key' => LessonTitleNormalizer::matchKey($name),
                    ])
                    ->all();
            })
            ->filter(fn (array $reference): bool => filled($reference['id'] ?? null) || filled($reference['key'] ?? null))
            ->unique(fn (array $reference): string => ($reference['id'] ?? 'name').':'.($reference['key'] ?? ''))
            ->values();
    }

    protected function plannedLessonNamesFromDescription(string $description): array
    {
        if (! preg_match('/Aulas do bloco:\s*(.+)$/u', $description, $matches)) {
            return [];
        }

        return collect(preg_split('/,\s*|\s+e\s+(?=\d{1,3}\s+-)/u', $matches[1]) ?: [])
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->values()
            ->all();
    }

    protected function buildItemLessonSelections(): array
    {
        $lessonStates = [];
        $lessonsByItem = [];
        $modulesById = CourseModule::query()
            ->whereIn('id', $this->studyPlan->items->pluck('course_module_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $modulesById->each(fn (CourseModule $module) => $this->loadModulePlanningRelations($module));

        $this->studyPlan->items
            ->sortBy([
                ['scheduled_date', 'asc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->each(function (StudyPlanItem $item) use (&$lessonStates, &$lessonsByItem, $modulesById) {
                $module = $modulesById->get($item->course_module_id);

                if (! in_array($item->type, ['basic', 'specific', 'complementary'], true) || ! $module) {
                    return;
                }

                $moduleId = $module->id;
                $lessonStates[$moduleId] ??= [
                    'index' => 0,
                    'lessons' => $this->planningLessonsForModule($module),
                ];
                $firstLessonIndex = (int) ($lessonStates[$moduleId]['index'] ?? 0);
                $selection = $this->buildLessonSelectionForItem($module, (int) $item->estimated_minutes, $lessonStates[$moduleId]);
                $lessonStates[$moduleId] = $selection['state'];
                $itemLessons = $selection['lessons'];

                if ($itemLessons === []) {
                    $itemLessons = $this->lessonSelectionsFromDescription($module, $item);
                }

                if ($itemLessons !== []) {
                    $lessonsByItem[$item->id] = [
                        'lesson_index' => $firstLessonIndex,
                        'lessons' => $itemLessons,
                    ];
                }
            });

        return $lessonsByItem;
    }

    protected function buildLessonSelectionForItem(CourseModule $module, int $availableMinutes, array $state): array
    {
        $lessons = $state['lessons'] ?? [];
        $index = (int) ($state['index'] ?? 0);
        $minutes = 0;
        $itemLessons = [];
        $currentTrackName = null;

        while ($index < count($lessons)) {
            $lesson = $lessons[$index];
            $lessonMinutes = (int) ($lesson['minutes'] ?? 0);
            $lessonTrackName = trim((string) ($lesson['track_name'] ?? ''));

            if ($lessonMinutes <= 0) {
                $index++;

                continue;
            }

            if ($minutes > 0 && $currentTrackName !== null && $lessonTrackName !== '' && $lessonTrackName !== $currentTrackName) {
                break;
            }

            if ($currentTrackName === null && $lessonTrackName !== '') {
                $currentTrackName = $lessonTrackName;
            }

            $remainingBlockMinutes = max(0, $availableMinutes - $minutes);

            if ($remainingBlockMinutes <= 0) {
                break;
            }

            $displayMinutes = min($lessonMinutes, $remainingBlockMinutes);
            $lessonName = (string) ($lesson['name'] ?? $module->name);

            $itemLessons[] = [
                'name' => $lessonName,
                'minutes' => $displayMinutes,
                'minutes_label' => $this->formatLessonMinutes($displayMinutes),
                'lesson_id' => $lesson['lesson_id'] ?? null,
            ];

            $minutes += $displayMinutes;

            if ($displayMinutes < $lessonMinutes) {
                $lessons[$index]['minutes'] = $lessonMinutes - $displayMinutes;
                $lessons[$index]['name'] = 'Continuação: '.preg_replace('/^Continuação:\s*/u', '', $lessonName);
                $state['lessons'] = $lessons;

                break;
            }

            $index++;
        }

        $state['index'] = $index;

        return [
            'lessons' => $itemLessons,
            'state' => $state,
        ];
    }

    protected function lessonSelectionsFromDescription(CourseModule $module, StudyPlanItem $item): array
    {
        $lessonNames = $this->plannedLessonNamesFromDescription((string) $item->description);

        if ($lessonNames === []) {
            return [];
        }

        $availableLessons = collect($this->planningLessonsForModule($module));
        $fallbackMinutes = count($lessonNames) > 0
            ? max(1, (int) floor(((int) $item->estimated_minutes) / count($lessonNames)))
            : max(1, (int) $item->estimated_minutes);

        return collect($lessonNames)
            ->map(function (string $lessonName) use ($availableLessons, $fallbackMinutes): array {
                $matchedLesson = $availableLessons->first(function (array $lesson) use ($lessonName): bool {
                    return $this->normalizeLessonName((string) ($lesson['name'] ?? '')) === $this->normalizeLessonName($lessonName);
                });
                $minutes = max(1, (int) ($matchedLesson['minutes'] ?? $fallbackMinutes));

                return [
                    'name' => $lessonName,
                    'minutes' => $minutes,
                    'minutes_label' => $this->formatLessonMinutes($minutes),
                    'lesson_id' => $matchedLesson['lesson_id'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    protected function withResolvedLessonUrl(array $lesson): array
    {
        if (! empty($lesson['url'])) {
            return $lesson;
        }

        $lessonId = (int) ($lesson['lesson_id'] ?? 0);

        if ($lessonId <= 0 || ! $this->studyPlan->course) {
            return $lesson;
        }

        return array_merge($lesson, [
            'url' => route('courses.lessons.show', [$this->studyPlan->course->slug, $lessonId]),
            'is_online' => true,
        ]);
    }

    protected function orderedPlanItems(): Collection
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

    protected function editableModules(): Collection
    {
        if ($this->studyPlan->studyTrack) {
            return $this->studyPlan->studyTrack
                ->modules()
                ->where('course_modules.is_active', true)
                ->get()
                ->each(fn (CourseModule $module) => $this->loadModulePlanningRelations($module))
                ->reject(fn (CourseModule $module): bool => $module->shouldBeExcludedFromStudyPlan())
                ->values();
        }

        return $this->studyPlan->course
            ->modules()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->each(fn (CourseModule $module) => $this->loadModulePlanningRelations($module))
            ->reject(fn (CourseModule $module): bool => $module->shouldBeExcludedFromStudyPlan())
            ->values();
    }

    protected function blankManualDayRow(): array
    {
        return [
            'item_id' => '',
            'block_number' => $this->nextManualBlockNumber(),
            'module_id' => '',
            'lesson_index' => '',
            'minutes' => '0:30',
            'sort_order' => 0,
            'original_module_id' => null,
            'original_lesson_index' => null,
            'original_minutes' => null,
        ];
    }

    protected function manualDayRowIsUnchanged(array $row): bool
    {
        return array_key_exists('original_module_id', $row)
            && (string) ($row['module_id'] ?? '') === (string) ($row['original_module_id'] ?? '')
            && (string) ($row['lesson_index'] ?? '') === (string) ($row['original_lesson_index'] ?? '')
            && StudyTime::formatMinutes(StudyTime::parseToMinutes($row['minutes'] ?? null))
                === StudyTime::formatMinutes(StudyTime::parseToMinutes($row['original_minutes'] ?? null));
    }

    protected function buildManualDaySwaps(Collection $rows, Collection $modules): array
    {
        $lessonSelections = $this->buildItemLessonSelections();
        $pendingItems = $this->studyPlan->items
            ->whereNull('completed_at')
            ->sortBy([
                ['scheduled_date', 'asc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
        $rowsByItemId = $rows
            ->filter(fn (array $row): bool => (int) ($row['item_id'] ?? 0) > 0)
            ->keyBy(fn (array $row): int => (int) $row['item_id']);
        $usedItemIds = [];
        $updates = [];
        $summaries = [];

        foreach ($rows as $row) {
            if (($row['preserve'] ?? false)
                || ! filled($row['item_id'] ?? null)
                || ! filled($row['original_module_id'] ?? null)
                || $row['original_lesson_index'] === null
                || $row['lesson_index'] === null
            ) {
                continue;
            }

            $originalModule = $modules->get((int) $row['original_module_id']);
            $targetModule = $modules->get((int) $row['module_id']);
            $originalLesson = $originalModule ? ($this->planningLessonsForModule($originalModule)[(int) $row['original_lesson_index']] ?? null) : null;
            $targetLesson = $targetModule ? ($this->planningLessonsForModule($targetModule)[(int) $row['lesson_index']] ?? null) : null;

            if (! $originalModule || ! $targetModule || ! $originalLesson || ! $targetLesson) {
                continue;
            }

            $targetItem = $pendingItems->first(function (StudyPlanItem $item) use ($row, $lessonSelections, $rowsByItemId, $usedItemIds): bool {
                if ((int) $item->id === (int) $row['item_id']
                    || in_array((int) $item->id, $usedItemIds, true)
                    || (int) $item->course_module_id !== (int) $row['module_id']
                    || (string) ($lessonSelections[$item->id]['lesson_index'] ?? '') !== (string) $row['lesson_index']
                ) {
                    return false;
                }

                $editingRow = $rowsByItemId->get((int) $item->id);

                return ! $editingRow || ($editingRow['preserve'] ?? false);
            });

            $summaries[] = [
                'from' => $this->lessonSwapLabel($originalModule, $originalLesson),
                'to' => $this->lessonSwapLabel($targetModule, $targetLesson),
                'target_date' => $targetItem?->scheduled_date?->format('d/m/Y'),
                'missing_target' => ! $targetItem,
            ];

            if (! $targetItem) {
                continue;
            }

            $usedItemIds[] = (int) $targetItem->id;
            $targetDate = $targetItem->scheduled_date?->copy()->startOfDay() ?? Carbon::parse($this->editingDate)->startOfDay();
            $blockNumber = $this->blockNumberFromItem($targetItem, (int) $targetItem->sort_order);
            $minutes = (int) ($originalLesson['minutes'] ?? 0) ?: (int) ($row['original_minutes'] ?? 0);

            $updates[] = [
                'item' => $targetItem,
                'attributes' => $this->manualItemAttributes(
                    $originalModule,
                    $originalLesson,
                    $minutes,
                    $targetDate,
                    $blockNumber,
                    (int) $targetItem->sort_order,
                ),
            ];
        }

        return [
            'updates' => $updates,
            'summaries' => $summaries,
        ];
    }

    protected function manualItemAttributes(CourseModule $module, ?array $lesson, int $minutes, Carbon $date, int $blockNumber, int $sortOrder): array
    {
        $type = $this->normalizeModuleType((string) $module->type);
        $lessonName = $lesson ? (string) ($lesson['name'] ?? '') : '';

        return [
            'course_module_id' => $module->id,
            'scheduled_date' => $date->toDateString(),
            'week_number' => $this->weekNumberForDate($date),
            'day_of_week' => strtolower($date->englishDayOfWeek),
            'title' => $this->manualItemTitle($type, $module->name, $blockNumber),
            'description' => $lessonName !== ''
                ? 'Ajuste manual do aluno. Aulas do bloco: '.$lessonName.'.'
                : 'Ajuste manual do aluno para estudar '.$module->name.'.',
            'type' => $type,
            'estimated_minutes' => $minutes,
            'sort_order' => $sortOrder,
        ];
    }

    protected function lessonSwapLabel(CourseModule $module, array $lesson): string
    {
        return $module->name.' - '.((string) ($lesson['name'] ?? 'Aula'));
    }

    protected function loadModulePlanningRelations(CourseModule $module): void
    {
        $module->loadMissing('onlineLessons');
        $module->setRelation('tracks', $module->tracks()
            ->where('status', 'published')
            ->where(function ($query): void {
                $query
                    ->whereDoesntHave('courses')
                    ->orWhereHas('courses', fn ($query) => $query->whereKey($this->studyPlan->course_id));
            })
            ->with(['lessons' => fn ($query) => $query->where('lessons.status', '!=', 'archived')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get());
    }

    protected function planningLessonsForModule(CourseModule $module): array
    {
        $trackLessons = $this->planningLessonsFromTracks($module);

        return $trackLessons !== [] ? $trackLessons : $module->planning_lessons;
    }

    protected function planningLessonsFromTracks(CourseModule $module): array
    {
        $tracks = $module->relationLoaded('tracks') ? $module->tracks : collect();

        return $tracks
            ->sortBy([
                ['sort_order', 'asc'],
                ['name', 'asc'],
            ])
            ->flatMap(function ($track): Collection {
                $lessons = $track->relationLoaded('lessons') ? $track->lessons : collect();

                return $lessons
                    ->map(function ($lesson, int $index) use ($track): array {
                        return [
                            'lesson_id' => $lesson->id,
                            'name' => trim((string) $lesson->title) ?: ($track->name.' - Aula '.($index + 1)),
                            'minutes' => max(1, (int) $lesson->duration_minutes),
                            'track_name' => (string) $track->name,
                        ];
                    })
                    ->filter(fn (array $lesson): bool => $lesson['minutes'] > 0)
                    ->values();
            })
            ->values()
            ->all();
    }

    protected function normalizeLessonName(string $name): string
    {
        return LessonTitleNormalizer::matchKey(preg_replace('/^Continuação:\s*/u', '', $name) ?: $name);
    }

    protected function nextManualBlockNumber(): int
    {
        if (! $this->editingDate) {
            return count($this->manualDayRows) + 1;
        }

        $currentRows = collect($this->manualDayRows)
            ->pluck('block_number')
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->all();
        $existingBlocks = $this->orderedPlanItems()
            ->filter(fn (StudyPlanItem $item): bool => $item->scheduled_date?->toDateString() === $this->editingDate)
            ->values()
            ->keys()
            ->map(fn (int $index) => $index + 1)
            ->all();

        return max([0, ...$currentRows, ...$existingBlocks]) + 1;
    }

    protected function blockNumberFromItem(StudyPlanItem $item, int $fallback): int
    {
        if (preg_match('/Bloco\s+(\d+)/i', (string) $item->title, $matches)) {
            return max(1, (int) $matches[1]);
        }

        return max(1, $fallback);
    }

    protected function weekNumberForDate(Carbon $date): int
    {
        $reference = $this->studyPlan->start_date?->copy()->startOfWeek(CarbonInterface::MONDAY)
            ?? $date->copy()->startOfWeek(CarbonInterface::MONDAY);

        return $reference->diffInWeeks($date->copy()->startOfWeek(CarbonInterface::MONDAY)) + 1;
    }

    protected function normalizeModuleType(string $type): string
    {
        return in_array($type, ['basic', 'specific', 'complementary'], true) ? $type : 'other';
    }

    protected function manualItemTitle(string $type, string $moduleName, int $blockNumber): string
    {
        return match ($type) {
            'basic' => 'Bloco '.$blockNumber.' · Matéria Básica: '.$moduleName,
            'specific' => 'Bloco '.$blockNumber.' · Conhecimentos Específicos: '.$moduleName,
            'complementary' => 'Bloco '.$blockNumber.' · Conhecimentos Complementares: '.$moduleName,
            default => 'Bloco '.$blockNumber.' · '.$moduleName,
        };
    }

    protected function formatLessonMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes === 0) {
            return $hours.'h';
        }

        return $hours.'h '.$remainingMinutes.'min';
    }
}
