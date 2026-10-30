<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Meus planos</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">Planos de estudo salvos.</h1>
                <p class="mt-3 max-w-2xl text-sm text-slate-300">Acompanhe seus ciclos ativos e retome o plano certo sem poluir o menu lateral.</p>
            </div>
            @if ($canCreatePlan)
                <a href="{{ route('study-plans.create') }}" class="rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">
                    Criar plano
                </a>
            @endif
        </div>
    </x-slot>

    <section class="card-panel">
        <div class="grid gap-4 xl:grid-cols-2">
            @forelse ($plans as $plan)
                <div class="rounded-[1.6rem] border border-white/10 bg-slate-950/55 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-amber-300">{{ $plan->course?->name ?? 'Curso não vinculado' }}</p>
                            <h2 class="mt-2 text-xl font-semibold text-white">{{ $plan->name }}</h2>
                            <p class="mt-2 text-sm text-slate-400">Prova: {{ $plan->exam_date_label }} · {{ $plan->viability_label }}</p>
                        </div>
                        <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-slate-200">{{ $plan->progress_percentage }}%</span>
                    </div>

                    <div class="mt-4 h-3 overflow-hidden rounded-full bg-slate-800">
                        <div class="h-full rounded-full bg-amber-300" style="width: {{ min(100, $plan->progress_percentage) }}%"></div>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-3">
                        <a href="{{ route('study-plans.show', $plan) }}" class="rounded-2xl bg-amber-300 px-4 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">Continuar plano</a>
                        <a href="{{ route('study-plans.edit', $plan) }}" class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">Editar</a>
                    </div>
                </div>
            @empty
                <div class="card-subtle xl:col-span-2">
                    <h2 class="text-xl font-semibold text-white">Nenhum plano criado ainda.</h2>
                    <p class="mt-2 text-sm text-slate-300">Crie um plano para transformar seus cursos liberados em uma rotina de estudo.</p>
                </div>
            @endforelse
        </div>
    </section>
</x-app-layout>
