@php
    $trackLessonCount = $track->lessons->count();
    $trackCompletedCount = $track->lessons->filter(fn ($lesson) => $completedLessonIds->contains($lesson->id))->count();
    $trackProgress = $trackLessonCount > 0 ? (int) round(($trackCompletedCount / $trackLessonCount) * 100) : 0;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">{{ $module->name }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">{{ $track->name }}</h1>
                <p class="mt-3 max-w-2xl text-sm text-slate-300">{{ $track->description ?: 'Aulas filtradas por curso, módulo e trilha selecionados.' }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('courses.modules.tracks.index', [$course->slug, $module]) }}" class="rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-sm font-semibold text-slate-100">
                    Voltar às trilhas
                </a>
                <a href="{{ route('courses.show', $course->slug) }}" class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-5 py-3 text-sm font-semibold text-sky-100">
                    Módulos
                </a>
            </div>
        </div>
    </x-slot>

    <section class="card-panel">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Aulas</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">Aulas desta trilha</h2>
                <p class="mt-2 text-sm text-slate-300">{{ $trackLessonCount }} aula(s) em {{ $course->name }}.</p>
            </div>
            @if ($hasAccess && $trackLessonCount > 0)
                <span class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">
                    {{ $trackProgress }}% concluído
                </span>
            @endif
        </div>

        @if ($hasAccess && $trackLessonCount > 0)
            <div class="mt-6 rounded-2xl border border-sky-400/20 bg-sky-400/10 p-4">
                <div class="mb-2 flex items-center justify-between text-sm font-semibold text-sky-100">
                    <span>{{ $trackCompletedCount }} de {{ $trackLessonCount }} aula(s) concluída(s)</span>
                    <span>{{ $trackProgress }}%</span>
                </div>
                <div class="h-3 overflow-hidden rounded-full bg-slate-800">
                    <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $trackProgress) }}%"></div>
                </div>
            </div>
        @endif

        @if ($track->lessons->isNotEmpty())
            <div x-data="lessonCarousel" class="course-carousel-shell mt-6">
                <div class="mb-3 flex items-center justify-between gap-4">
                    <p class="text-xs font-semibold text-slate-400 md:hidden">Arraste para ver as próximas aulas.</p>
                </div>

                <button type="button" @click="scroll(-1)" :disabled="atStart" class="course-carousel-arrow course-carousel-arrow-left" aria-label="Aulas anteriores">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
                    </svg>
                </button>
                <button type="button" @click="scroll(1)" :disabled="atEnd" class="course-carousel-arrow course-carousel-arrow-right" aria-label="Próximas aulas">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                    </svg>
                </button>

                <div x-ref="track" @scroll.debounce.100ms="update" class="course-carousel-track">
                    @foreach ($track->lessons as $lesson)
                        @php
                            $lessonCompleted = $completedLessonIds->contains($lesson->id);
                            $lessonInProgress = $inProgressLessonIds->contains($lesson->id);
                            $lessonHasMedia = filled($lesson->panda_video_id)
                                || filled($lesson->panda_embed_url)
                                || filled($lesson->panda_player_url)
                                || in_array((string) $lesson->source_status, ['media_ready', 'published'], true);
                        @endphp

                        <article data-carousel-item class="card-subtle course-carousel-card flex flex-col">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-amber-300">Aula {{ $loop->iteration }}</span>
                                @if ($hasAccess && $lessonCompleted)
                                    <span class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-2 py-0.5 text-xs font-semibold text-emerald-200">Concluída</span>
                                @elseif ($hasAccess && $lessonInProgress)
                                    <span class="rounded-full border border-amber-300/20 bg-amber-300/10 px-2 py-0.5 text-xs font-semibold text-amber-200">Em andamento</span>
                                @elseif (! $lessonHasMedia)
                                    <span class="rounded-full border border-amber-300/20 bg-amber-300/10 px-2 py-0.5 text-xs font-semibold text-amber-200">Em breve</span>
                                @endif
                            </div>

                            <h3 class="mt-3 text-lg font-semibold leading-7 text-white">{{ $lesson->title }}</h3>
                            <p class="mt-2 line-clamp-3 text-sm text-slate-400">{{ $lesson->description && $lesson->description !== 'Aula importada por planilha.' ? $lesson->description : 'Aula da trilha selecionada.' }}</p>

                            @if ($lesson->duration_minutes > 0)
                                <p class="mt-4 text-xs font-semibold text-slate-500">{{ $lesson->duration_minutes }} min</p>
                            @endif

                            <div class="mt-auto pt-5">
                                @if ($hasAccess)
                                    <a href="{{ route('courses.lessons.show', [$course->slug, $lesson]) }}" class="inline-flex w-full justify-center rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100 transition hover:bg-sky-400/20">
                                        {{ $lessonCompleted ? 'Rever aula' : ($lessonInProgress ? 'Continuar' : 'Assistir aula') }}
                                    </a>
                                @else
                                    <span class="inline-flex w-full justify-center rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-400">
                                        Aula bloqueada
                                    </span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="mt-1 flex justify-center gap-2 md:hidden" aria-label="Indicador de aulas">
                    @foreach ($track->lessons as $lesson)
                        <button type="button" @click="goTo({{ $loop->index }})" class="h-2.5 rounded-full transition" :class="activeIndex === {{ $loop->index }} ? 'w-6 bg-sky-300' : 'w-2.5 bg-slate-600'" aria-label="Ir para aula {{ $loop->iteration }}"></button>
                    @endforeach
                </div>
            </div>
        @else
            <div class="mt-6 card-subtle">
                <p class="text-sm text-slate-400">Nenhuma aula publicada nesta trilha ainda.</p>
            </div>
        @endif
    </section>
</x-app-layout>
