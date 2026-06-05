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
        $queues = $modules
            ->sortBy('sort_order')
            ->groupBy('type')
            ->map(fn (Collection $group) => $group->values());

        $remainingByModule = $remainingByModule ?? $modules->mapWithKeys(fn (CourseModule $module) => [$module->id => (int) $module->workload_minutes])->all();
        $queuePointers = [];
        $sortOrder = $initialSortOrder;
        $weekReferenceStart = $weekReferenceStart?->copy()->startOfWeek(CarbonInterface::MONDAY) ?? $start->copy()->startOfWeek(CarbonInterface::MONDAY);

        for ($date = $start->copy(); $date->lte($exam); $date->addDay()) {
            $dayKey = strtolower($date->englishDayOfWeek);

            if (! in_array($dayKey, $availableDays, true)) {
                continue;
            }

            $remainingToday = (int) ($availableMinutesByDay[$dayKey] ?? 0);
            $weekNumber = $weekReferenceStart
                ->diffInWeeks($date->copy()->startOfWeek(CarbonInterface::MONDAY)) + 1;
            $blockNumber = 1;

            foreach ($this->dailyBlueprint($dayKey, $remainingToday, $intensity) as $slot) {
                if ($remainingToday < 15) {
                    break;
                }

                $allocated = $this->createPlannedItem(
                    $plan,
                    $date,
                    $weekNumber,
                    $dayKey,
                    $slot['type'],
                    min($remainingToday, $slot['minutes']),
                    $blockNumber,
                    $sortOrder,
                    $queues,
                    $queuePointers,
                    $remainingByModule,
                    $completedModules,
                );

                if ($allocated === 0) {
                    continue;
                }

                $remainingToday -= $allocated;
                $blockNumber++;
            }
        }
    }

    protected function dailyBlueprint(string $dayKey, int $remainingToday, string $intensity): array
    {
        if ($dayKey === 'saturday') {
            return $this->buildSaturdayBlueprint($remainingToday);
        }

        if ($dayKey === 'sunday') {
            return $this->buildSundayBlueprint($remainingToday);
        }

        return $this->buildWeekdayBlueprint($dayKey, $remainingToday, $intensity);
    }

    protected function buildWeekdayBlueprint(string $dayKey, int $remainingToday, string $intensity): array
    {
        return $this->buildInterleavedStudyBlueprint($remainingToday);
    }

    protected function buildSaturdayBlueprint(int $remainingToday): array
    {
        $slots = [];

        while ($remainingToday >= 15) {
            $minutes = min(30, $remainingToday);
            $slots[] = ['type' => count($slots) % 2 === 0 ? 'review' : 'questions', 'minutes' => $minutes];
            $remainingToday -= $minutes;
        }

        return $slots;
    }

    protected function buildSundayBlueprint(int $remainingToday): array
    {
        return $this->buildInterleavedStudyBlueprint($remainingToday);
    }

    protected function buildInterleavedStudyBlueprint(int $remainingToday): array
    {
        $slots = [];

        while ($remainingToday >= 90) {
            $slots[] = ['type' => 'basic', 'minutes' => 30];
            $slots[] = ['type' => 'specific', 'minutes' => 30];
            $slots[] = ['type' => 'questions', 'minutes' => 15];
            $slots[] = ['type' => 'review', 'minutes' => 15];
            $remainingToday -= 90;
        }

        if ($remainingToday >= 60) {
            $slots[] = ['type' => 'basic', 'minutes' => 30];
            $slots[] = ['type' => 'specific', 'minutes' => 30];
            $remainingToday -= 60;
        }

        if ($remainingToday >= 30) {
            $slots[] = ['type' => 'basic', 'minutes' => 30];
            $remainingToday -= 30;
        }

        if ($remainingToday >= 15) {
            $slots[] = ['type' => 'questions', 'minutes' => 15];
            $remainingToday -= 15;
        }

        if ($remainingToday >= 15) {
            $slots[] = ['type' => 'review', 'minutes' => 15];
        }

        return $slots;
    }

    protected function createPlannedItem(
        StudyPlan $plan,
        Carbon $date,
        int $weekNumber,
        string $dayKey,
        string $preferredType,
        int $plannedMinutes,
        int $blockNumber,
        int &$sortOrder,
        Collection $queues,
        array &$queuePointers,
        array &$remainingByModule,
        array &$completedModules,
    ): int {
        $type = $this->resolveTypeForSlot($preferredType, $queues, $remainingByModule);

        if (in_array($type, ['basic', 'specific'], true) && ! $this->hasRemainingModulesForType($type, $queues, $remainingByModule)) {
            return 0;
        }

        [$module, $title, $description] = $this->pickModule($type, $queues, $queuePointers, $remainingByModule, $completedModules, $blockNumber, $dayKey);

        $moduleRemaining = $module ? max(15, (int) ($remainingByModule[$module->id] ?? 15)) : $plannedMinutes;
        $estimatedMinutes = min(60, $plannedMinutes, $moduleRemaining);

        if ($estimatedMinutes < 15) {
            return 0;
        }

        $plan->items()->create([
            'course_module_id' => $module?->id,
            'scheduled_date' => $date->toDateString(),
            'week_number' => $weekNumber,
            'day_of_week' => $dayKey,
            'title' => $title,
            'description' => $description,
            'type' => $type,
            'estimated_minutes' => $estimatedMinutes,
            'sort_order' => $sortOrder++,
        ]);

        if ($module) {
            $remainingByModule[$module->id] -= $estimatedMinutes;

            if ($remainingByModule[$module->id] <= 0) {
                $completedModules[] = $module->name;
            }
        }

        return $estimatedMinutes;
    }

    protected function hasRemainingModulesForType(string $type, Collection $queues, array $remainingByModule): bool
    {
        return ($queues[$type] ?? collect())
            ->contains(fn (CourseModule $module) => ($remainingByModule[$module->id] ?? 0) > 0);
    }

    protected function resolveTypeForSlot(string $preferredType, Collection $queues, array $remainingByModule): string
    {
        $fallbacks = match ($preferredType) {
            'basic' => ['basic', 'specific', 'questions', 'review'],
            'specific' => ['specific', 'basic', 'questions', 'review'],
            'review' => ['review', 'questions', 'specific', 'basic'],
            'questions' => ['questions', 'review', 'specific', 'basic'],
            default => ['specific', 'basic', 'questions', 'review'],
        };

        foreach ($fallbacks as $type) {
            $hasActiveModule = ($queues[$type] ?? collect())
                ->contains(fn (CourseModule $module) => ($remainingByModule[$module->id] ?? 0) > 0);

            if ($hasActiveModule || in_array($type, ['review', 'questions'], true)) {
                return $type;
            }
        }

        return 'other';
    }

    protected function pickModule(
        string $type,
        Collection $queues,
        array &$queuePointers,
        array $remainingByModule,
        array $completedModules,
        int $blockNumber,
        string $dayKey,
    ): array {
        $queue = ($queues[$type] ?? collect())->filter(function (CourseModule $module) use ($remainingByModule) {
            return ($remainingByModule[$module->id] ?? 0) > 0;
        })->values();

        if ($queue->isNotEmpty()) {
            $pointer = $queuePointers[$type] ?? 0;
            $module = $queue[$pointer % $queue->count()];
            $queuePointers[$type] = $pointer + 1;

            return [
                $module,
                $this->makeItemTitle($type, $module->name, $blockNumber),
                $this->makeItemDescription($type, $module->name, $dayKey),
            ];
        }

        if ($type === 'review') {
            $topic = collect($completedModules)->take(-2)->implode(' e ');
            return [
                null,
                'Bloco ' . $blockNumber . ' · Revisão',
                $topic !== ''
                    ? 'Retome resumos, mapas mentais e pontos críticos de ' . $topic . '.'
                    : 'Retome resumos, mapas mentais e os principais pontos estudados para consolidar a memória.',
            ];
        }

        if ($type === 'questions') {
            return [
                null,
                'Bloco ' . $blockNumber . ' · Questões',
                'Resolva questões para consolidar o conteúdo estudado e medir retenção.',
            ];
        }

        return [
            null,
            'Bloco ' . $blockNumber . ' · Bloco complementar',
            'Use este espaço para reforçar o conteúdo mais relevante da sua trilha.',
        ];
    }

    protected function makeItemTitle(string $type, string $moduleName, int $blockNumber): string
    {
        return match ($type) {
            'basic' => 'Bloco ' . $blockNumber . ' · Matéria Básica: ' . $moduleName,
            'specific' => 'Bloco ' . $blockNumber . ' · Conhecimentos Específicos: ' . $moduleName,
            'review' => 'Bloco ' . $blockNumber . ' · Revisão: ' . $moduleName,
            'questions' => 'Bloco ' . $blockNumber . ' · Resolução de Questões: ' . $moduleName,
            default => 'Bloco ' . $blockNumber . ' · ' . $moduleName,
        };
    }

    protected function makeItemDescription(string $type, string $moduleName, string $dayKey): string
    {
        return match ($type) {
            'basic' => 'Bloco de até 60 minutos para fortalecer sua matéria básica em ' . $moduleName . '.',
            'specific' => 'Bloco de até 60 minutos para avançar em conhecimentos específicos com foco em ' . $moduleName . '.',
            'review' => $dayKey === 'saturday'
                ? 'Sábado de revisão: retome pontos-chave de ' . $moduleName . ' com foco em fixação.'
                : 'Bloco de revisão para reforçar a retenção em ' . $moduleName . '.',
            'questions' => 'Bloco de resolução de questões para aplicar na prática o conteúdo de ' . $moduleName . '.',
            default => 'Bloco de estudo planejado para manter consistência até a prova.',
        };
    }
}
