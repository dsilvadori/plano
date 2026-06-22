@php
    $dayLabels = [
        'monday' => 'Segunda-feira',
        'tuesday' => 'Terça-feira',
        'wednesday' => 'Quarta-feira',
        'thursday' => 'Quinta-feira',
        'friday' => 'Sexta-feira',
        'saturday' => 'Sábado',
        'sunday' => 'Domingo',
    ];

    $typeLabels = [
        'basic' => 'Matéria Básica',
        'specific' => 'Conhecimentos Específicos',
        'complementary' => 'Conhecimentos Complementares',
        'review' => 'Revisão',
        'questions' => 'Resolução de Questões',
        'other' => 'Complementar',
    ];

    $typeBadgeClasses = [
        'basic' => 'badge-basic',
        'specific' => 'badge-specific',
        'complementary' => 'badge-other',
        'review' => 'badge-review',
        'questions' => 'badge-questions',
        'other' => 'badge-other',
    ];
@endphp

<div class="space-y-6">
    <section class="space-y-6">
        <div class="card-panel">
            <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                <div class="max-w-2xl">
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Visão geral do plano</p>
                    <h3 class="mt-2 text-2xl font-semibold text-white">Seu progresso fica claro em um só lugar.</h3>
                    <p class="mt-3 text-sm text-slate-300">A cada tarefa concluída, este painel atualiza automaticamente para mostrar o avanço real do ciclo, o que já foi consolidado e o que ainda pede atenção.</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-100">{{ $studyPlan->course->name }}</span>
                        <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-100">Prova: {{ $studyPlan->exam_date_label }}</span>
                        <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-100">Dias restantes: {{ $studyPlan->days_until_exam_label }}</span>
                        <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-100">Viabilidade: {{ $studyPlan->viability_label }}</span>
                        <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-100">Carga do curso: {{ $studyPlan->required_hours_minutes }}</span>
                    </div>
                    <div class="mt-5 h-4 overflow-hidden rounded-full bg-slate-900/90">
                        <div class="progress-glow h-full rounded-full bg-gradient-to-r from-emerald-400 via-amber-300 to-sky-400 transition-all duration-500" style="width: {{ min(100, $overviewSummary['completion_percentage']) }}%"></div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-3 text-sm text-slate-300">
                        <span>{{ $overviewSummary['tasks_completed'] }} tarefa(s) concluída(s)</span>
                        <span class="text-slate-500">•</span>
                        <span>{{ $overviewSummary['tasks_pending'] }} pendente(s)</span>
                        <span class="text-slate-500">•</span>
                        <span>{{ \App\Support\StudyTime::formatMinutes($overviewSummary['minutes_completed']) }} estudados</span>
                    </div>
                </div>

                <div class="progress-ring shrink-0 self-center" style="--progress: {{ min(100, $overviewSummary['completion_percentage']) }}%">
                    <div class="progress-ring-inner">
                        <span class="text-3xl font-semibold text-white">{{ $overviewSummary['completion_percentage'] }}%</span>
                        <span class="mt-1 text-xs uppercase tracking-[0.2em] text-slate-400">Concluído</span>
                    </div>
                </div>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="metric-card">
                    <p class="stat-label">Tarefas totais</p>
                    <p class="mt-3 stat-value">{{ $overviewSummary['tasks_total'] }}</p>
                </div>
                <div class="metric-card">
                    <p class="stat-label">Concluídas</p>
                    <p class="mt-3 stat-value">{{ $overviewSummary['tasks_completed'] }}</p>
                </div>
                <div class="metric-card">
                    <p class="stat-label">Carga concluída</p>
                    <p class="mt-3 stat-value">{{ \App\Support\StudyTime::formatMinutes($overviewSummary['minutes_completed']) }}</p>
                </div>
                    <div class="metric-card">
                        <p class="stat-label">Carga pendente</p>
                        <p class="mt-3 stat-value">{{ \App\Support\StudyTime::formatMinutes($overviewSummary['minutes_pending']) }}</p>
                    </div>
                    <div class="metric-card">
                        <p class="stat-label">Tempo em revisão</p>
                        <p class="mt-3 stat-value">{{ $studyPlan->weekly_review_hours_minutes }}</p>
                    </div>
                    <div class="metric-card">
                        <p class="stat-label">Tempo em questões</p>
                        <p class="mt-3 stat-value">{{ $studyPlan->weekly_questions_hours_minutes }}</p>
                    </div>
                </div>

            <div class="mt-6 grid gap-4 xl:grid-cols-2">
                @foreach ($typeOverview as $typeStat)
                    <div class="card-subtle">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-white">{{ $typeStat['label'] }}</p>
                                <p class="mt-1 text-xs text-slate-400">{{ $typeStat['completed_tasks'] }} de {{ $typeStat['tasks'] }} tarefas • {{ $typeStat['minutes_label'] }}</p>
                            </div>
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-slate-100">{{ $typeStat['progress'] }}%</span>
                        </div>
                        <div class="mt-4 h-3 overflow-hidden rounded-full bg-slate-900/90">
                            <div class="h-full rounded-full transition-all duration-500 {{ $typeBadgeClasses[$typeStat['key']] ?? $typeBadgeClasses['other'] }}" style="width: {{ min(100, $typeStat['progress']) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card-panel">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Semana selecionada</p>
                    <h3 class="mt-2 text-2xl font-semibold text-white">Visualize uma semana por vez para focar na execução.</h3>
                    @if ($selectedWeekRange)
                        <p class="mt-2 text-sm text-slate-400">Período da semana: {{ $selectedWeekRange }}.</p>
                    @endif
                    <div class="mt-4 rounded-2xl border border-sky-400/15 bg-sky-400/10 p-4">
                        <p class="text-sm font-medium text-sky-100">{{ $weeklyFocusMessage }}</p>
                        <p class="mt-2 text-sm text-slate-300">{{ $weeklyBreakdownMessage }}</p>
                    </div>
                </div>
                <div class="grid gap-2 md:grid-cols-3">
                    @foreach ($availableWeeks as $weekNumber)
                        <label class="cursor-pointer select-none rounded-2xl border px-4 py-3 text-sm font-semibold transition {{ $selectedWeek === $weekNumber ? 'border-amber-300/40 bg-amber-300 text-slate-950' : 'border-white/10 bg-white/5 text-slate-200' }}">
                            <input wire:click="selectWeek({{ $weekNumber }})" type="radio" name="week_selector" class="sr-only" {{ $selectedWeek === $weekNumber ? 'checked' : '' }}>
                            Semana {{ $weekNumber }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-4">
                <div class="metric-card">
                    <p class="stat-label">Tarefas</p>
                    <p class="mt-3 stat-value">{{ $weeklySummary['tasks'] }}</p>
                </div>
                <div class="metric-card">
                    <p class="stat-label">Tempo total</p>
                    <p class="mt-3 stat-value">{{ \App\Support\StudyTime::formatMinutes($weeklySummary['total_minutes']) }}</p>
                </div>
                <div class="metric-card">
                    <p class="stat-label">Revisões</p>
                    <p class="mt-3 stat-value">{{ \App\Support\StudyTime::formatMinutes($weeklySummary['review_minutes']) }}</p>
                    <p class="mt-2 text-xs text-slate-400">Momento de respirar, retomar e fixar o que você estudou.</p>
                </div>
                <div class="metric-card">
                    <p class="stat-label">Resolução de questões</p>
                    <p class="mt-3 stat-value">{{ \App\Support\StudyTime::formatMinutes($weeklySummary['questions_minutes']) }}</p>
                    <p class="mt-2 text-xs text-slate-400">Momento de colocar a memória para trabalhar e ganhar ritmo.</p>
                </div>
            </div>

            @if ($studyPlan->viability_message)
                <div class="mt-5 rounded-2xl border border-amber-400/20 bg-amber-400/10 p-4">
                    <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Orientação do plano</p>
                    <p class="mt-2 text-sm text-amber-100">{{ $studyPlan->viability_message }}</p>
                </div>
            @endif
        </div>

        <div class="card-panel">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Semana {{ $selectedWeek }}</p>
                    <h3 class="mt-2 text-2xl font-semibold text-white">Hoje é dia de cumprir o ciclo.</h3>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-200">
                    {{ collect($selectedWeekItems)->flatten()->count() }} tarefas
                </div>
            </div>

            <div class="mt-6 space-y-4">
                @foreach ($selectedWeekItems as $date => $items)
                    <div class="card-subtle">
                        <div class="flex items-center justify-between">
                            <h4 class="text-lg font-semibold text-white">{{ $date }}</h4>
                            <p class="text-sm text-slate-400">{{ $dayLabels[$items->first()->day_of_week] ?? $items->first()->day_of_week }}</p>
                        </div>

                        <div class="mt-4 grid gap-3 xl:grid-cols-2">
                            @foreach ($items as $item)
                                <div class="rounded-2xl border border-white/10 bg-slate-950/70 p-4">
                                    <div class="flex h-full flex-col justify-between gap-4">
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-200">Bloco {{ $loop->iteration }}</span>
                                                <span class="badge-chip {{ $typeBadgeClasses[$item->type] ?? $typeBadgeClasses['other'] }}">{{ $typeLabels[$item->type] ?? $item->type }}</span>
                                                <span class="rounded-full border border-amber-400/20 bg-amber-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-amber-200">{{ $item->estimated_minutes }} min</span>
                                            </div>
                                            <p class="mt-3 text-sm font-semibold text-white">{{ $item->display_title }}</p>
                                            @if (! empty($itemLessons[$item->id]))
                                                <div class="mt-3 space-y-2">
                                                    @foreach ($itemLessons[$item->id] as $lesson)
                                                        <div class="flex items-start justify-between gap-3 rounded-xl border border-white/10 bg-white/[0.03] px-3 py-2">
                                                            @if (! empty($lesson['url']))
                                                                <a href="{{ $lesson['url'] }}" class="text-sm font-semibold leading-5 text-sky-100 underline-offset-4 hover:underline">
                                                                    {{ $lesson['name'] }}
                                                                </a>
                                                            @else
                                                                <p class="text-sm leading-5 text-slate-300">{{ $lesson['name'] }}</p>
                                                            @endif
                                                            <span class="shrink-0 rounded-full border border-white/10 bg-slate-900/80 px-2.5 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-slate-200">{{ $lesson['minutes_label'] }}</span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @else
                                                <p class="mt-2 text-sm text-slate-400">{{ $item->display_description }}</p>
                                            @endif
                                        </div>
                                        <div class="mt-auto flex justify-end pt-2">
                                            <livewire:toggle-study-plan-item :item="$item" :key="$item->id" />
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</div>
