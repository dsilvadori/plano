<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\StudyPlan;
use App\Models\StudyTrack;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudyPlanGenerator
{
    public function generate(
        User $user,
        Course $course,
        ?StudyTrack $studyTrack,
        ?string $examDate,
        string $startDate,
        array $availableDays,
        array $availableMinutesByDay,
        string $intensity,
    ): StudyPlan {
        return DB::transaction(function () use ($user, $course, $studyTrack, $examDate, $startDate, $availableDays, $availableMinutesByDay, $intensity) {
            $payload = $this->buildPlanPayload($course, $studyTrack, $examDate, $startDate, $availableDays, $availableMinutesByDay, $intensity);

            $user->studyPlans()
                ->where('status', 'active')
                ->where('course_id', $course->id)
                ->update(['status' => 'archived']);

            $plan = StudyPlan::create(array_merge($payload['attributes'], [
                'user_id' => $user->id,
            ]));

            $this->generateItems(
                $plan,
                $payload['modules'],
                $payload['start'],
                $payload['exam'],
                $availableDays,
                $availableMinutesByDay,
                $intensity,
            );

            return $plan->fresh(['items.courseModule', 'course', 'studyTrack', 'user']);
        });
    }

    public function regenerate(
        StudyPlan $studyPlan,
        Course $course,
        ?StudyTrack $studyTrack,
        ?string $examDate,
        string $startDate,
        array $availableDays,
        array $availableMinutesByDay,
        string $intensity,
    ): StudyPlan {
        return DB::transaction(function () use ($studyPlan, $course, $studyTrack, $examDate, $startDate, $availableDays, $availableMinutesByDay, $intensity) {
            $studyPlan->loadMissing(['course', 'studyTrack', 'user', 'items.courseModule']);

            if ($studyPlan->items->whereNotNull('completed_at')->isNotEmpty()) {
                return $this->regeneratePreservingProgress(
                    $studyPlan,
                    $course,
                    $studyTrack,
                    $examDate,
                    $startDate,
                    $availableDays,
                    $availableMinutesByDay,
                    $intensity,
                );
            }

            $payload = $this->buildPlanPayload($course, $studyTrack, $examDate, $startDate, $availableDays, $availableMinutesByDay, $intensity);

            $studyPlan->user
                ->studyPlans()
                ->where('status', 'active')
                ->where('course_id', $course->id)
                ->whereKeyNot($studyPlan->id)
                ->update(['status' => 'archived']);

            $studyPlan->items()->delete();
            $studyPlan->fill($payload['attributes']);
            $studyPlan->save();

            $this->generateItems(
                $studyPlan,
                $payload['modules'],
                $payload['start'],
                $payload['exam'],
                $availableDays,
                $availableMinutesByDay,
                $intensity,
            );

            return $studyPlan->fresh(['items.courseModule', 'course', 'studyTrack', 'user']);
        });
    }

    protected function regeneratePreservingProgress(
        StudyPlan $studyPlan,
        Course $course,
        ?StudyTrack $studyTrack,
        ?string $examDate,
        string $startDate,
        array $availableDays,
        array $availableMinutesByDay,
        string $intensity,
    ): StudyPlan {
        $payload = $this->buildPlanPayload($course, $studyTrack, $examDate, $startDate, $availableDays, $availableMinutesByDay, $intensity);
        $modules = $payload['modules'];
        $completedItems = $studyPlan->items->whereNotNull('completed_at')->values();
        $completedMinutesByModule = $completedItems
            ->filter(fn ($item) => filled($item->course_module_id))
            ->groupBy('course_module_id')
            ->map(fn (Collection $items) => (int) $items->sum('estimated_minutes'))
            ->all();

        $remainingByModule = $modules
            ->mapWithKeys(fn (CourseModule $module) => [
                $module->id => max(0, (int) $module->workload_minutes - (int) ($completedMinutesByModule[$module->id] ?? 0)),
            ])
            ->all();

        $studyPlan->user
            ->studyPlans()
            ->where('status', 'active')
            ->where('course_id', $course->id)
            ->whereKeyNot($studyPlan->id)
            ->update(['status' => 'archived']);

        $studyPlan->items()->whereNull('completed_at')->delete();
        $studyPlan->fill($payload['attributes']);
        $studyPlan->save();

        $completedModules = $completedItems
            ->map(fn ($item) => $item->courseModule?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (array_sum($remainingByModule) > 0) {
            $generationStart = Carbon::parse($startDate)->startOfDay()->max(now()->startOfDay());

            $this->generateItems(
                $studyPlan,
                $modules,
                $generationStart,
                $payload['exam'],
                $availableDays,
                $availableMinutesByDay,
                $intensity,
                $payload['start'],
                $remainingByModule,
                $completedModules,
                ((int) $studyPlan->items()->max('sort_order')) + 1,
            );
        }

        return $studyPlan->fresh(['items.courseModule', 'course', 'studyTrack', 'user']);
    }

    public function smartRebalance(StudyPlan $studyPlan): StudyPlan
    {
        return DB::transaction(function () use ($studyPlan) {
            $studyPlan->loadMissing(['course', 'studyTrack', 'user', 'items.courseModule']);

            $today = now()->startOfDay();
            $availableDays = $studyPlan->available_days ?? [];
            $availableMinutesByDay = $studyPlan->available_minutes_by_day ?? [];
            $modules = $this->resolveModules($studyPlan->course, $studyPlan->studyTrack);
            $completedItems = $studyPlan->items->whereNotNull('completed_at')->values();
            $completedMinutesByModule = $completedItems
                ->filter(fn ($item) => filled($item->course_module_id))
                ->groupBy('course_module_id')
                ->map(fn (Collection $items) => (int) $items->sum('estimated_minutes'))
                ->all();

            $remainingByModule = $modules
                ->mapWithKeys(fn (CourseModule $module) => [
                    $module->id => max(0, (int) $module->workload_minutes - (int) ($completedMinutesByModule[$module->id] ?? 0)),
                ])
                ->all();

            $remainingRequiredMinutes = array_sum($remainingByModule);
            $examDate = $studyPlan->exam_date && ($studyPlan->exam_date->isFuture() || $studyPlan->exam_date->isToday())
                ? $studyPlan->exam_date->toDateString()
                : null;
            $exam = $examDate
                ? Carbon::parse($examDate)->startOfDay()
                : $this->estimatePlanEndDate($today, $remainingRequiredMinutes, $availableMinutesByDay);
            $totalAvailableMinutes = $this->calculateAvailableMinutes($today, $exam, $availableDays, $availableMinutesByDay);

            [$viabilityStatus, $viabilityMessage] = $this->resolveViability($totalAvailableMinutes, $remainingRequiredMinutes, filled($examDate));

            $studyPlan->items()->whereNull('completed_at')->delete();

            $studyPlan->forceFill([
                'name' => filled($examDate)
                    ? 'Plano ' . Str::title($studyPlan->course->name) . ' até ' . $exam->format('d/m/Y')
                    : 'Plano ' . Str::title($studyPlan->course->name) . ' sem previsão de prova',
                'exam_date' => $exam,
                'exam_date_confirmed' => $studyPlan->exam_date_confirmed,
                'total_available_minutes' => $totalAvailableMinutes,
                'intensity' => $studyPlan->intensity,
                'status' => 'active',
                'viability_status' => $viabilityStatus,
                'viability_message' => $viabilityMessage,
                'generated_at' => now(),
            ])->save();

            $completedModules = $completedItems
                ->map(fn ($item) => $item->courseModule?->name)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($remainingRequiredMinutes > 0) {
                $this->generateItems(
                    $studyPlan,
                    $modules,
                    $today,
                    $exam,
                    $availableDays,
                    $availableMinutesByDay,
                    $studyPlan->intensity,
                    $studyPlan->start_date?->copy()->startOfDay() ?? $today,
                    $remainingByModule,
                    $completedModules,
                    ((int) $studyPlan->items()->max('sort_order')) + 1,
                );
            }

            $studyPlan->forceFill([
                'total_required_minutes' => (int) $modules->sum('workload_minutes'),
            ])->save();

            return $studyPlan->fresh(['items.courseModule', 'course', 'studyTrack', 'user']);
        });
    }

    protected function buildPlanPayload(
        Course $course,
        ?StudyTrack $studyTrack,
        ?string $examDate,
        string $startDate,
        array $availableDays,
        array $availableMinutesByDay,
        string $intensity,
    ): array {
        $start = Carbon::parse($startDate)->startOfDay();
        $modules = $this->resolveModules($course, $studyTrack);
        $totalRequiredMinutes = (int) $modules->sum('workload_minutes');
        $exam = $examDate
            ? Carbon::parse($examDate)->startOfDay()
            : $this->estimatePlanEndDate($start, $totalRequiredMinutes, $availableMinutesByDay);
        $totalAvailableMinutes = $this->calculateAvailableMinutes($start, $exam, $availableDays, $availableMinutesByDay);

        [$viabilityStatus, $viabilityMessage] = $this->resolveViability($totalAvailableMinutes, $totalRequiredMinutes, filled($examDate));

        return [
            'modules' => $modules,
            'start' => $start,
            'exam' => $exam,
            'attributes' => [
                'course_id' => $course->id,
                'study_track_id' => $studyTrack?->id,
                'name' => filled($examDate)
                    ? 'Plano ' . Str::title($course->name) . ' até ' . $exam->format('d/m/Y')
                    : 'Plano ' . Str::title($course->name) . ' sem previsão de prova',
                'exam_date' => $exam,
                'exam_date_confirmed' => filled($examDate),
                'start_date' => $start,
                'available_days' => array_values($availableDays),
                'available_minutes_by_day' => $availableMinutesByDay,
                'total_available_minutes' => $totalAvailableMinutes,
                'total_required_minutes' => $totalRequiredMinutes,
                'intensity' => $intensity,
                'status' => 'active',
                'viability_status' => $viabilityStatus,
                'viability_message' => $viabilityMessage,
                'generated_at' => now(),
            ],
        ];
    }

    protected function resolveModules(Course $course, ?StudyTrack $studyTrack): Collection
    {
        if ($studyTrack) {
            return $studyTrack->modules()
                ->where('course_modules.is_active', true)
                ->get()
                ->reject(fn (CourseModule $module) => $this->shouldSkipModule($module))
                ->values();
        }

        return $course->modules()
            ->where('is_active', true)
            ->get()
            ->reject(fn (CourseModule $module) => $this->shouldSkipModule($module))
            ->values();
    }

    protected function shouldSkipModule(CourseModule $module): bool
    {
        $normalizedName = Str::of($module->name)->lower()->ascii()->value();

        return Str::contains($normalizedName, [
            'apresentacao',
            'boas-vindas',
            'boas vindas',
            'bem-vindo',
            'bem vindo',
        ]);
    }

    protected function calculateAvailableMinutes(Carbon $start, Carbon $exam, array $availableDays, array $availableMinutesByDay): int
    {
        $total = 0;

        for ($date = $start->copy(); $date->lte($exam); $date->addDay()) {
            $dayKey = strtolower($date->englishDayOfWeek);

            if (in_array($dayKey, $availableDays, true)) {
                $total += (int) ($availableMinutesByDay[$dayKey] ?? 0);
            }
        }

        return $total;
    }

    protected function resolveViability(int $available, int $required, bool $hasExamDate): array
    {
        if (! $hasExamDate) {
            return ['good', 'Plano criado sem data de prova definida. Distribuímos o ciclo com foco em constância, revisão e questões até você informar a prova.'];
        }

        if ($required === 0 || $available >= $required) {
            return ['good', 'Plano viável. Seu tempo disponível cobre a carga necessária até a prova.'];
        }

        if ($available >= (int) round($required * 0.75)) {
            return ['warning', 'Plano apertado. Há espaço para avançar, mas será importante manter constância e priorizar o essencial.'];
        }

        return ['critical', 'Plano crítico. O tempo disponível não cobre a carga prevista, então o ciclo vai priorizar o que mais move sua preparação.'];
    }

    protected function estimatePlanEndDate(Carbon $start, int $totalRequiredMinutes, array $availableMinutesByDay): Carbon
    {
        $weeklyMinutes = max(60, array_sum($availableMinutesByDay));
        $weeksNeeded = (int) ceil(max(1, $totalRequiredMinutes) / $weeklyMinutes);

        return $start
            ->copy()
            ->startOfWeek(CarbonInterface::MONDAY)
            ->addWeeks(max(4, min($weeksNeeded + 1, 52)) - 1)
            ->endOfWeek(CarbonInterface::SUNDAY)
            ->startOfDay();
    }

    protected function generateItems(
        StudyPlan $plan,
        Collection $modules,
        Carbon $start,
        Carbon $exam,
        array $availableDays,
        array $availableMinutesByDay,
        string $intensity,
        ?Carbon $weekReferenceStart = null,
        ?array $remainingByModule = null,
        array $completedModules = [],
        int $initialSortOrder = 1,
    ): void {
        $orderedModules = $modules
            ->sortBy('sort_order')
            ->values();
        $theoryModules = $orderedModules
            ->filter(fn (CourseModule $module) => in_array($this->normalizeModuleType($module->type), ['basic', 'specific', 'complementary'], true))
            ->values();
        $remainingByModule = $remainingByModule ?? $modules->mapWithKeys(fn (CourseModule $module) => [$module->id => (int) $module->workload_minutes])->all();
        $typeQueues = $this->buildTheoryQueues($theoryModules);
        $typePointers = collect($typeQueues)->mapWithKeys(fn (Collection $queue, string $type) => [$type => 0])->all();
        $typeRotationIndex = 0;
        $sortOrder = $initialSortOrder;
        $weeklyTheoryScheduled = [];
        $weekReferenceStart = $weekReferenceStart?->copy()->startOfWeek(CarbonInterface::MONDAY) ?? $start->copy()->startOfWeek(CarbonInterface::MONDAY);

        for ($date = $start->copy(); $date->lte($exam); $date->addDay()) {
            $dayKey = strtolower($date->englishDayOfWeek);

            if (! in_array($dayKey, $availableDays, true)) {
                continue;
            }

            $remainingToday = (int) ($availableMinutesByDay[$dayKey] ?? 0);
            if ($remainingToday < 15) {
                continue;
            }

            $weekNumber = $weekReferenceStart
                ->diffInWeeks($date->copy()->startOfWeek(CarbonInterface::MONDAY)) + 1;
            $blockNumber = 1;
            $reserveMinutes = $this->resolveReserveMinutes(
                $dayKey,
                $remainingToday,
                (bool) ($weeklyTheoryScheduled[$weekNumber] ?? false),
            );
            $theoryBudget = max(0, $remainingToday - $reserveMinutes);

            while ($theoryBudget >= 15 && $this->hasRemainingTheoryModules($typeQueues, $typePointers, $remainingByModule)) {
                $allocated = $this->createInterleavedStudyItem(
                    $plan,
                    $date,
                    $weekNumber,
                    $dayKey,
                    $theoryBudget,
                    $blockNumber,
                    $sortOrder,
                    $typeQueues,
                    $typePointers,
                    $typeRotationIndex,
                    $remainingByModule,
                    $completedModules,
                );

                if ($allocated === 0) {
                    break;
                }

                $theoryBudget -= $allocated;
                $remainingToday -= $allocated;
                $weeklyTheoryScheduled[$weekNumber] = true;
                $blockNumber++;
            }

            foreach ($this->buildReserveBlueprint($dayKey, $remainingToday) as $reserveBlock) {
                $this->createReserveItem(
                    $plan,
                    $date,
                    $weekNumber,
                    $dayKey,
                    $reserveBlock['type'],
                    $reserveBlock['minutes'],
                    $blockNumber,
                    $sortOrder,
                    $completedModules,
                    $orderedModules,
                    $remainingByModule,
                );

                $blockNumber++;
            }
        }
    }

    protected function resolveReserveMinutes(string $dayKey, int $remainingToday, bool $weekHasTheory): int
    {
        if ($dayKey === 'saturday' && $weekHasTheory) {
            return $remainingToday;
        }

        return max(0, min(30, $remainingToday - 60));
    }

    protected function buildReserveBlueprint(string $dayKey, int $remainingToday): array
    {
        $slots = [];

        while ($remainingToday >= 15) {
            $slots[] = [
                'type' => count($slots) % 2 === 0 ? 'questions' : 'review',
                'minutes' => min(15, $remainingToday),
            ];
            $remainingToday -= 15;
        }

        return $slots;
    }

    protected function createInterleavedStudyItem(
        StudyPlan $plan,
        Carbon $date,
        int $weekNumber,
        string $dayKey,
        int $availableMinutes,
        int $blockNumber,
        int &$sortOrder,
        array $typeQueues,
        array &$typePointers,
        int &$typeRotationIndex,
        array &$remainingByModule,
        array &$completedModules,
    ): int {
        [$type, $module] = $this->resolveCurrentTheoryModule($typeQueues, $typePointers, $typeRotationIndex, $remainingByModule);

        if (! $module || ! $type) {
            return 0;
        }

        $moduleRemaining = max(15, (int) ($remainingByModule[$module->id] ?? 15));
        $estimatedMinutes = min(60, $availableMinutes, $moduleRemaining);

        if ($estimatedMinutes < 15) {
            return 0;
        }

        $type = $module->type;

        $plan->items()->create([
            'course_module_id' => $module->id,
            'scheduled_date' => $date->toDateString(),
            'week_number' => $weekNumber,
            'day_of_week' => $dayKey,
            'title' => $this->makeItemTitle($type, $module->name, $blockNumber),
            'description' => $this->makeItemDescription($type, $module->name, $dayKey, $estimatedMinutes),
            'type' => $type,
            'estimated_minutes' => $estimatedMinutes,
            'sort_order' => $sortOrder++,
        ]);

        $remainingByModule[$module->id] -= $estimatedMinutes;

        if ($remainingByModule[$module->id] <= 0) {
            $completedModules[] = $module->name;
            $typePointers[$type]++;
        }

        return $estimatedMinutes;
    }

    protected function createReserveItem(
        StudyPlan $plan,
        Carbon $date,
        int $weekNumber,
        string $dayKey,
        string $type,
        int $plannedMinutes,
        int $blockNumber,
        int &$sortOrder,
        array $completedModules,
        Collection $orderedModules,
        array $remainingByModule,
    ): void {
        [$module, $title, $description] = $this->pickReserveContext(
            $type,
            $orderedModules,
            $remainingByModule,
            $completedModules,
            $blockNumber,
            $dayKey,
            $plannedMinutes,
        );

        $plan->items()->create([
            'course_module_id' => $module?->id,
            'scheduled_date' => $date->toDateString(),
            'week_number' => $weekNumber,
            'day_of_week' => $dayKey,
            'title' => $title,
            'description' => $description,
            'type' => $type,
            'estimated_minutes' => $plannedMinutes,
            'sort_order' => $sortOrder++,
        ]);
    }

    protected function buildTheoryQueues(Collection $modules): array
    {
        return collect(['basic', 'specific', 'complementary'])
            ->mapWithKeys(fn (string $type) => [
                $type => $modules
                    ->filter(fn (CourseModule $module) => $this->normalizeModuleType($module->type) === $type)
                    ->sortBy('sort_order')
                    ->values(),
            ])
            ->all();
    }

    protected function hasRemainingTheoryModules(array $typeQueues, array $typePointers, array $remainingByModule): bool
    {
        foreach (['basic', 'specific', 'complementary'] as $type) {
            $queue = $typeQueues[$type] ?? collect();
            $pointer = $typePointers[$type] ?? 0;

            while ($pointer < $queue->count()) {
                $module = $queue[$pointer];

                if (($remainingByModule[$module->id] ?? 0) > 0) {
                    return true;
                }

                $pointer++;
            }
        }

        return false;
    }

    protected function hasRemainingModules(Collection $orderedModules, array $remainingByModule): bool
    {
        return $orderedModules
            ->contains(fn (CourseModule $module) => ($remainingByModule[$module->id] ?? 0) > 0);
    }

    protected function resolveCurrentTheoryModule(array $typeQueues, array &$typePointers, int &$typeRotationIndex, array $remainingByModule): array
    {
        $types = ['basic', 'specific', 'complementary'];
        $count = count($types);

        for ($offset = 0; $offset < $count; $offset++) {
            $typeIndex = ($typeRotationIndex + $offset) % $count;
            $type = $types[$typeIndex];
            $queue = $typeQueues[$type] ?? collect();
            $pointer = $typePointers[$type] ?? 0;

            while ($pointer < $queue->count()) {
                $module = $queue[$pointer];

                if (($remainingByModule[$module->id] ?? 0) > 0) {
                    $typePointers[$type] = $pointer;
                    $typeRotationIndex = ($typeIndex + 1) % $count;

                    return [$type, $module];
                }

                $pointer++;
            }

            $typePointers[$type] = $pointer;
        }

        return [null, null];
    }

    protected function pickReserveContext(
        string $type,
        Collection $orderedModules,
        array $remainingByModule,
        array $completedModules,
        int $blockNumber,
        string $dayKey,
        int $plannedMinutes,
    ): array {
        $currentModule = $orderedModules->first(fn (CourseModule $module) => ($remainingByModule[$module->id] ?? 0) > 0);
        $referenceModuleName = $currentModule?->name ?? collect($completedModules)->last();

        if ($type === 'review') {
            $topic = collect($completedModules)->take(-2)->implode(' e ');

            return [
                $currentModule,
                'Bloco ' . $blockNumber . ' · Revisão',
                $topic !== ''
                    ? 'Reserva de até ' . $plannedMinutes . ' minutos para retomar resumos, mapas mentais e pontos críticos de ' . $topic . '.'
                    : 'Reserva de até ' . $plannedMinutes . ' minutos para retomar resumos, mapas mentais e os principais pontos estudados para consolidar a memória.',
            ];
        }

        if ($type === 'questions') {
            return [
                $currentModule,
                'Bloco ' . $blockNumber . ' · Questões',
                $referenceModuleName
                    ? 'Reserva de até ' . $plannedMinutes . ' minutos para resolver questões e consolidar o conteúdo de ' . $referenceModuleName . '.'
                    : 'Reserva de até ' . $plannedMinutes . ' minutos para resolver questões e medir retenção.',
            ];
        }

        return [
            null,
            'Bloco ' . $blockNumber . ' · Bloco complementar',
            'Reserva de até ' . $plannedMinutes . ' minutos para reforçar o conteúdo mais relevante da sua trilha.',
        ];
    }

    protected function makeItemTitle(string $type, string $moduleName, int $blockNumber): string
    {
        return match ($type) {
            'basic' => 'Bloco ' . $blockNumber . ' · Matéria Básica: ' . $moduleName,
            'specific' => 'Bloco ' . $blockNumber . ' · Conhecimentos Específicos: ' . $moduleName,
            'complementary' => 'Bloco ' . $blockNumber . ' · Conhecimentos Complementares: ' . $moduleName,
            'review' => 'Bloco ' . $blockNumber . ' · Revisão: ' . $moduleName,
            'questions' => 'Bloco ' . $blockNumber . ' · Resolução de Questões: ' . $moduleName,
            default => 'Bloco ' . $blockNumber . ' · ' . $moduleName,
        };
    }

    protected function makeItemDescription(string $type, string $moduleName, string $dayKey, int $estimatedMinutes): string
    {
        $durationLabel = $estimatedMinutes . ' minutos';

        return match ($type) {
            'basic' => 'Bloco de até ' . $durationLabel . ' para fortalecer sua matéria básica em ' . $moduleName . ' antes de avançar para o próximo assunto.',
            'specific' => 'Bloco de até ' . $durationLabel . ' para avançar em conhecimentos específicos com foco em ' . $moduleName . ' sem pular para o próximo assunto antes da conclusão.',
            'complementary' => 'Bloco de até ' . $durationLabel . ' para avançar em conhecimentos complementares com foco em ' . $moduleName . ' sem quebrar a ordem da trilha.',
            'review' => $dayKey === 'saturday'
                ? 'Sábado de revisão com reserva de até ' . $durationLabel . ' para retomar pontos-chave de ' . $moduleName . ' com foco em fixação.'
                : 'Bloco de revisão com reserva de até ' . $durationLabel . ' para reforçar a retenção em ' . $moduleName . '.',
            'questions' => 'Bloco de resolução de questões com reserva de até ' . $durationLabel . ' para aplicar na prática o conteúdo de ' . $moduleName . '.',
            default => 'Bloco de estudo planejado para manter consistência até a prova.',
        };
    }

    protected function normalizeModuleType(?string $type): string
    {
        return match ($type) {
            'other' => 'complementary',
            default => $type ?: 'complementary',
        };
    }
}
