<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Visualização do plano</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">Cada tarefa concluída aproxima você da aprovação.</h1>
                @unless ($studyPlan->lessons_reprocessed_at)
                    <p class="mt-3 max-w-3xl text-sm text-slate-300">
                        Este curso pode ter recebido atualizações de aulas. Clique em Reprocessar aulas para atualizar seu plano de estudos sem perder o progresso já concluído.
                    </p>
                @endunless
            </div>
            <div class="flex flex-wrap gap-3">
                @unless ($studyPlan->lessons_reprocessed_at)
                    <form method="POST" action="{{ route('study-plans.sync-lessons', $studyPlan) }}">
                        @csrf
                        <button type="submit" class="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-5 py-3 text-sm font-semibold text-emerald-100">
                            Reprocessar aulas
                        </button>
                    </form>
                @endunless
                <a href="{{ route('study-plans.edit', $studyPlan) }}" class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-5 py-3 text-sm font-semibold text-sky-100">
                    Editar plano
                </a>
                <form method="POST" action="{{ route('study-plans.destroy', $studyPlan) }}" onsubmit="return confirm('Deseja apagar este plano e começar outro?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-2xl border border-rose-400/20 bg-rose-400/10 px-5 py-3 text-sm font-semibold text-rose-100">
                        Apagar este plano
                    </button>
                </form>
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="mx-auto mb-6 max-w-7xl px-4 sm:px-6 lg:px-8">
            <p class="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm font-semibold text-emerald-100">
                {{ session('status') }}
            </p>
        </div>
    @endif

    <livewire:study-plan-viewer :study-plan="$studyPlan" />
</x-app-layout>
