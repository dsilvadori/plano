@php
    $thumbnail = $course->thumbnail_display_url;
    $progressPercentage = (int) ($progressSummary['percentage'] ?? 0);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel grid gap-6 lg:grid-cols-[minmax(0,1fr)_24rem] lg:items-center">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Curso online</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">{{ $course->name }}</h1>
                <p class="mt-3 max-w-3xl text-sm text-slate-300">{{ $course->short_description ?: $course->description ?: 'Módulos, trilhas e aulas organizados para você avançar com clareza.' }}</p>
                <div class="mt-5 flex flex-wrap gap-3">
                    @if ($course->sphere)
                        <span class="rounded-full border border-sky-400/20 bg-sky-400/10 px-4 py-2 text-sm font-semibold text-sky-100">{{ $course->sphere->name }}</span>
                    @endif
                    @if ($course->educationLevel)
                        <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-100">{{ $course->educationLevel->name }}</span>
                    @endif
                    <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-100">{{ $course->modules_count }} módulo(s)</span>
                    <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-100">{{ $course->lessons_count }} aula(s)</span>
                </div>
                @if ($hasAccess && $continueLesson)
                    <a href="{{ route('courses.lessons.show', [$course->slug, $continueLesson]) }}" class="mt-6 inline-flex rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">
                        {{ $progressPercentage > 0 ? 'Continuar aula' : 'Começar curso' }}
                    </a>
                @endif
            </div>
            <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-950 p-3 shadow-2xl shadow-slate-950/30">
                <img src="{{ $thumbnail }}" alt="{{ $course->name }}" class="aspect-video h-full w-full rounded-2xl object-contain">
            </div>
        </div>
    </x-slot>

    @unless ($hasAccess)
        <section class="mb-6 rounded-3xl border border-amber-400/20 bg-amber-400/10 p-6">
            <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Acesso bloqueado</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">Este curso ainda não está na sua área.</h2>
            <p class="mt-3 max-w-2xl text-sm text-amber-100/90">Você pode visualizar módulos e trilhas, mas precisa de matrícula ativa para assistir às aulas.</p>
            @if ($course->checkout_url)
                <a href="{{ $course->checkout_url }}" target="_blank" rel="noopener noreferrer" class="mt-5 inline-flex rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">
                    Comprar acesso
                </a>
            @endif
        </section>
    @endunless

    <section class="card-panel">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Conteúdo</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">Módulos do curso</h2>
                @if ($hasAccess)
                    <p class="mt-2 text-sm text-slate-300">{{ $progressSummary['completed'] }} de {{ $progressSummary['total'] }} aula(s) concluída(s).</p>
                @endif
            </div>
            <a href="{{ route('courses.mine') }}" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-100">
                Meus cursos
            </a>
        </div>

        @if ($hasAccess)
            <div class="mt-6 rounded-2xl border border-sky-400/20 bg-sky-400/10 p-4">
                <div class="mb-2 flex items-center justify-between text-sm font-semibold text-sky-100">
                    <span>Progresso do curso</span>
                    <span>{{ $progressPercentage }}%</span>
                </div>
                <div class="h-3 overflow-hidden rounded-full bg-slate-800">
                    <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $progressPercentage) }}%"></div>
                </div>
            </div>
        @endif

        <div class="mt-6 space-y-4">
            @forelse ($course->modules as $module)
                @php
                    $moduleLessonCount = $module->tracks->sum(fn ($track) => $track->lessons->count());
                    $moduleCompletedCount = $module->tracks
                        ->flatMap(fn ($track) => $track->lessons)
                        ->filter(fn ($lesson) => $completedLessonIds->contains($lesson->id))
                        ->count();
                    $moduleProgress = $moduleLessonCount > 0 ? (int) round(($moduleCompletedCount / $moduleLessonCount) * 100) : 0;
                    $isOpen = $loop->first;
                @endphp

                <details x-data="{ open: $el.open }" @toggle="open = $el.open" class="rounded-2xl border border-white/10 bg-white/[0.03] transition open:border-sky-400/30 open:bg-sky-400/[0.04]" @if ($isOpen) open @endif>
                    <summary class="flex cursor-pointer list-none flex-col gap-4 px-5 py-4 marker:hidden sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Módulo {{ $loop->iteration }}</p>
                            <h3 class="mt-2 text-lg font-semibold text-white">{{ $module->name }}</h3>
                            <p class="mt-1 line-clamp-2 text-sm text-slate-400">{{ $module->description ?: 'Trilhas e aulas deste módulo.' }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-3 text-xs font-semibold text-slate-300">
                            <span>{{ $module->tracks->count() }} trilha(s)</span>
                            <span>{{ $moduleLessonCount }} aula(s)</span>
                            @if ($hasAccess && $moduleLessonCount > 0)
                                <span class="rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1 text-sky-100">{{ $moduleProgress }}%</span>
                            @endif
                            <span class="inline-flex min-w-24 items-center justify-center gap-2 rounded-full border border-white/10 bg-slate-950 px-3 py-1 text-slate-100">
                                <span x-show="!open">Abrir</span>
                                <span x-show="open" x-cloak>Fechar</span>
                                <svg class="h-4 w-4 transition" :class="{ 'rotate-180': open }" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        </div>
                    </summary>

                    <div class="border-t border-white/10 px-5 py-5">
                        @if ($hasAccess && $moduleLessonCount > 0)
                            <div class="mb-5">
                                <div class="mb-2 flex items-center justify-between text-xs font-semibold text-slate-300">
                                    <span>{{ $moduleCompletedCount }} de {{ $moduleLessonCount }} aula(s)</span>
                                    <span>{{ $moduleProgress }}%</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-slate-800">
                                    <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $moduleProgress) }}%"></div>
                                </div>
                            </div>
                        @endif

                        @if ($module->tracks->isNotEmpty())
                            <div x-data="lessonCarousel" class="course-carousel-shell">
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
                                            $trackHasInProgressLesson = $trackEntryLesson && $inProgressLessonIds->contains($trackEntryLesson->id);
                                        @endphp

                                        <article data-carousel-item class="card-subtle course-carousel-card flex flex-col overflow-hidden p-0">
                                            <div class="bg-slate-950 p-3">
                                                <div class="flex h-36 w-full items-center justify-center rounded-xl bg-slate-900/80">
                                                    <img src="{{ $track->thumbnail_display_url }}" alt="{{ $track->name }}" class="max-h-full max-w-full rounded-lg object-contain">
                                                </div>
                                            </div>
                                            <div class="flex flex-1 flex-col p-5">
                                                <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Trilha {{ $loop->iteration }}</p>
                                                <h4 class="mt-3 text-base font-semibold leading-6 text-white">{{ $track->name }}</h4>
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
                                                            <span>{{ $trackCompletedCount }} de {{ $trackLessonCount }}</span>
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
                                    @endforeach
                                </div>

                                <div class="mt-1 flex justify-center gap-2 md:hidden" aria-label="Navegação das trilhas">
                                    @foreach ($module->tracks as $track)
                                        <button type="button" @click="goTo({{ $loop->index }})" class="h-2.5 rounded-full transition-all" :class="activeIndex === {{ $loop->index }} ? 'w-6 bg-sky-300' : 'w-2.5 bg-slate-600'" aria-label="Ir para trilha {{ $loop->iteration }}"></button>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <p class="text-sm text-slate-400">Nenhuma trilha publicada neste módulo ainda.</p>
                        @endif
                    </div>
                </details>
            @empty
                <div class="card-subtle">
                    <p class="text-sm text-slate-400">Este curso ainda não possui módulos publicados.</p>
                </div>
            @endforelse
        </div>
    </section>
</x-app-layout>
