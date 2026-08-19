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

        @if ($module->tracks->isNotEmpty())
            <div class="course-carousel-shell mt-6" x-data="lessonCarousel">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400 md:hidden">
                        Arraste para ver as próximas trilhas.
                    </p>
                </div>

                <button type="button" @click="scroll(-1)" :disabled="atStart" aria-label="Trilhas anteriores" class="course-carousel-arrow course-carousel-arrow-left">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.83 10l3.94 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd" />
                    </svg>
                </button>
                <button type="button" @click="scroll(1)" :disabled="atEnd" aria-label="Próximas trilhas" class="course-carousel-arrow course-carousel-arrow-right">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
                    </svg>
                </button>

                <div x-ref="track" @scroll.debounce.100ms="update" class="course-carousel-track">
                    @foreach ($module->tracks as $track)
                        @php
                            $trackLessonCount = $track->lessons->count();
                            $trackCompletedCount = $track->lessons->filter(fn ($lesson) => $completedLessonIds->contains($lesson->id))->count();
                            $trackProgress = $trackLessonCount > 0 ? (int) round(($trackCompletedCount / $trackLessonCount) * 100) : 0;
                            $trackEntryLesson = $trackEntryLessons->get($track->id);
                        @endphp

                        <article data-carousel-item class="card-subtle course-carousel-card course-track-card flex flex-col overflow-hidden p-0">
                            <div class="course-thumbnail-frame p-3">
                                <div class="course-track-thumbnail">
                                    <img src="{{ $track->thumbnail_display_url }}" alt="{{ $track->name }}">
                                </div>
                            </div>
                            <div class="flex flex-1 flex-col p-4">
                                <h3 class="text-sm font-semibold leading-5 text-white">{{ $track->name }}</h3>
                                @if (filled($track->teacher_display_name))
                                    <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-400">{{ $track->teacher_display_name }}</p>
                                @endif

                                <div class="mt-3 flex flex-wrap gap-3 text-xs font-semibold text-slate-300">
                                    <span>{{ $trackLessonCount }} aula(s)</span>
                                    @if ($hasAccess && $trackLessonCount > 0)
                                        <span>{{ $trackProgress }}%</span>
                                    @endif
                                </div>

                                @if ($hasAccess && $trackLessonCount > 0)
                                    <div class="mt-3">
                                        <div class="mb-2 flex items-center justify-between text-xs font-semibold text-slate-300">
                                            <span>{{ $trackCompletedCount }} de {{ $trackLessonCount }} aula(s)</span>
                                            <span>{{ $trackProgress }}%</span>
                                        </div>
                                        <div class="h-2 overflow-hidden rounded-full bg-slate-800">
                                            <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $trackProgress) }}%"></div>
                                        </div>
                                    </div>
                                @endif

                                <div class="mt-auto pt-3">
                                    <a href="{{ $trackEntryLesson ? route('courses.lessons.show', [$course->slug, $trackEntryLesson]) : route('courses.modules.tracks.lessons.index', [$course->slug, $module, $track]) }}" class="inline-flex w-full justify-center rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100 transition hover:bg-sky-400/20">
                                        Ver aulas
                                    </a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="mt-1 flex justify-center gap-2 md:hidden" aria-label="Navegação das trilhas">
                    @foreach ($module->tracks as $track)
                        <button type="button" @click="goTo({{ $loop->index }})" class="h-2.5 rounded-full transition-all" :class="activeIndex === {{ $loop->index }} ? 'w-6 bg-sky-300' : 'w-2.5 bg-slate-600'" aria-label="Ir para trilha {{ $loop->iteration }}"></button>
                    @endforeach
                </div>
            </div>
        @else
            <div class="mt-6 card-subtle">
                <p class="text-sm text-slate-400">Nenhuma trilha publicada neste módulo ainda.</p>
            </div>
        @endif
    </section>
</x-app-layout>
