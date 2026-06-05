<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">{{ $user->isAdmin() ? 'Área de estudos do admin' : 'Página inicial do aluno' }}</p>
                @if ($activePlan)
                    <h1 class="mt-2 text-3xl font-semibold text-white">Seu plano está pronto. Agora é execução.</h1>
                    <p class="mt-3 max-w-2xl text-sm text-slate-300">Hoje é dia de avançar mais um bloco. Você não precisa estudar tudo hoje. Precisa cumprir o ciclo.</p>
                @else
                    <h1 class="mt-2 text-3xl font-semibold text-white">Vamos montar um plano que caiba na sua rotina.</h1>
                    <p class="mt-3 max-w-2xl text-sm text-slate-300">Você não precisa vencer o edital inteiro hoje. Precisa começar com um ciclo realista.</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('study-plans.create') }}" class="rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">Criar plano</a>
                @if ($activePlan)
                    <a href="{{ route('study-plans.show', $activePlan) }}" class="rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-sm font-semibold text-slate-100">Continuar plano</a>
                    <form method="POST" action="{{ route('study-plans.rebalance', $activePlan) }}" onsubmit="return confirm('Deseja reajustar o plano mantendo o progresso já concluído e recalculando o restante a partir de hoje?');">
                        @csrf
                        <button type="submit" class="rounded-2xl border border-amber-400/20 bg-amber-400/10 px-5 py-3 text-sm font-semibold text-amber-100">
                            Reajustar mantendo progresso
                        </button>
                    </form>
                    <form method="POST" action="{{ route('study-plans.destroy', $activePlan) }}" onsubmit="return confirm('Deseja apagar o plano atual? Esta ação não pode ser desfeita.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-2xl border border-rose-400/20 bg-rose-400/10 px-5 py-3 text-sm font-semibold text-rose-100">
                            Apagar plano atual
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-4">
        <section class="card-panel lg:col-span-3">
            @if (session('status'))
                <div class="mb-6 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="metric-card">
                    <p class="stat-label">Tarefas hoje</p>
                    <p class="mt-3 stat-value">{{ $activePlan?->items->where('scheduled_date', now()->toDateString())->count() ?? 0 }}</p>
                </div>
                <div class="metric-card">
                    <p class="stat-label">Tarefas semana</p>
                    <p class="mt-3 stat-value">{{ $activePlan?->items->whereBetween('scheduled_date', [$weekStart, $weekEnd])->count() ?? 0 }}</p>
                </div>
                <div class="metric-card">
                    <p class="stat-label">Progresso</p>
                    <p class="mt-3 stat-value">{{ $activePlan?->progress_percentage ?? 0 }}%</p>
                </div>
                <div class="metric-card">
                    <p class="stat-label">Dias até prova</p>
                    <p class="mt-3 stat-value">{{ $activePlan?->days_until_exam_label ?? 'Sem previsão' }}</p>
                </div>
            </div>

            <div class="mt-6 grid gap-6 lg:grid-cols-[1.4fr_1fr]">
                <div class="glass-highlight">
                    <p class="text-sm uppercase tracking-[0.25em] text-slate-400">Plano ativo</p>
                    @if ($activePlan)
                        <h2 class="mt-3 text-2xl font-semibold text-white">{{ $activePlan->name }}</h2>
                        <p class="mt-2 text-sm text-slate-300">Curso: {{ $activePlan->course->name }}</p>
                        <p class="mt-1 text-sm text-slate-400">Plano ajustado à sua realidade até {{ $activePlan->exam_date_label }}, com blocos de até 60 minutos.</p>
                        @php
                            $activeCompletedMinutes = (int) $activePlan->items->whereNotNull('completed_at')->sum('estimated_minutes');
                            $activePendingMinutes = max(0, (int) $activePlan->total_required_minutes - $activeCompletedMinutes);
                            $activeTypeMinutes = [
                                'Matérias Básicas' => (int) $activePlan->items->where('type', 'basic')->sum('estimated_minutes'),
                                'Conhecimentos Específicos' => (int) $activePlan->items->where('type', 'specific')->sum('estimated_minutes'),
                                'Revisões' => (int) $activePlan->items->where('type', 'review')->sum('estimated_minutes'),
                                'Questões' => (int) $activePlan->items->where('type', 'questions')->sum('estimated_minutes'),
                            ];
                            $activeTypeTotal = max(1, array_sum($activeTypeMinutes));
                        @endphp
                        <div class="mt-4 rounded-2xl border border-white/10 bg-slate-950/55 p-4">
                            <p class="text-sm text-slate-200">
                                @if (($activePlan->progress_percentage ?? 0) >= 70)
                                    Você já construiu um ótimo ritmo. Agora a missão é manter consistência até a prova.
                                @elseif (($activePlan->progress_percentage ?? 0) >= 30)
                                    Seu plano já está ganhando corpo. Continue bloco por bloco, sem tentar fazer tudo de uma vez.
                                @else
                                    O começo é sobre criar tração. Cumprir os primeiros blocos já muda o jogo.
                                @endif
                            </p>
                        </div>
                        <div class="mt-5 h-3 overflow-hidden rounded-full bg-slate-800">
                            <div class="h-full rounded-full bg-amber-300" style="width: {{ min(100, $activePlan->progress_percentage) }}%"></div>
                        </div>
                        <div class="mt-5 grid gap-3 lg:grid-cols-[1.2fr_1fr]">
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Estado atual do ciclo</p>
                                <div class="mt-4 space-y-3">
                                    <div>
                                        <div class="mb-2 flex items-center justify-between text-sm text-slate-300">
                                            <span>Concluído</span>
                                            <span>{{ \App\Support\StudyTime::formatMinutes($activeCompletedMinutes) }}</span>
                                        </div>
                                        <div class="h-3 overflow-hidden rounded-full bg-slate-800">
                                            <div class="h-full rounded-full bg-emerald-400" style="width: {{ min(100, $activePlan->progress_percentage) }}%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="mb-2 flex items-center justify-between text-sm text-slate-300">
                                            <span>Pendente</span>
                                            <span>{{ \App\Support\StudyTime::formatMinutes($activePendingMinutes) }}</span>
                                        </div>
                                        <div class="h-3 overflow-hidden rounded-full bg-slate-800">
                                            <div class="h-full rounded-full bg-slate-500" style="width: {{ max(0, 100 - min(100, $activePlan->progress_percentage)) }}%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Distribuição da carga</p>
                                <div class="mt-4 space-y-3">
                                    @foreach ($activeTypeMinutes as $label => $minutes)
                                        @continue($minutes === 0)
                                        <div>
                                            <div class="mb-2 flex items-center justify-between text-sm text-slate-300">
                                                <span>{{ $label }}</span>
                                                <span>{{ \App\Support\StudyTime::formatMinutes($minutes) }}</span>
                                            </div>
                                            <div class="h-3 overflow-hidden rounded-full bg-slate-800">
                                                <div class="h-full rounded-full bg-gradient-to-r from-sky-400 via-violet-400 to-amber-300" style="width: {{ round(($minutes / $activeTypeTotal) * 100) }}%"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Viabilidade</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ $activePlan->viability_label }}</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Carga concluída</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ $activePlan->completed_hours_minutes }}</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Carga total</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ $activePlan->required_hours_minutes }}</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Tempo em revisão</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ $activePlan->weekly_review_hours_minutes }}</p>
                                <p class="mt-2 text-xs text-slate-400">Espaço para consolidar antes de seguir acelerando.</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Tempo em questões</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ $activePlan->weekly_questions_hours_minutes }}</p>
                                <p class="mt-2 text-xs text-slate-400">Prática para transformar estudo em resultado.</p>
                            </div>
                        </div>
                    @else
                        <h2 class="mt-3 text-2xl font-semibold text-white">Nenhum plano ativo</h2>
                        <p class="mt-2 text-sm text-slate-300">Crie seu primeiro ciclo e transforme sua disponibilidade em execução consistente.</p>
                    @endif
                </div>

                <div class="card-subtle">
                    <p class="text-sm uppercase tracking-[0.25em] text-slate-400">Cursos liberados</p>
                    <h2 class="mt-3 text-xl font-semibold text-white">{{ $availableCourses->count() }} curso(s) disponível(is)</h2>
                    <p class="mt-2 text-sm text-slate-300">Você pode montar um plano separado para cada curso em que estiver matriculado.</p>
                    <div class="mt-4 space-y-3">
                        @forelse ($availableCourses as $course)
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="text-sm font-semibold text-white">{{ $course->name }}</p>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="text-sm text-slate-300">Nenhum curso vinculado no momento.</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            @if ($activePlans->isNotEmpty())
                <div class="mt-6">
                    <p class="text-sm uppercase tracking-[0.25em] text-slate-400">Seus planos ativos</p>
                    <div class="mt-4 grid gap-4 xl:grid-cols-2">
                        @foreach ($activePlans as $plan)
                            <div class="rounded-[1.6rem] border border-white/10 bg-slate-950/55 p-5">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs uppercase tracking-[0.2em] text-amber-300">{{ $plan->course->name }}</p>
                                        <h3 class="mt-2 text-lg font-semibold text-white">{{ $plan->name }}</h3>
                                        <p class="mt-2 text-sm text-slate-400">Prova: {{ $plan->exam_date_label }} • {{ $plan->viability_label }}</p>
                                    </div>
                                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-slate-200">{{ $plan->progress_percentage }}%</span>
                                </div>
                                <div class="mt-4 flex gap-3">
                                    <a href="{{ route('study-plans.show', $plan) }}" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-100">Abrir plano</a>
                                    <form method="POST" action="{{ route('study-plans.rebalance', $plan) }}" onsubmit="return confirm('Deseja reajustar este plano mantendo o progresso já concluído e recalculando o restante a partir de hoje?');">
                                        @csrf
                                        <button type="submit" class="rounded-2xl border border-amber-400/20 bg-amber-400/10 px-4 py-3 text-sm font-semibold text-amber-100">Reajustar</button>
                                    </form>
                                    <form method="POST" action="{{ route('study-plans.rebalance', $plan) }}" onsubmit="return confirm('Deseja editar este plano por meio de um reajuste automático, mantendo o progresso já concluído?');">
                                        @csrf
                                        <button type="submit" class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">Editar plano</button>
                                    </form>
                                    <form method="POST" action="{{ route('study-plans.destroy', $plan) }}" onsubmit="return confirm('Deseja apagar este plano?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-2xl border border-rose-400/20 bg-rose-400/10 px-4 py-3 text-sm font-semibold text-rose-100">Apagar</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        <aside class="card-panel">
            <p class="text-sm uppercase tracking-[0.25em] text-slate-400">Próximas tarefas</p>
            <div class="mt-4 space-y-3">
                @forelse ($nextTasks as $task)
                    <div class="card-subtle">
                        <p class="text-sm font-semibold text-white">{{ $task->title }}</p>
                        <p class="mt-1 text-xs uppercase tracking-[0.2em] text-amber-300">{{ $task->scheduled_date->format('d/m') }} • {{ $task->estimated_minutes }} min</p>
                        <p class="mt-2 text-sm text-slate-400">{{ $task->description }}</p>
                    </div>
                @empty
                    <div class="card-subtle">
                        <p class="text-sm text-slate-300">Sem tarefas futuras no momento. Crie ou atualize seu plano para continuar.</p>
                    </div>
                @endforelse
            </div>
        </aside>
    </div>
</x-app-layout>
