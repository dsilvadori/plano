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
                <p class="mt-3 max-w-3xl text-sm text-slate-300">{{ $course->short_description ?: $course->description ?: 'Curso organizado em módulos e aulas para acompanhar seu plano de estudos.' }}</p>
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
            <p class="mt-3 max-w-2xl text-sm text-amber-100/90">Você pode visualizar a estrutura do curso, mas precisa de matrícula ativa para assistir às aulas.</p>
            @if ($course->checkout_url)
                <a href="{{ $course->checkout_url }}" target="_blank" rel="noopener noreferrer" class="mt-5 inline-flex rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">
                    Comprar acesso
                </a>
            @endif
        </section>
    @endunless

    <section class="card-panel">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Conteúdo</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">Módulos e aulas</h2>
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
                @endphp
                <details class="card-subtle group">
                    <summary class="flex cursor-pointer list-none flex-col gap-4 rounded-2xl transition hover:bg-white/[0.03] sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Módulo</p>
                            <h3 class="mt-2 text-xl font-semibold text-white">{{ $module->name }}</h3>
                            @if ($module->description)
                                <p class="mt-2 text-sm text-slate-400">{{ $module->description }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-slate-200">
                                {{ $moduleLessonCount }} aula(s)
                            </span>
                            <span class="inline-flex items-center gap-2 rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1.5 text-xs font-semibold text-sky-100 transition group-open:bg-sky-400/20">
                                <span class="group-open:hidden">Abrir</span>
                                <span class="hidden group-open:inline">Fechar</span>
                                <svg class="h-4 w-4 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        </div>
                    </summary>

                    <div class="mt-6 flex gap-5 overflow-x-auto pb-3">
                        @forelse ($module->tracks as $track)
                            @php
                                $trackLessonCount = $track->lessons->count();
                                $trackCompletedCount = $track->lessons->filter(fn ($lesson) => $completedLessonIds->contains($lesson->id))->count();
                                $trackProgress = $trackLessonCount > 0 ? (int) round(($trackCompletedCount / $trackLessonCount) * 100) : 0;
                                $trackEntryLesson = $trackEntryLessons->get($track->id);
                                $trackHasInProgressLesson = $trackEntryLesson && $inProgressLessonIds->contains($trackEntryLesson->id);
                            @endphp
                            @if ($hasAccess && $trackEntryLesson)
                                <a href="{{ route('courses.lessons.show', [$course->slug, $trackEntryLesson]) }}" class="group flex w-[20rem] shrink-0 flex-col overflow-hidden rounded-2xl border border-white/10 bg-slate-950/60 transition hover:-translate-y-0.5 hover:border-sky-400/40 hover:bg-slate-900/80">
                                    <div class="bg-slate-950 p-3">
                                        <img src="{{ $track->thumbnail_display_url }}" alt="{{ $track->name }}" class="aspect-video w-full rounded-xl object-contain">
                                    </div>
                                    <div class="flex flex-1 flex-col gap-4 p-5">
                                        <div>
                                            <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Trilha</p>
                                            <h4 class="mt-2 text-base font-semibold leading-6 text-white">{{ $track->name }}</h4>
                                        </div>
                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between text-xs font-semibold text-slate-300">
                                            <span>{{ $trackLessonCount }} aula(s)</span>
                                            <span>{{ $trackProgress }}%</span>
                                            </div>
                                            <div class="h-2 overflow-hidden rounded-full bg-slate-800">
                                                <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $trackProgress) }}%"></div>
                                            </div>
                                        </div>
                                        <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                                                {{ $trackHasInProgressLesson || ($trackProgress > 0 && $trackProgress < 100) ? 'Continuar em' : ($trackProgress >= 100 ? 'Rever desde' : 'Começar por') }}
                                            </p>
                                            <p class="mt-2 line-clamp-3 text-sm font-semibold leading-5 text-slate-100">{{ $trackEntryLesson->title }}</p>
                                        </div>
                                        <span class="mt-auto inline-flex w-full justify-center rounded-xl border border-sky-400/20 bg-sky-400/10 px-3 py-2.5 text-center text-sm font-semibold text-sky-100 transition group-hover:bg-sky-400/20">
                                            {{ $trackHasInProgressLesson || ($trackProgress > 0 && $trackProgress < 100) ? 'Continuar trilha' : ($trackProgress >= 100 ? 'Rever trilha' : 'Começar trilha') }}
                                        </span>
                                    </div>
                                </a>
                            @else
                                <div class="flex w-[20rem] shrink-0 flex-col overflow-hidden rounded-2xl border border-white/10 bg-slate-950/60">
                                    <div class="bg-slate-950 p-3">
                                        <img src="{{ $track->thumbnail_display_url }}" alt="{{ $track->name }}" class="aspect-video w-full rounded-xl object-contain opacity-80">
                                    </div>
                                    <div class="flex flex-1 flex-col gap-4 p-5">
                                        <div>
                                            <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Trilha</p>
                                            <h4 class="mt-2 text-base font-semibold leading-6 text-white">{{ $track->name }}</h4>
                                        </div>
                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between text-xs font-semibold text-slate-300">
                                                <span>{{ $trackLessonCount }} aula(s)</span>
                                                @if ($hasAccess)
                                                    <span>{{ $trackProgress }}%</span>
                                                @endif
                                            </div>
                                            @if ($hasAccess)
                                                <div class="h-2 overflow-hidden rounded-full bg-slate-800">
                                                    <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $trackProgress) }}%"></div>
                                                </div>
                                            @endif
                                        </div>
                                        <span class="mt-auto inline-flex w-full justify-center rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-center text-sm font-semibold text-slate-400">
                                            {{ $hasAccess ? 'Sem aulas publicadas' : 'Bloqueada' }}
                                        </span>
                                    </div>
                                </div>
                            @endif
                        @empty
                            <div class="w-full rounded-2xl border border-white/10 bg-white/5 p-4">
                                <p class="text-sm text-slate-400">Nenhuma trilha publicada neste módulo ainda.</p>
                                @if ($moduleCompletedCount > 0)
                                    <p class="mt-1 text-xs text-slate-500">{{ $moduleCompletedCount }} aula(s) concluída(s) neste módulo.</p>
                                @endif
                            </div>
                        @endforelse
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
