@php
    $moduleLessonCount = $module->tracks->sum(fn ($track) => $track->lessons->count());
    $moduleCompletedCount = $module->tracks
        ->flatMap(fn ($track) => $track->lessons)
        ->filter(fn ($lesson) => $completedLessonIds->contains($lesson->id))
        ->count();
    $moduleProgress = $moduleLessonCount > 0 ? (int) round(($moduleCompletedCount / $moduleLessonCount) * 100) : 0;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">{{ $course->name }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">{{ $module->name }}</h1>
                <p class="mt-3 max-w-2xl text-sm text-slate-300">{{ $module->description ?: 'Escolha uma trilha para ver as aulas deste módulo.' }}</p>
            </div>
            <a href="{{ route('courses.show', $course->slug) }}" class="rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-sm font-semibold text-slate-100">
                Voltar aos módulos
            </a>
        </div>
    </x-slot>

    <section class="card-panel">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Trilhas</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">Trilhas deste módulo</h2>
                <p class="mt-2 text-sm text-slate-300">{{ $module->tracks->count() }} trilha(s) e {{ $moduleLessonCount }} aula(s) neste módulo.</p>
            </div>
            @if ($hasAccess && $moduleLessonCount > 0)
                <span class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">
                    {{ $moduleProgress }}% concluído
                </span>
            @endif
        </div>

        @if ($hasAccess && $moduleLessonCount > 0)
            <div class="mt-6 rounded-2xl border border-sky-400/20 bg-sky-400/10 p-4">
                <div class="mb-2 flex items-center justify-between text-sm font-semibold text-sky-100">
                    <span>{{ $moduleCompletedCount }} de {{ $moduleLessonCount }} aula(s) concluída(s)</span>
                    <span>{{ $moduleProgress }}%</span>
                </div>
                <div class="h-3 overflow-hidden rounded-full bg-slate-800">
                    <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $moduleProgress) }}%"></div>
                </div>
            </div>
        @endif

        <div class="mt-6 grid gap-5 sm:grid-cols-[repeat(auto-fill,minmax(18rem,22rem))]">
            @forelse ($module->tracks as $track)
                @php
                    $trackLessonCount = $track->lessons->count();
                    $trackCompletedCount = $track->lessons->filter(fn ($lesson) => $completedLessonIds->contains($lesson->id))->count();
                    $trackProgress = $trackLessonCount > 0 ? (int) round(($trackCompletedCount / $trackLessonCount) * 100) : 0;
                    $trackEntryLesson = $trackEntryLessons->get($track->id);
                    $trackHasInProgressLesson = $trackEntryLesson && $inProgressLessonIds->contains($trackEntryLesson->id);
                @endphp

                <article class="card-subtle flex h-full flex-col overflow-hidden p-0">
                    <div class="bg-slate-950 p-3">
                        <div class="flex h-40 w-full items-center justify-center rounded-xl bg-slate-900/80">
                            <img src="{{ $track->thumbnail_display_url }}" alt="{{ $track->name }}" class="max-h-full max-w-full rounded-lg object-contain">
                        </div>
                    </div>
                    <div class="flex flex-1 flex-col p-5">
                        <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Trilha {{ $loop->iteration }}</p>
                        <h3 class="mt-3 text-lg font-semibold leading-7 text-white">{{ $track->name }}</h3>
                        <p class="mt-2 line-clamp-3 text-sm text-slate-400">{{ $track->description ?: 'Acesse a lista de aulas desta trilha.' }}</p>

                        <div class="mt-4 flex flex-wrap gap-3 text-xs font-semibold text-slate-300">
                            <span>{{ $trackLessonCount }} aula(s)</span>
                            @if ($hasAccess && $trackLessonCount > 0)
                                <span>{{ $trackProgress }}%</span>
                            @endif
                        </div>

                        @if ($hasAccess && $trackLessonCount > 0)
                            <div class="mt-4">
                                <div class="mb-2 flex items-center justify-between text-xs font-semibold text-slate-300">
                                    <span>{{ $trackCompletedCount }} de {{ $trackLessonCount }} aula(s)</span>
                                    <span>{{ $trackProgress }}%</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-slate-800">
                                    <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $trackProgress) }}%"></div>
                                </div>
                            </div>
                        @endif

                        <div class="mt-auto space-y-3 pt-5">
                            <a href="{{ route('courses.modules.tracks.lessons.index', [$course->slug, $module, $track]) }}" class="inline-flex w-full justify-center rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100 transition hover:bg-sky-400/20">
                                Ver aulas
                            </a>
                            @if ($hasAccess && $trackEntryLesson)
                                <a href="{{ route('courses.lessons.show', [$course->slug, $trackEntryLesson]) }}" class="inline-flex w-full justify-center rounded-2xl bg-amber-300 px-4 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">
                                    {{ $trackHasInProgressLesson || ($trackProgress > 0 && $trackProgress < 100) ? 'Continuar trilha' : ($trackProgress >= 100 ? 'Rever trilha' : 'Começar trilha') }}
                                </a>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="card-subtle">
                    <p class="text-sm text-slate-400">Nenhuma trilha publicada neste módulo ainda.</p>
                </div>
            @endforelse
        </div>
    </section>
</x-app-layout>
