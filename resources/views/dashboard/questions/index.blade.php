<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel">
            <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Banco de questões</p>
            <h1 class="mt-2 text-3xl font-semibold text-white">Pratique por assunto.</h1>
            <p class="mt-3 max-w-2xl text-sm text-slate-300">Resolva questões dos cursos liberados e acompanhe seu desempenho.</p>
        </div>
    </x-slot>

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($banks as $bank)
            <article class="card-panel">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-300">
                            {{ $bank->modules->pluck('name')->merge($bank->tracks->pluck('name'))->merge($bank->lessons->pluck('title'))->take(2)->join(' / ') ?: 'Geral' }}
                        </p>
                        <h2 class="mt-2 text-xl font-semibold text-white">{{ $bank->title }}</h2>
                        <p class="mt-3 text-sm text-slate-400">{{ $bank->questions_count }} questão(ões) disponíveis</p>
                    </div>
                    <a href="{{ route('questions.show', $bank) }}" class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-center text-sm font-semibold text-sky-100">
                        Resolver
                    </a>
                </div>
            </article>
        @empty
            <div class="card-panel">
                <p class="text-sm text-slate-300">Ainda não há bancos de questões publicados para os seus cursos.</p>
            </div>
        @endforelse
    </div>
</x-app-layout>
