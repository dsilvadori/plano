@php
    $playerUrl = $lesson->player_url;
    $isCompleted = $progress->status === 'completed';
    $progressPercentage = (int) ($progressSummary['percentage'] ?? 0);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">{{ $lesson->module?->name ?: 'Aula' }}</p>
                    <h1 class="mt-2 max-w-4xl text-3xl font-semibold text-white">{{ $lesson->title }}</h1>
                    <p class="mt-3 max-w-2xl text-sm text-slate-300">{{ $course->name }}</p>
                </div>
                <a href="{{ route('courses.show', $course->slug) }}" class="rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-sm font-semibold text-slate-100">
                    Voltar ao curso
                </a>
            </div>

            <div class="mt-6 rounded-2xl border border-sky-400/20 bg-sky-400/10 p-4">
                <div class="mb-2 flex items-center justify-between text-sm font-semibold text-sky-100">
                    <span>{{ $progressSummary['completed'] }} de {{ $progressSummary['total'] }} aula(s) concluída(s)</span>
                    <span>{{ $progressPercentage }}%</span>
                </div>
                <div class="h-3 overflow-hidden rounded-full bg-slate-800">
                    <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $progressPercentage) }}%"></div>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <section class="card-panel">
            @if (session('status'))
                <div class="mb-6 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-950">
                @if ($playerUrl && in_array($lesson->type, ['video', 'mixed'], true))
                    <iframe
                        src="{{ $playerUrl }}"
                        title="{{ $lesson->title }}"
                        class="aspect-video w-full"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen
                    ></iframe>
                @else
                    <div class="flex aspect-video flex-col items-center justify-center p-8 text-center">
                        <p class="text-sm uppercase tracking-[0.25em] text-amber-300">{{ ucfirst($lesson->type) }}</p>
                        <h2 class="mt-3 text-2xl font-semibold text-white">Conteúdo em preparação</h2>
                        <p class="mt-3 max-w-lg text-sm text-slate-400">O player Panda, PDF ou material digital será exibido aqui assim que estiver cadastrado para esta aula.</p>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex flex-col gap-4 rounded-3xl border border-white/10 bg-white/5 p-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1 text-xs font-semibold text-sky-100">{{ match ($lesson->type) {
                            'video' => 'Vídeo',
                            'pdf' => 'PDF',
                            'mixed' => 'Mista',
                            'text' => 'Texto',
                            'quiz' => 'Questões',
                            default => $lesson->type,
                        } }}</span>
                        <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-slate-200">{{ $lesson->duration_minutes }} min</span>
                        @if ($isCompleted)
                            <span class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-200">Concluída</span>
                        @endif
                    </div>

                    @if ($lesson->description)
                        <p class="mt-4 text-sm leading-6 text-slate-300">{{ $lesson->description }}</p>
                    @endif
                </div>

                <form method="POST" action="{{ route('courses.lessons.complete', [$course->slug, $lesson]) }}" class="shrink-0">
                    @csrf
                    <button type="submit" class="w-full rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">
                        {{ $isCompleted ? 'Marcar concluída novamente' : 'Marcar como concluída' }}
                    </button>
                </form>
            </div>
        </section>

        <aside class="space-y-4">
            <div class="card-panel">
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Navegação</p>
                <div class="mt-5 space-y-3">
                    @if ($previousLesson)
                        <a href="{{ route('courses.lessons.show', [$course->slug, $previousLesson]) }}" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-100">
                            Aula anterior
                        </a>
                    @endif

                    @if ($nextLesson)
                        <a href="{{ route('courses.lessons.show', [$course->slug, $nextLesson]) }}" class="block rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">
                            Próxima aula
                        </a>
                    @endif

                    @unless ($previousLesson || $nextLesson)
                        <p class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-slate-400">Esta é a única aula publicada neste curso.</p>
                    @endunless
                </div>
            </div>

            <div class="card-panel">
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Status</p>
                <p class="mt-3 text-2xl font-semibold text-white">{{ $isCompleted ? 'Aula concluída' : 'Em andamento' }}</p>
                <p class="mt-2 text-sm text-slate-400">Ao concluir, o progresso do curso é atualizado automaticamente nos cards e na página do curso.</p>
            </div>
        </aside>
    </div>
</x-app-layout>
