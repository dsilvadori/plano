<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm uppercase tracking-[0.25em] text-amber-300">{{ $studyPlan ? 'Editar plano' : 'Criador de plano' }}</p>
            <h1 class="mt-2 text-3xl font-semibold text-white">{{ $studyPlan ? 'Ajuste seu plano sem perder o rumo.' : 'Plano ajustado à sua realidade.' }}</h1>
        </div>
    </x-slot>

    <div class="card-panel">
        <livewire:study-plan-builder :study-plan="$studyPlan" :old-input="session()->getOldInput()" :key="$builderKey" />
    </div>
</x-app-layout>
