<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Visualização do plano</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">Cada tarefa concluída aproxima você da aprovação.</h1>
            </div>
            <div class="flex flex-wrap gap-3">
                <form method="POST" action="{{ route('study-plans.rebalance', $studyPlan) }}" onsubmit="return confirm('Deseja reajustar o plano mantendo o progresso já concluído e recalculando o restante a partir de hoje?');">
                    @csrf
                    <button type="submit" class="rounded-2xl border border-amber-400/20 bg-amber-400/10 px-5 py-3 text-sm font-semibold text-amber-100">
                        Reajustar mantendo progresso
                    </button>
                </form>
                <form method="POST" action="{{ route('study-plans.rebalance', $studyPlan) }}" onsubmit="return confirm('Deseja editar o plano por meio de um reajuste automático, mantendo o progresso já concluído?');">
                    @csrf
                    <button type="submit" class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-5 py-3 text-sm font-semibold text-sky-100">
                        Editar plano
                    </button>
                </form>
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

    <livewire:study-plan-viewer :study-plan="$studyPlan" />
</x-app-layout>
