<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Models\StudyTrack;
use App\Models\User;
use App\Support\StudyTime;
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
            $this->syncPublishedLessonsForPlan($plan);

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
            $this->syncPublishedLessonsForPlan($studyPlan);

            return $studyPlan->fresh(['items.courseModule', 'course', 'studyTrack', 'user']);
        });
    }

    public function regenerateFromDate(
        StudyPlan $studyPlan,
        Course $course,
        ?StudyTrack $studyTrack,
        ?string $examDate,
        string $startDate,
        array $availableDays,
        array $availableMinutesByDay,
        string $intensity,
        string $regenerateFromDate,
        bool $reloadItems = true,
        ?string $replaceRemovedModulesFromDate = null,
    ): StudyPlan {
        return DB::transaction(function () use ($studyPlan, $course, $studyTrack, $examDate, $startDate, $availableDays, $availableMinutesByDay, $intensity, $regenerateFromDate, $reloadItems, $replaceRemovedModulesFromDate) {
            $studyPlan->loadMissing(['course', 'studyTrack', 'user']);

            $payload = $this->buildPlanPayload($course, $studyTrack, $examDate, $startDate, $availableDays, $availableMinutesByDay, $intensity);
            $modules = $payload['modules'];
            $moduleIds = $modules->pluck('id')->all();
            $cutoff = Carbon::parse($regenerateFromDate)->startOfDay();

            if (filled($replaceRemovedModulesFromDate)) {
                $replaceCutoff = Carbon::parse($replaceRemovedModulesFromDate)->startOfDay();

                if ($replaceCutoff->lt($cutoff)) {
                    $this->replaceRemovedModuleItemsBeforeCutoff($studyPlan, $modules, $replaceCutoff, $cutoff);
                }
            }

            $preservedItems = $studyPlan->items()
                ->where(function ($query) use ($cutoff) {
                    $query
                        ->whereDate('scheduled_date', '<', $cutoff->toDateString())
                        ->orWhereNotNull('completed_at');
                })
                ->with('courseModule')
                ->get()
                ->values();

            $preservedMinutesByModule = $preservedItems
                ->filter(fn (StudyPlanItem $item): bool => filled($item->course_module_id) && in_array($item->course_module_id, $moduleIds, true))
                ->groupBy('course_module_id')
                ->map(fn (Collection $items): int => (int) $items->sum('estimated_minutes'))
                ->all();

            $remainingByModule = $modules
                ->mapWithKeys(fn (CourseModule $module): array => [
                    $module->id => max(0, (int) $module->workload_minutes - (int) ($preservedMinutesByModule[$module->id] ?? 0)),
                ])
                ->all();

            $studyPlan->user
                ->studyPlans()
                ->where('status', 'active')
                ->where('course_id', $course->id)
                ->whereKeyNot($studyPlan->id)
                ->update(['status' => 'archived']);

            $studyPlan->items()
                ->whereDate('scheduled_date', '>=', $cutoff->toDateString())
                ->whereNull('completed_at')
                ->delete();

            $studyPlan->fill($payload['attributes']);
            $studyPlan->save();

            $completedModules = $preservedItems
                ->filter(fn (StudyPlanItem $item): bool => in_array($item->course_module_id, $moduleIds, true))
                ->map(fn (StudyPlanItem $item): ?string => $item->courseModule?->name)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($cutoff->lte($payload['exam'])) {
                $this->generateItems(
                    $studyPlan,
                    $modules,
                    $cutoff,
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

            $this->syncPublishedLessonsForPlan($studyPlan);

            return $reloadItems
                ? $studyPlan->fresh(['items.courseModule', 'course', 'studyTrack', 'user'])
                : $studyPlan->fresh(['course', 'studyTrack', 'user']);
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
            $this->syncPublishedLessonsForPlan($studyPlan);
        }

        return $studyPlan->fresh(['items.courseModule', 'course', 'studyTrack', 'user']);
    }

    protected function replaceRemovedModuleItemsBeforeCutoff(StudyPlan $studyPlan, Collection $modules, Carbon $from, Carbon $until): void
    {
        $moduleIds = $modules->pluck('id')->all();

        if ($moduleIds === []) {
            $this->convertRemovedModuleItemsToReserve($studyPlan, $from, $until);

            return;
        }

        $removedItems = $studyPlan->items()
            ->whereDate('scheduled_date', '>=', $from->toDateString())
            ->whereDate('scheduled_date', '<', $until->toDateString())
            ->whereNull('completed_at')
            ->where(function ($query) use ($moduleIds): void {
                $query
                    ->where(function ($query) use ($moduleIds): void {
                        $query
                            ->whereNotNull('course_module_id')
                            ->whereNotIn('course_module_id', $moduleIds);
                    })
                    ->orWhere(function ($query): void {
                        $query
                            ->whereNull('course_module_id')
                            ->whereIn('type', ['basic', 'specific', 'complementary']);
                    });
            })
            ->orderBy('scheduled_date')
            ->orderBy('sort_order')
            ->get();

        if ($removedItems->isEmpty()) {
            return;
        }

        $scheduledMinutesByModule = $studyPlan->items()
            ->whereDate('scheduled_date', '<', $until->toDateString())
            ->whereIn('course_module_id', $moduleIds)
            ->selectRaw('course_module_id, sum(estimated_minutes) as minutes')
            ->groupBy('course_module_id')
            ->pluck('minutes', 'course_module_id')
            ->map(fn ($minutes): int => (int) $minutes)
            ->all();
        $remainingByModule = $modules
            ->mapWithKeys(fn (CourseModule $module): array => [
                $module->id => max(0, (int) $module->workload_minutes - (int) ($scheduledMinutesByModule[$module->id] ?? 0)),
            ])
            ->all();
        $orderedModules = $this->sortModulesForPlanning($modules);
        $reserveIndex = 0;

        foreach ($removedItems as $item) {
            $plannedMinutes = max(15, (int) $item->estimated_minutes);
            $blockNumber = $this->blockNumberFromItem($item);
            $replacementModule = $orderedModules->first(fn (CourseModule $module): bool => ($remainingByModule[$module->id] ?? 0) > 0);

            if ($replacementModule) {
                $type = $this->normalizeModuleType($replacementModule->type);
                $lessonNames = collect($replacementModule->planning_lessons)
                    ->pluck('name')
                    ->filter()
                    ->take(2)
                    ->values()
                    ->all();

                $item->forceFill([
                    'course_module_id' => $replacementModule->id,
                    'title' => $this->makeItemTitle($type, $replacementModule->name, $blockNumber),
                    'description' => $this->makeItemDescription($type, $replacementModule->name, (string) $item->day_of_week, $plannedMinutes, $lessonNames),
                    'type' => $type,
                    'estimated_minutes' => $plannedMinutes,
                ])->save();

                $remainingByModule[$replacementModule->id] = max(0, (int) ($remainingByModule[$replacementModule->id] ?? 0) - $plannedMinutes);

                continue;
            }

            $type = $reserveIndex % 2 === 0 ? 'questions' : 'review';
            $reserveIndex++;

            $item->forceFill([
                'course_module_id' => null,
                'title' => $type === 'questions'
                    ? 'Bloco '.$blockNumber.' · Questões'
                    : 'Bloco '.$blockNumber.' · Revisão',
                'description' => $type === 'questions'
                    ? 'Reserva de até '.$plannedMinutes.' minutos para resolver questões e manter a prática sem alterar o progresso já feito.'
                    : 'Reserva de até '.$plannedMinutes.' minutos para revisar pontos importantes sem alterar o progresso já feito.',
                'type' => $type,
                'estimated_minutes' => $plannedMinutes,
            ])->save();
        }
    }

    protected function convertRemovedModuleItemsToReserve(StudyPlan $studyPlan, Carbon $from, Carbon $until): void
    {
        $items = $studyPlan->items()
            ->whereDate('scheduled_date', '>=', $from->toDateString())
            ->whereDate('scheduled_date', '<', $until->toDateString())
            ->whereNull('completed_at')
            ->where(function ($query): void {
                $query
                    ->whereNotNull('course_module_id')
                    ->orWhereIn('type', ['basic', 'specific', 'complementary']);
            })
            ->orderBy('scheduled_date')
            ->orderBy('sort_order')
            ->get();
        $reserveIndex = 0;

        foreach ($items as $item) {
            $plannedMinutes = max(15, (int) $item->estimated_minutes);
            $blockNumber = $this->blockNumberFromItem($item);
            $type = $reserveIndex % 2 === 0 ? 'questions' : 'review';
            $reserveIndex++;

            $item->forceFill([
                'course_module_id' => null,
                'title' => $type === 'questions'
                    ? 'Bloco '.$blockNumber.' · Questões'
                    : 'Bloco '.$blockNumber.' · Revisão',
                'description' => $type === 'questions'
                    ? 'Reserva de até '.$plannedMinutes.' minutos para resolver questões e manter a prática sem alterar o progresso já feito.'
                    : 'Reserva de até '.$plannedMinutes.' minutos para revisar pontos importantes sem alterar o progresso já feito.',
                'type' => $type,
                'estimated_minutes' => $plannedMinutes,
            ])->save();
        }
    }

    protected function blockNumberFromItem(StudyPlanItem $item): int
    {
        if (preg_match('/Bloco\s+(\d+)/i', (string) $item->title, $matches)) {
            return max(1, (int) $matches[1]);
        }

        return max(1, (int) $item->sort_order);
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

            [$viabilityStatus, $viabilityMessage] = $this->resolveViability(
                $totalAvailableMinutes,
                $remainingRequiredMinutes,
                filled($examDate),
                $today,
                $exam,
                $availableDays,
            );

            $studyPlan->items()->whereNull('completed_at')->delete();

            $studyPlan->forceFill([
                'name' => filled($examDate)
                    ? 'Plano '.Str::title($studyPlan->course->name).' até '.$exam->format('d/m/Y')
                    : 'Plano '.Str::title($studyPlan->course->name).' sem previsão de prova',
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
                $this->syncPublishedLessonsForPlan($studyPlan);
            }

            $studyPlan->forceFill([
                'total_required_minutes' => (int) $modules->sum('workload_minutes'),
            ])->save();

            return $studyPlan->fresh(['items.courseModule', 'course', 'studyTrack', 'user']);
        });
    }

    public function syncPublishedLessonsForPlan(StudyPlan $studyPlan): StudyPlan
    {
        return DB::transaction(function () use ($studyPlan): StudyPlan {
            $studyPlan->loadMissing(['course', 'items.courseModule.onlineLessons']);

            if (! $studyPlan->course) {
                return $studyPlan;
            }

            $this->publishReadyLessonsForCourse($studyPlan->course);

            $lessonIndexes = [];
            $studyPlan->items()
                ->with('courseModule.onlineLessons')
                ->orderBy('scheduled_date')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->each(function (StudyPlanItem $item) use (&$lessonIndexes): void {
                    $module = $item->courseModule;

                    if (! $module || ! in_array($item->type, ['basic', 'specific', 'complementary'], true)) {
                        return;
                    }

                    $lessonNames = $this->lessonNamesForPlanItem($module, $item, $lessonIndexes);

                    if ($lessonNames === []) {
                        return;
                    }

                    $this->attachOnlineLessonsToItem($item, $module, $lessonNames);
                });

            return $studyPlan->fresh(['items.courseModule', 'items.lessons', 'course', 'studyTrack', 'user']);
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

        [$viabilityStatus, $viabilityMessage] = $this->resolveViability(
            $totalAvailableMinutes,
            $totalRequiredMinutes,
            filled($examDate),
            $start,
            $exam,
            $availableDays,
        );

        return [
            'modules' => $modules,
            'start' => $start,
            'exam' => $exam,
            'attributes' => [
                'course_id' => $course->id,
                'study_track_id' => $studyTrack?->id,
                'name' => filled($examDate)
                    ? 'Plano '.Str::title($course->name).' até '.$exam->format('d/m/Y')
                    : 'Plano '.Str::title($course->name).' sem previsão de prova',
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
                ->sortBy(fn (CourseModule $module) => $this->modulePlanningOrder($module))
                ->values();
        }

        $pivotModules = $course->modules()
            ->where('course_modules.is_active', true)
            ->get();

        $legacyModules = CourseModule::query()
            ->where('course_id', $course->id)
            ->where('is_active', true)
            ->get();

        return $pivotModules
            ->concat($legacyModules)
            ->unique('id')
            ->reject(fn (CourseModule $module) => $this->shouldSkipModule($module))
            ->sortBy(fn (CourseModule $module) => $this->modulePlanningOrder($module))
            ->values();
    }

    protected function sortModulesForPlanning(Collection $modules): Collection
    {
        return $modules
            ->sortBy(fn (CourseModule $module) => $this->modulePlanningOrder($module))
            ->values();
    }

    protected function modulePlanningOrder(CourseModule $module): int
    {
        return (int) ($module->pivot?->sort_order ?? $module->sort_order);
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

    protected function resolveViability(
        int $available,
        int $required,
        bool $hasExamDate,
        ?Carbon $start = null,
        ?Carbon $exam = null,
        array $availableDays = [],
    ): array {
        if (! $hasExamDate) {
            return ['good', 'Plano criado sem data de prova definida. Distribuímos o ciclo com foco em constância, revisão e questões até você informar a prova.'];
        }

        $minimumDailyGuidance = $this->buildMinimumDailyGuidance($required, $start, $exam, $availableDays);
        $isCloseExam = $start && $exam ? $start->diffInDays($exam) <= 21 : false;

        if ($required === 0 || $available >= $required) {
            $message = 'Plano viável. Seu tempo disponível cobre a carga necessária até a prova.';

            if ($isCloseExam && $minimumDailyGuidance) {
                $message .= ' '.$minimumDailyGuidance;
            }

            return ['good', $message];
        }

        if ($available >= (int) round($required * 0.75)) {
            $message = 'Plano apertado. Há espaço para avançar, mas será importante manter constância e priorizar o essencial.';

            if ($minimumDailyGuidance) {
                $message .= ' '.$minimumDailyGuidance;
            }

            return ['warning', $message];
        }

        $message = 'Plano crítico. O tempo disponível não cobre a carga prevista, então o ciclo vai priorizar o que mais move sua preparação.';

        if ($minimumDailyGuidance) {
            $message .= ' '.$minimumDailyGuidance;
        }

        return ['critical', $message];
    }

    protected function buildMinimumDailyGuidance(int $requiredMinutes, ?Carbon $start, ?Carbon $exam, array $availableDays): ?string
    {
        if (! $start || ! $exam || $requiredMinutes <= 0 || $availableDays === []) {
            return null;
        }

        $studyDaysUntilExam = $this->countStudyDaysUntilExam($start, $exam, $availableDays);

        if ($studyDaysUntilExam === 0) {
            return null;
        }

        $minimumDailyMinutes = (int) ceil(($requiredMinutes / $studyDaysUntilExam) / 15) * 15;
        $daysPerWeek = count(array_unique($availableDays));

        if ($minimumDailyMinutes > 480) {
            return 'Mesmo estudando 8h por dia nos dias marcados, a carga completa não cabe até a prova. Se possível, aumente os dias disponíveis ou reduza o escopo de prioridade.';
        }

        return 'Para cumprir 100% da carga até a prova, mantenha pelo menos '
            .StudyTime::formatMinutes($minimumDailyMinutes)
            .' por dia nos '
            .$daysPerWeek
            .' dia(s) de estudo que você marcou por semana.';
    }

    protected function countStudyDaysUntilExam(Carbon $start, Carbon $exam, array $availableDays): int
    {
        $total = 0;

        for ($date = $start->copy(); $date->lte($exam); $date->addDay()) {
            if (in_array(strtolower($date->englishDayOfWeek), $availableDays, true)) {
                $total++;
            }
        }

        return $total;
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
        $orderedModules = $this->sortModulesForPlanning($modules);
        $theoryModules = $orderedModules
            ->filter(fn (CourseModule $module) => in_array($this->normalizeModuleType($module->type), ['basic', 'specific', 'complementary'], true))
            ->values();
        $remainingByModule = $remainingByModule ?? $modules->mapWithKeys(fn (CourseModule $module) => [$module->id => (int) $module->workload_minutes])->all();
        $lessonStates = $this->buildLessonStates($theoryModules, $remainingByModule);
        $typeQueues = $this->buildTheoryQueues($theoryModules);
        $typePointers = collect($typeQueues)->mapWithKeys(fn (Collection $queue, string $type) => [$type => 0])->all();
        $lastSubjectsByType = [];
        $typeRotationIndex = 0;
        $sortOrder = $initialSortOrder;
        $weeklyTheoryScheduled = [];
        $weeklyTheoryMinutes = [];
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
                (int) ($weeklyTheoryMinutes[$weekNumber] ?? 0),
            );

            if ($dayKey === 'saturday') {
                $weekTheoryTargetMinutes = 360;
                $theoryBudget = (
                    ($weeklyTheoryMinutes[$weekNumber] ?? 0) < $weekTheoryTargetMinutes
                    && $this->hasRemainingTheoryModules($typeQueues, $typePointers, $lessonStates)
                )
                    ? (int) floor($remainingToday / 2)
                    : 0;
                $reserveMinutes = max(0, $remainingToday - $theoryBudget);

                if ($theoryBudget <= 0) {
                    $this->createReserveItems(
                        $plan,
                        $date,
                        $weekNumber,
                        $dayKey,
                        min($reserveMinutes, $remainingToday),
                        $blockNumber,
                        $sortOrder,
                        $completedModules,
                        $orderedModules,
                        $remainingByModule,
                        true,
                    );

                    continue;
                }

                $reserveMinutes = 0;
            } else {
                $theoryBudget = max(0, $remainingToday - $reserveMinutes);
            }

            $minimumPairTheoryMinutes = $this->minimumDailyPairTheoryMinutes($typeQueues, $typePointers, $lessonStates, $remainingByModule);
            if ($minimumPairTheoryMinutes > $theoryBudget && $minimumPairTheoryMinutes <= $remainingToday) {
                $theoryBudget = $minimumPairTheoryMinutes;
            }

            $balancedTheoryBlocks = 0;
            $balancedAllocated = $this->createBalancedDailyTheoryItems(
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
                $lastSubjectsByType,
                $lessonStates,
                $remainingByModule,
                $completedModules,
                $balancedTheoryBlocks,
            );

            if ($balancedAllocated > 0) {
                $theoryBudget -= $balancedAllocated;
                $remainingToday -= $balancedAllocated;
                $weeklyTheoryScheduled[$weekNumber] = true;
                $weeklyTheoryMinutes[$weekNumber] = ($weeklyTheoryMinutes[$weekNumber] ?? 0) + $balancedAllocated;
            }

            while ($theoryBudget >= 15 && $this->hasRemainingTheoryModules($typeQueues, $typePointers, $lessonStates)) {
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
                    $lastSubjectsByType,
                    $lessonStates,
                    $remainingByModule,
                    $completedModules,
                );

                if ($allocated === 0) {
                    break;
                }

                $theoryBudget -= $allocated;
                $remainingToday -= $allocated;
                $weeklyTheoryScheduled[$weekNumber] = true;
                $weeklyTheoryMinutes[$weekNumber] = ($weeklyTheoryMinutes[$weekNumber] ?? 0) + $allocated;
                $blockNumber++;
            }

            if ($dayKey !== 'saturday') {
                $shouldExpandReserve = $theoryBudget > 0;

                $this->createReserveItems(
                    $plan,
                    $date,
                    $weekNumber,
                    $dayKey,
                    $remainingToday,
                    $blockNumber,
                    $sortOrder,
                    $completedModules,
                    $orderedModules,
                    $remainingByModule,
                    $shouldExpandReserve,
                );
            } else {
                $this->createReserveItems(
                    $plan,
                    $date,
                    $weekNumber,
                    $dayKey,
                    max(0, $remainingToday),
                    $blockNumber,
                    $sortOrder,
                    $completedModules,
                    $orderedModules,
                    $remainingByModule,
                    true,
                );
            }
        }
    }

    protected function createBalancedDailyTheoryItems(
        StudyPlan $plan,
        Carbon $date,
        int $weekNumber,
        string $dayKey,
        int $theoryBudget,
        int &$blockNumber,
        int &$sortOrder,
        array $typeQueues,
        array &$typePointers,
        int &$typeRotationIndex,
        array &$lastSubjectsByType,
        array &$lessonStates,
        array &$remainingByModule,
        array &$completedModules,
        int &$createdBlocks,
    ): int {
        if (
            $theoryBudget < 30
            || ! $this->hasRemainingTheoryTypes(['basic'], $typeQueues, $typePointers, $lessonStates)
            || ! $this->hasRemainingTheoryTypes(['specific', 'complementary'], $typeQueues, $typePointers, $lessonStates)
        ) {
            return 0;
        }

        $allocated = 0;
        $dailyGroups = [
            ['basic'],
            ['specific', 'complementary'],
        ];

        foreach ($dailyGroups as $index => $preferredTypes) {
            $remainingBudget = $theoryBudget - $allocated;

            if ($remainingBudget <= 0 || ! $this->hasRemainingTheoryTypes($preferredTypes, $typeQueues, $typePointers, $lessonStates)) {
                continue;
            }

            $nextGroupMinimum = isset($dailyGroups[$index + 1])
                ? $this->minimumTheoryBlockMinutesForTypes($dailyGroups[$index + 1], $typeQueues, $typePointers, $lessonStates, $remainingByModule)
                : 0;
            $currentGroupMinimum = $this->minimumTheoryBlockMinutesForTypes($preferredTypes, $typeQueues, $typePointers, $lessonStates, $remainingByModule);
            $availableForBlock = $index === 0
                ? max($currentGroupMinimum, min((int) floor($theoryBudget / 2), $theoryBudget - $nextGroupMinimum), 15)
                : $remainingBudget;
            $availableForBlock = min($remainingBudget, $availableForBlock);

            $blockAllocated = $this->createInterleavedStudyItem(
                $plan,
                $date,
                $weekNumber,
                $dayKey,
                $availableForBlock,
                $blockNumber,
                $sortOrder,
                $typeQueues,
                $typePointers,
                $typeRotationIndex,
                $lastSubjectsByType,
                $lessonStates,
                $remainingByModule,
                $completedModules,
                $preferredTypes,
            );

            if ($blockAllocated <= 0 && $index === 0 && $availableForBlock < $remainingBudget) {
                $blockAllocated = $this->createInterleavedStudyItem(
                    $plan,
                    $date,
                    $weekNumber,
                    $dayKey,
                    $remainingBudget,
                    $blockNumber,
                    $sortOrder,
                    $typeQueues,
                    $typePointers,
                    $typeRotationIndex,
                    $lastSubjectsByType,
                    $lessonStates,
                    $remainingByModule,
                    $completedModules,
                    $preferredTypes,
                );
            }

            if ($blockAllocated <= 0) {
                continue;
            }

            $allocated += $blockAllocated;
            $createdBlocks++;
            $blockNumber++;
        }

        return $allocated;
    }

    protected function minimumDailyPairTheoryMinutes(array $typeQueues, array $typePointers, array $lessonStates, array $remainingByModule): int
    {
        if (
            ! $this->hasRemainingTheoryTypes(['basic'], $typeQueues, $typePointers, $lessonStates)
            || ! $this->hasRemainingTheoryTypes(['specific', 'complementary'], $typeQueues, $typePointers, $lessonStates)
        ) {
            return 0;
        }

        return $this->minimumTheoryBlockMinutesForTypes(['basic'], $typeQueues, $typePointers, $lessonStates, $remainingByModule)
            + $this->minimumTheoryBlockMinutesForTypes(['specific', 'complementary'], $typeQueues, $typePointers, $lessonStates, $remainingByModule);
    }

    protected function minimumTheoryBlockMinutesForTypes(array $types, array $typeQueues, array $typePointers, array $lessonStates, array $remainingByModule): int
    {
        foreach ($types as $type) {
            $module = $this->remainingTheoryModules($typeQueues[$type] ?? collect(), $typePointers[$type] ?? 0, $lessonStates)->first();

            if (! $module) {
                continue;
            }

            $state = $lessonStates[$module->id] ?? null;
            $index = (int) ($state['index'] ?? 0);
            $lessons = $state['lessons'] ?? [];
            $lessonMinutes = (int) ($lessons[$index]['minutes'] ?? 0);

            if ($lessonMinutes > 0) {
                return $lessonMinutes;
            }

            return min(60, max(15, (int) ($remainingByModule[$module->id] ?? $module->workload_minutes)));
        }

        return 0;
    }

    protected function resolveReserveMinutes(string $dayKey, int $remainingToday, int $weeklyTheoryMinutes): int
    {
        if ($remainingToday <= 60) {
            return min(20, $remainingToday);
        }

        return max(30, (int) round($remainingToday * 0.25));
    }

    protected function buildReserveBlueprint(string $dayKey, int $remainingToday, bool $fillAvailable = false): array
    {
        if ($remainingToday <= 0) {
            return [];
        }

        if ($fillAvailable) {
            if ($remainingToday < 20) {
                return [
                    [
                        'type' => 'questions',
                        'minutes' => $remainingToday,
                    ],
                ];
            }

            $questionsMinutes = (int) ceil($remainingToday / 2);
            $reviewMinutes = max(0, $remainingToday - $questionsMinutes);

            return array_values(array_filter([
                [
                    'type' => 'questions',
                    'minutes' => $questionsMinutes,
                ],
                $reviewMinutes > 0 ? [
                    'type' => 'review',
                    'minutes' => $reviewMinutes,
                ] : null,
            ]));
        }

        if ($remainingToday < 20) {
            return [
                [
                    'type' => 'questions',
                    'minutes' => $remainingToday,
                ],
            ];
        }

        if ($remainingToday <= 30) {
            $targetMinutes = $remainingToday <= 20 ? 10 : 15;
            $questionsMinutes = min($targetMinutes, (int) ceil($remainingToday / 2));
            $reviewMinutes = min($targetMinutes, max(0, $remainingToday - $questionsMinutes));
        } else {
            $questionsMinutes = (int) ceil($remainingToday / 2);
            $reviewMinutes = max(0, $remainingToday - $questionsMinutes);
        }

        return array_values(array_filter([
            [
                'type' => 'questions',
                'minutes' => $questionsMinutes,
            ],
            $reviewMinutes > 0 ? [
                'type' => 'review',
                'minutes' => $reviewMinutes,
            ] : null,
        ]));
    }

    protected function createReserveItems(
        StudyPlan $plan,
        Carbon $date,
        int $weekNumber,
        string $dayKey,
        int $availableMinutes,
        int &$blockNumber,
        int &$sortOrder,
        array $completedModules,
        Collection $orderedModules,
        array $remainingByModule,
        bool $fillAvailable = false,
    ): int {
        $allocated = 0;

        foreach ($this->buildReserveBlueprint($dayKey, $availableMinutes, $fillAvailable) as $reserveBlock) {
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

            $allocated += $reserveBlock['minutes'];
            $blockNumber++;
        }

        return $allocated;
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
        array &$lastSubjectsByType,
        array &$lessonStates,
        array &$remainingByModule,
        array &$completedModules,
        ?array $preferredTypes = null,
    ): int {
        [$type, $module] = $preferredTypes
            ? $this->resolveCurrentTheoryModuleFromTypes($preferredTypes, $typeQueues, $typePointers, $typeRotationIndex, $lastSubjectsByType, $lessonStates)
            : $this->resolveCurrentTheoryModule($typeQueues, $typePointers, $typeRotationIndex, $lastSubjectsByType, $lessonStates);

        if (! $module || ! $type) {
            return 0;
        }

        $lessonBlock = $this->buildLessonBlock($module, $availableMinutes, $lessonStates[$module->id] ?? null);
        $estimatedMinutes = (int) ($lessonBlock['minutes'] ?? 0);

        if ($estimatedMinutes <= 0) {
            return 0;
        }

        $type = $this->normalizeModuleType($module->type);
        $lessonNames = $lessonBlock['lesson_names'] ?? [];
        $lastSubjectsByType[$type] = $this->moduleSubject($module);

        $item = $plan->items()->create([
            'course_module_id' => $module->id,
            'scheduled_date' => $date->toDateString(),
            'week_number' => $weekNumber,
            'day_of_week' => $dayKey,
            'title' => $this->makeItemTitle($type, $module->name, $blockNumber),
            'description' => $this->makeItemDescription($type, $module->name, $dayKey, $estimatedMinutes, $lessonNames),
            'type' => $type,
            'estimated_minutes' => $estimatedMinutes,
            'sort_order' => $sortOrder++,
        ]);

        $this->attachOnlineLessonsToItem($item, $module, $lessonNames);

        $remainingByModule[$module->id] -= $estimatedMinutes;
        $lessonStates[$module->id] = $lessonBlock['state'];

        if (($lessonBlock['completed_module'] ?? false) || ($remainingByModule[$module->id] ?? 0) <= 0) {
            $completedModules[] = $module->name;
        }

        $typePointers[$type] = $this->nextTheoryPointer($typeQueues[$type] ?? collect(), $module);

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

    protected function attachOnlineLessonsToItem(StudyPlanItem $item, CourseModule $module, array $lessonNames): void
    {
        $normalizedLessonNames = collect($lessonNames)
            ->map(fn (string $lessonName) => $this->normalizeLessonName($lessonName))
            ->filter()
            ->values();

        if ($normalizedLessonNames->isEmpty()) {
            return;
        }

        $onlineLessons = ($module->relationLoaded('onlineLessons')
            ? $module->onlineLessons
            : $module->onlineLessons()->get())
            ->filter(fn (Lesson $lesson): bool => $lesson->status === 'published')
            ->values();

        if ($onlineLessons->isEmpty()) {
            return;
        }

        $matchedLessons = collect();

        foreach ($normalizedLessonNames as $lessonName) {
            $matchedLesson = $onlineLessons->first(function (Lesson $lesson) use ($lessonName, $matchedLessons) {
                if ($matchedLessons->contains(fn (Lesson $matched) => $matched->is($lesson))) {
                    return false;
                }

                $normalizedTitle = $this->normalizeLessonName($lesson->title);

                return $normalizedTitle === $lessonName
                    || Str::contains($normalizedTitle, $lessonName)
                    || Str::contains($lessonName, $normalizedTitle);
            });

            if ($matchedLesson) {
                $matchedLessons->push($matchedLesson);
            }
        }

        if ($matchedLessons->isEmpty()) {
            return;
        }

        $syncPayload = $matchedLessons
            ->values()
            ->mapWithKeys(fn (Lesson $lesson, int $index) => [$lesson->id => ['sort_order' => $index + 1]])
            ->all();

        $item->lessons()->sync($syncPayload);
    }

    protected function lessonNamesForPlanItem(CourseModule $module, StudyPlanItem $item, array &$lessonIndexes): array
    {
        $moduleId = $module->id;
        $lessons = $module->planning_lessons;
        $index = $lessonIndexes[$moduleId] ?? 0;
        $minutes = 0;
        $lessonNames = [];

        while ($index < count($lessons)) {
            $lessonMinutes = (int) ($lessons[$index]['minutes'] ?? 0);

            if ($lessonMinutes <= 0) {
                $index++;

                continue;
            }

            if (($minutes + $lessonMinutes) > (int) $item->estimated_minutes) {
                break;
            }

            $lessonNames[] = (string) ($lessons[$index]['name'] ?? '');
            $minutes += $lessonMinutes;
            $index++;
        }

        $lessonIndexes[$moduleId] = $index;

        return array_values(array_filter($lessonNames));
    }

    protected function publishReadyLessonsForCourse(Course $course): void
    {
        $trackLessonIds = DB::table('course_module_track_course')
            ->join('course_module_track_lessons', 'course_module_track_lessons.course_module_track_id', '=', 'course_module_track_course.course_module_track_id')
            ->join('lessons', 'lessons.id', '=', 'course_module_track_lessons.lesson_id')
            ->where('course_module_track_course.course_id', $course->id)
            ->where($this->readyLessonFilter())
            ->distinct()
            ->pluck('lessons.id');

        $moduleLessonIds = DB::table('course_module_course')
            ->join('course_module_lessons', 'course_module_lessons.course_module_id', '=', 'course_module_course.course_module_id')
            ->join('lessons', 'lessons.id', '=', 'course_module_lessons.lesson_id')
            ->where('course_module_course.course_id', $course->id)
            ->where($this->readyLessonFilter())
            ->distinct()
            ->pluck('lessons.id');

        $legacyModuleLessonIds = DB::table('course_modules')
            ->join('course_module_lessons', 'course_module_lessons.course_module_id', '=', 'course_modules.id')
            ->join('lessons', 'lessons.id', '=', 'course_module_lessons.lesson_id')
            ->where('course_modules.course_id', $course->id)
            ->where($this->readyLessonFilter())
            ->distinct()
            ->pluck('lessons.id');

        $lessonIds = $trackLessonIds
            ->merge($moduleLessonIds)
            ->merge($legacyModuleLessonIds)
            ->unique()
            ->values();

        if ($lessonIds->isEmpty()) {
            return;
        }

        Lesson::query()
            ->whereIn('id', $lessonIds)
            ->where('status', '!=', 'published')
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);
    }

    protected function readyLessonFilter(): callable
    {
        return function ($query): void {
            $query->where(function ($query): void {
                $query->whereNotNull('lessons.panda_video_id')
                    ->orWhereNotNull('lessons.panda_embed_url')
                    ->orWhereNotNull('lessons.panda_player_url')
                    ->orWhere('lessons.source_status', 'media_ready');
            });
        };
    }

    protected function normalizeLessonName(string $value): string
    {
        return Str::of($value)
            ->replaceMatches('/^continua(?:c|ç)(?:a|ã)o:\s*/iu', '')
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
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

    protected function hasRemainingTheoryModules(array $typeQueues, array $typePointers, array $lessonStates): bool
    {
        foreach (['basic', 'specific', 'complementary'] as $type) {
            $queue = $typeQueues[$type] ?? collect();
            $pointer = $typePointers[$type] ?? 0;

            if ($this->remainingTheoryModules($queue, $pointer, $lessonStates)->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    protected function hasRemainingTheoryTypes(array $types, array $typeQueues, array $typePointers, array $lessonStates): bool
    {
        foreach ($types as $type) {
            $queue = $typeQueues[$type] ?? collect();
            $pointer = $typePointers[$type] ?? 0;

            if ($this->remainingTheoryModules($queue, $pointer, $lessonStates)->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    protected function hasRemainingModules(Collection $orderedModules, array $remainingByModule): bool
    {
        return $orderedModules
            ->contains(fn (CourseModule $module) => ($remainingByModule[$module->id] ?? 0) > 0);
    }

    protected function resolveCurrentTheoryModule(array $typeQueues, array &$typePointers, int &$typeRotationIndex, array $lastSubjectsByType, array $lessonStates): array
    {
        $types = ['basic', 'specific', 'complementary'];
        $count = count($types);

        for ($offset = 0; $offset < $count; $offset++) {
            $typeIndex = ($typeRotationIndex + $offset) % $count;
            $type = $types[$typeIndex];
            $queue = $typeQueues[$type] ?? collect();
            $pointer = $typePointers[$type] ?? 0;
            $module = $this->resolveNextModuleForType($queue, $pointer, $lastSubjectsByType[$type] ?? null, $lessonStates);

            if ($module) {
                $typeRotationIndex = ($typeIndex + 1) % $count;

                return [$type, $module];
            }
        }

        return [null, null];
    }

    protected function resolveCurrentTheoryModuleFromTypes(array $types, array $typeQueues, array &$typePointers, int &$typeRotationIndex, array $lastSubjectsByType, array $lessonStates): array
    {
        foreach ($types as $type) {
            $queue = $typeQueues[$type] ?? collect();
            $pointer = $typePointers[$type] ?? 0;
            $module = $this->resolveNextModuleForType($queue, $pointer, $lastSubjectsByType[$type] ?? null, $lessonStates);

            if ($module) {
                $allTypes = ['basic', 'specific', 'complementary'];
                $typeIndex = array_search($type, $allTypes, true);

                if ($typeIndex !== false) {
                    $typeRotationIndex = (((int) $typeIndex) + 1) % count($allTypes);
                }

                return [$type, $module];
            }
        }

        return [null, null];
    }

    protected function resolveNextModuleForType(Collection $queue, int $pointer, ?string $lastSubject, array $lessonStates): ?CourseModule
    {
        $availableModules = $this->remainingTheoryModules($queue, $pointer, $lessonStates);

        if ($availableModules->isEmpty()) {
            return null;
        }

        return $availableModules
            ->first(fn (CourseModule $module) => $this->moduleSubject($module) !== $lastSubject)
            ?? $availableModules->first();
    }

    protected function remainingTheoryModules(Collection $queue, int $pointer, array $lessonStates): Collection
    {
        $count = $queue->count();

        if ($count === 0) {
            return collect();
        }

        return collect(range(0, $count - 1))
            ->map(fn (int $offset) => $queue[($pointer + $offset) % $count])
            ->filter(function (CourseModule $module) use ($lessonStates) {
                $state = $lessonStates[$module->id] ?? null;

                return $state && ($state['index'] ?? 0) < count($state['lessons'] ?? []);
            })
            ->values();
    }

    protected function nextTheoryPointer(Collection $queue, CourseModule $currentModule): int
    {
        $index = $queue->search(fn (CourseModule $module) => $module->id === $currentModule->id);

        if ($index === false || $queue->isEmpty()) {
            return 0;
        }

        return (((int) $index) + 1) % $queue->count();
    }

    protected function buildLessonStates(Collection $modules, array $remainingByModule): array
    {
        return $modules->mapWithKeys(function (CourseModule $module) use ($remainingByModule) {
            $lessons = collect($module->planning_lessons)->values()->all();
            $completedMinutes = max(0, (int) $module->workload_minutes - (int) ($remainingByModule[$module->id] ?? 0));
            $index = 0;

            while ($index < count($lessons) && $completedMinutes >= (int) $lessons[$index]['minutes']) {
                $completedMinutes -= (int) $lessons[$index]['minutes'];
                $index++;
            }

            if ($completedMinutes > 0 && $index < count($lessons)) {
                $lessons[$index]['minutes'] = max(1, (int) $lessons[$index]['minutes'] - $completedMinutes);
                $lessons[$index]['name'] = 'Continuação: '.$lessons[$index]['name'];
            }

            return [$module->id => [
                'lessons' => $lessons,
                'index' => $index,
            ]];
        })->all();
    }

    protected function buildLessonBlock(CourseModule $module, int $availableMinutes, ?array $state): array
    {
        $state ??= ['lessons' => $module->planning_lessons, 'index' => 0];
        $lessons = $state['lessons'] ?? [];
        $index = (int) ($state['index'] ?? 0);
        $maxBlockMinutes = min(90, $availableMinutes);
        $lessonNames = [];
        $totalMinutes = 0;
        $consumedLessons = 0;

        while (($index + $consumedLessons) < count($lessons)) {
            $lesson = $lessons[$index + $consumedLessons];
            $lessonMinutes = (int) ($lesson['minutes'] ?? 0);

            if ($lessonMinutes <= 0) {
                $consumedLessons++;

                continue;
            }

            if (($totalMinutes + $lessonMinutes) > $maxBlockMinutes) {
                $remainingBlockMinutes = $maxBlockMinutes - $totalMinutes;

                if ($remainingBlockMinutes <= 0) {
                    break;
                }

                $lessonNames[] = (string) ($lesson['name'] ?? $module->name);
                $totalMinutes += $remainingBlockMinutes;
                $lessons[$index + $consumedLessons]['minutes'] = $lessonMinutes - $remainingBlockMinutes;
                $lessons[$index + $consumedLessons]['name'] = 'Continuação: '.preg_replace('/^Continuação:\s*/', '', (string) ($lesson['name'] ?? $module->name));
                $state['lessons'] = $lessons;

                break;
            }

            $lessonNames[] = (string) ($lesson['name'] ?? $module->name);
            $totalMinutes += $lessonMinutes;
            $consumedLessons++;
        }

        if ($totalMinutes <= 0) {
            return [
                'minutes' => 0,
                'lesson_names' => [],
                'completed_module' => false,
                'state' => $state,
            ];
        }

        $state['index'] = $index + $consumedLessons;

        return [
            'minutes' => $totalMinutes,
            'lesson_names' => $lessonNames,
            'completed_module' => $state['index'] >= count($lessons),
            'state' => $state,
        ];
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
                'Bloco '.$blockNumber.' · Revisão',
                $topic !== ''
                    ? 'Reserva de até '.$plannedMinutes.' minutos para retomar resumos, mapas mentais e pontos críticos de '.$topic.'.'
                    : 'Reserva de até '.$plannedMinutes.' minutos para retomar resumos, mapas mentais e os principais pontos estudados para consolidar a memória.',
            ];
        }

        if ($type === 'questions') {
            return [
                $currentModule,
                'Bloco '.$blockNumber.' · Questões',
                $referenceModuleName
                    ? 'Reserva de até '.$plannedMinutes.' minutos para resolver questões e consolidar o conteúdo de '.$referenceModuleName.'.'
                    : 'Reserva de até '.$plannedMinutes.' minutos para resolver questões e medir retenção.',
            ];
        }

        return [
            null,
            'Bloco '.$blockNumber.' · Bloco complementar',
            'Reserva de até '.$plannedMinutes.' minutos para reforçar o conteúdo mais relevante da sua trilha.',
        ];
    }

    protected function makeItemTitle(string $type, string $moduleName, int $blockNumber): string
    {
        return match ($type) {
            'basic' => 'Bloco '.$blockNumber.' · Matéria Básica: '.$moduleName,
            'specific' => 'Bloco '.$blockNumber.' · Conhecimentos Específicos: '.$moduleName,
            'complementary' => 'Bloco '.$blockNumber.' · Conhecimentos Complementares: '.$moduleName,
            'review' => 'Bloco '.$blockNumber.' · Revisão: '.$moduleName,
            'questions' => 'Bloco '.$blockNumber.' · Resolução de Questões: '.$moduleName,
            default => 'Bloco '.$blockNumber.' · '.$moduleName,
        };
    }

    protected function makeItemDescription(string $type, string $moduleName, string $dayKey, int $estimatedMinutes, array $lessonNames = []): string
    {
        $durationLabel = $estimatedMinutes.' minutos';
        $lessonLabel = $this->summarizeLessonNames($lessonNames);
        $lessonSuffix = $lessonLabel ? ' Aulas do bloco: '.$lessonLabel.'.' : '';

        return match ($type) {
            'basic' => 'Bloco de até '.$durationLabel.' para estudar '.$moduleName.'.'.$lessonSuffix,
            'specific' => 'Bloco de até '.$durationLabel.' para avançar em conhecimentos específicos com foco em '.$moduleName.'.'.$lessonSuffix,
            'complementary' => 'Bloco de até '.$durationLabel.' para avançar em conhecimentos complementares com foco em '.$moduleName.'.'.$lessonSuffix,
            'review' => $dayKey === 'saturday'
                ? 'Sábado de revisão com reserva de até '.$durationLabel.' para retomar pontos-chave de '.$moduleName.' com foco em fixação.'
                : 'Bloco de revisão com reserva de até '.$durationLabel.' para reforçar a retenção em '.$moduleName.'.',
            'questions' => 'Bloco de resolução de questões com reserva de até '.$durationLabel.' para aplicar na prática o conteúdo de '.$moduleName.'.',
            default => 'Bloco de estudo planejado para manter consistência até a prova.',
        };
    }

    protected function summarizeLessonNames(array $lessonNames): string
    {
        $lessonNames = array_values(array_filter(array_map(fn ($name) => trim((string) $name), $lessonNames)));

        return match (count($lessonNames)) {
            0 => '',
            1 => $lessonNames[0],
            2 => $lessonNames[0].' e '.$lessonNames[1],
            default => implode(', ', array_slice($lessonNames, 0, -1)).' e '.$lessonNames[array_key_last($lessonNames)],
        };
    }

    protected function normalizeModuleType(?string $type): string
    {
        return match ($type) {
            'other' => 'complementary',
            default => $type ?: 'complementary',
        };
    }

    protected function moduleSubject(CourseModule $module): string
    {
        $name = trim($module->name);
        $subject = Str::before($name, ' - ');

        return Str::of($subject !== '' ? $subject : $name)
            ->lower()
            ->ascii()
            ->squish()
            ->value();
    }
}
