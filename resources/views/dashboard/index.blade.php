<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Plano de Estudos</p>
                @if ($activePlan)
                    <h1 class="mt-2 text-3xl font-semibold text-white">Seu plano está pronto. Agora é execução.</h1>
                    <p class="mt-3 max-w-2xl text-sm text-slate-300">Hoje é dia de avançar mais um bloco. Você não precisa estudar tudo hoje. Precisa cumprir o ciclo.</p>
                @else
                    <h1 class="mt-2 text-3xl font-semibold text-white">Crie seu plano de estudos em poucos passos.</h1>
                    <p class="mt-3 max-w-2xl text-sm text-slate-300">Escolha um curso liberado, informe sua rotina e acompanhe aulas, revisões e questões em um ciclo realista.</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-3">
                @if ($activePlan)
                    <a href="{{ route('study-plans.show', $activePlan) }}" class="rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">Continuar plano</a>
                    <a href="{{ route('study-plans.edit', $activePlan) }}" class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-5 py-3 text-sm font-semibold text-sky-100">Editar plano</a>
                    @if ($canCreatePlan)
                        <a href="{{ route('study-plans.create') }}" class="rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-sm font-semibold text-slate-100">Criar outro plano</a>
                    @endif
                    <form method="POST" action="{{ route('study-plans.destroy', $activePlan) }}" onsubmit="return confirm('Deseja apagar o plano atual? Esta ação não pode ser desfeita.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-2xl border border-rose-400/20 bg-rose-400/10 px-5 py-3 text-sm font-semibold text-rose-100">
                            Apagar plano atual
                        </button>
                    </form>
                @elseif ($canCreatePlan)
                    <a href="{{ route('study-plans.create') }}" class="rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">Criar plano</a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-4">
        <section class="space-y-6 lg:col-span-3">
            @if (session('status'))
                <div class="mb-6 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            @if ($canCreatePlan)
                <section class="card-panel border-amber-300/25 bg-amber-300/10">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Comece pelo plano</p>
                            <h2 class="mt-2 text-2xl font-semibold text-white">Monte um ciclo para um dos seus cursos.</h2>
                            <p class="mt-2 max-w-2xl text-sm text-amber-100">O plano organiza sua disponibilidade em blocos de estudo, revisões e questões para você saber exatamente o que fazer a cada dia.</p>
                        </div>
                        <a href="{{ route('study-plans.create') }}" class="inline-flex shrink-0 justify-center rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">
                            Criar plano de estudos
                        </a>
                    </div>
                </section>
            @endif

            @if ($catalogCourses->isNotEmpty())
                <section class="card-panel">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Catálogo de cursos</p>
                            <h2 class="mt-2 text-2xl font-semibold text-white">Todos os cursos publicados</h2>
                            <p class="mt-2 text-sm text-slate-300">Seus cursos aparecem primeiro no catálogo.</p>
                        </div>
                        <a href="{{ route('courses.index') }}" class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">
                            Ver catálogo
                        </a>
                    </div>

                    @include('dashboard.courses.partials.course-carousel', ['courses' => $catalogCourses, 'accessibleCourseIds' => $availableCourseIds, 'courseProgress' => $courseProgress, 'activePlansByCourse' => $activePlansByCourse, 'showOwnedBadge' => true])
                </section>
            @endif

            <section class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
                <div class="glass-highlight">
                    <p class="text-sm uppercase tracking-[0.25em] text-slate-400">Plano ativo</p>
                    @if ($activePlan)
                        <h2 class="mt-3 text-2xl font-semibold text-white">{{ $activePlan->name }}</h2>
                        <p class="mt-2 text-sm text-slate-300">Curso: {{ $activePlan->course->name }}</p>
                        <p class="mt-1 text-sm text-slate-400">Plano ajustado à sua realidade até {{ $activePlan->exam_date_label }}, com blocos de até 60 minutos.</p>
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
                        <div class="mt-5 flex flex-wrap gap-3 text-sm text-slate-300">
                            <span class="rounded-full border border-white/10 bg-slate-950/60 px-4 py-2">{{ $activePlan->progress_percentage }}% concluído</span>
                            <span class="rounded-full border border-white/10 bg-slate-950/60 px-4 py-2">Prova: {{ $activePlan->exam_date_label }}</span>
                            <span class="rounded-full border border-white/10 bg-slate-950/60 px-4 py-2">Hoje: {{ $activePlan->items->where('scheduled_date', now()->toDateString())->count() }} tarefa(s)</span>
                        </div>
                        @if ($activePlan->viability_message)
                            <div class="mt-5 rounded-2xl border border-amber-400/20 bg-amber-400/10 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Orientação do plano</p>
                                <p class="mt-2 text-sm text-amber-100">{{ $activePlan->viability_message }}</p>
                            </div>
                        @endif
                    @else
                        <h2 class="mt-3 text-2xl font-semibold text-white">Nenhum plano ativo</h2>
                        <p class="mt-2 text-sm text-slate-300">Crie seu primeiro ciclo e transforme sua disponibilidade em execução consistente.</p>
                    @endif
                </div>

                <div class="card-subtle">
                    <p class="text-sm uppercase tracking-[0.25em] text-slate-400">Cursos liberados</p>
                    <h2 class="mt-3 text-xl font-semibold text-white">{{ $availableCourses->count() }} {{ \Illuminate\Support\Str::plural('curso disponível', $availableCourses->count()) }}</h2>
                    <p class="mt-2 text-sm text-slate-300">Você pode montar um plano separado para cada curso em que estiver matriculado.</p>
                    <div class="mt-4 space-y-3">
                        @forelse ($availableCourses as $course)
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="text-sm font-semibold text-white">{{ $course->name }}</p>
                                @if ($activePlansByCourse->has($course->id))
                                    <a href="{{ route('study-plans.show', $activePlansByCourse->get($course->id)) }}" class="mt-3 inline-flex rounded-xl border border-amber-300/20 bg-amber-300/10 px-3 py-2 text-xs font-semibold text-amber-100">
                                        Acessar plano
                                    </a>
                                @else
                                    <a href="{{ route('study-plans.create') }}" class="mt-3 inline-flex rounded-xl border border-sky-400/20 bg-sky-400/10 px-3 py-2 text-xs font-semibold text-sky-100">
                                        Criar plano
                                    </a>
                                @endif
                            </div>
                        @empty
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="text-sm text-slate-300">Nenhum curso vinculado no momento.</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </section>

            @if ($activePlans->isNotEmpty())
                <section class="card-panel">
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
                                    <a href="{{ route('study-plans.edit', $plan) }}" class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">Editar plano</a>
                                    <form method="POST" action="{{ route('study-plans.destroy', $plan) }}" onsubmit="return confirm('Deseja apagar este plano?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-2xl border border-rose-400/20 bg-rose-400/10 px-4 py-3 text-sm font-semibold text-rose-100">Apagar</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
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
