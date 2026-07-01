@php
    $thumbnail = $course->thumbnail_display_url;
    $progressPercentage = (int) ($progressSummary['percentage'] ?? 0);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel grid gap-6 lg:grid-cols-[1fr_20rem] lg:items-center">
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
            <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-950/60">
                <img src="{{ $thumbnail }}" alt="{{ $course->name }}" class="aspect-[16/10] h-full w-full object-cover">
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
                <details class="card-subtle group" open>
                    <summary class="flex cursor-pointer list-none flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Módulo</p>
                            <h3 class="mt-2 text-xl font-semibold text-white">{{ $module->name }}</h3>
                            @if ($module->description)
                                <p class="mt-2 text-sm text-slate-400">{{ $module->description }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-slate-200">
                                {{ $moduleLessonCount }} aula(s)
                            </span>
                            <span class="text-slate-400 transition group-open:rotate-180">v</span>
                        </div>
                    </summary>

                    <div class="mt-5 flex gap-4 overflow-x-auto pb-2">
                        @forelse ($module->tracks as $track)
                            @php
                                $trackLessonCount = $track->lessons->count();
                                $trackCompletedCount = $track->lessons->filter(fn ($lesson) => $completedLessonIds->contains($lesson->id))->count();
                                $trackProgress = $trackLessonCount > 0 ? (int) round(($trackCompletedCount / $trackLessonCount) * 100) : 0;
                            @endphp
                            <details class="w-[18rem] shrink-0 overflow-hidden rounded-2xl border border-white/10 bg-slate-950/60" open>
                                <summary class="cursor-pointer list-none">
                                    <img src="{{ $track->thumbnail_display_url }}" alt="{{ $track->name }}" class="aspect-video w-full object-cover">
                                    <div class="p-4">
                                        <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Trilha</p>
                                        <h4 class="mt-2 min-h-12 text-base font-semibold text-white">{{ $track->name }}</h4>
                                        <div class="mt-4 flex items-center justify-between text-xs font-semibold text-slate-300">
                                            <span>{{ $trackLessonCount }} aula(s)</span>
                                            @if ($hasAccess)
                                                <span>{{ $trackProgress }}%</span>
                                            @endif
                                        </div>
                                        @if ($hasAccess)
                                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-800">
                                                <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $trackProgress) }}%"></div>
                                            </div>
                                        @endif
                                    </div>
                                </summary>

                                <div class="space-y-2 border-t border-white/10 p-4">
                                    @forelse ($track->lessons as $lesson)
                                        @php
                                            $lessonCompleted = $completedLessonIds->contains($lesson->id);
                                        @endphp
                                        <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                                            <div class="flex flex-wrap gap-2">
                                                <span class="rounded-full border border-sky-400/20 bg-sky-400/10 px-2 py-1 text-[0.7rem] font-semibold text-sky-100">{{ match ($lesson->type) {
                                                    'video' => 'Vídeo',
                                                    'pdf' => 'PDF',
                                                    'mixed' => 'Mista',
                                                    'text' => 'Texto',
                                                    'quiz' => 'Questões',
                                                    default => $lesson->type,
                                                } }}</span>
                                                <span class="rounded-full border border-white/10 bg-white/5 px-2 py-1 text-[0.7rem] font-semibold text-slate-200">{{ $lesson->duration_minutes }} min</span>
                                                @if ($lessonCompleted)
                                                    <span class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-2 py-1 text-[0.7rem] font-semibold text-emerald-200">Concluída</span>
                                                @endif
                                            </div>
                                            <p class="mt-3 text-sm font-semibold text-white">{{ $lesson->title }}</p>

                                            @if ($hasAccess)
                                                <a href="{{ route('courses.lessons.show', [$course->slug, $lesson]) }}" class="mt-3 inline-flex w-full justify-center rounded-xl border border-sky-400/20 bg-sky-400/10 px-3 py-2 text-center text-sm font-semibold text-sky-100">
                                                    {{ $lessonCompleted ? 'Rever aula' : 'Assistir aula' }}
                                                </a>
                                            @else
                                                <button type="button" disabled class="mt-3 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm font-semibold text-slate-400">
                                                    Bloqueada
                                                </button>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="text-sm text-slate-400">Nenhuma aula publicada nesta trilha ainda.</p>
                                    @endforelse
                                </div>
                            </details>
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
