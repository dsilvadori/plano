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
                <p class="mt-3 max-w-3xl text-sm text-slate-300">{{ $course->short_description ?: $course->description ?: 'Escolha um módulo para acessar as trilhas e aulas deste curso.' }}</p>
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
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Módulos</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">Escolha um módulo do curso</h2>
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

        <div class="mt-6 grid gap-5 sm:grid-cols-[repeat(auto-fill,minmax(18rem,22rem))]">
            @forelse ($course->modules as $module)
                @php
                    $moduleLessonCount = $module->tracks->sum(fn ($track) => $track->lessons->count());
                    $moduleCompletedCount = $module->tracks
                        ->flatMap(fn ($track) => $track->lessons)
                        ->filter(fn ($lesson) => $completedLessonIds->contains($lesson->id))
                        ->count();
                    $moduleProgress = $moduleLessonCount > 0 ? (int) round(($moduleCompletedCount / $moduleLessonCount) * 100) : 0;
                @endphp

                <article class="card-subtle flex h-full flex-col">
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Módulo {{ $loop->iteration }}</p>
                        <h3 class="mt-3 text-lg font-semibold leading-7 text-white">{{ $module->name }}</h3>
                        <p class="mt-2 line-clamp-3 text-sm text-slate-400">{{ $module->description ?: 'Acesse as trilhas e aulas organizadas neste módulo.' }}</p>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-3 text-xs font-semibold text-slate-300">
                        <span>{{ $module->tracks->count() }} trilha(s)</span>
                        <span>{{ $moduleLessonCount }} aula(s)</span>
                    </div>

                    @if ($hasAccess && $moduleLessonCount > 0)
                        <div class="mt-4">
                            <div class="mb-2 flex items-center justify-between text-xs font-semibold text-slate-300">
                                <span>{{ $moduleCompletedCount }} de {{ $moduleLessonCount }} aula(s)</span>
                                <span>{{ $moduleProgress }}%</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-800">
                                <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $moduleProgress) }}%"></div>
                            </div>
                        </div>
                    @endif

                    <div class="mt-auto pt-5">
                        <a href="{{ route('courses.modules.tracks.index', [$course->slug, $module]) }}" class="inline-flex w-full justify-center rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100 transition hover:bg-sky-400/20">
                            Ver trilhas
                        </a>
                    </div>
                </article>
            @empty
                <div class="card-subtle">
                    <p class="text-sm text-slate-400">Este curso ainda não possui módulos publicados.</p>
                </div>
            @endforelse
        </div>
    </section>
</x-app-layout>
