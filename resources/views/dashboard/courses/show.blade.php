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
                <details
                    class="card-subtle group"
                    x-init="$nextTick(() => sync())"
                    x-on:toggle="$nextTick(() => sync())"
                    x-data="{
                        active: 0,
                        total: {{ $module->tracks->count() }},
                        canPrevious: false,
                        canNext: {{ $module->tracks->count() > 1 ? 'true' : 'false' }},
                        scrollTo(index) {
                            this.active = Math.max(0, Math.min(index, this.total - 1));
                            this.$refs.tracks?.children[this.active]?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
                            this.$nextTick(() => this.sync());
                        },
                        scrollByCard(direction) {
                            const rail = this.$refs.tracks;
                            const firstCard = rail?.children[0];

                            if (! rail || ! firstCard) {
                                return;
                            }

                            const styles = window.getComputedStyle(rail);
                            const gap = Number.parseFloat(styles.columnGap || styles.gap || '0') || 0;
                            const distance = firstCard.getBoundingClientRect().width + gap;

                            rail.scrollBy({ left: direction * distance, behavior: 'smooth' });
                            window.setTimeout(() => this.sync(), 180);
                        },
                        previous() {
                            this.scrollByCard(-1);
                        },
                        next() {
                            this.scrollByCard(1);
                        },
                        sync() {
                            const rail = this.$refs.tracks;

                            if (! rail || rail.children.length === 0) {
                                return;
                            }

                            const center = rail.scrollLeft + (rail.clientWidth / 2);
                            let nearest = 0;
                            let distance = Number.POSITIVE_INFINITY;

                            Array.from(rail.children).forEach((item, index) => {
                                const itemCenter = item.offsetLeft + (item.clientWidth / 2);
                                const itemDistance = Math.abs(center - itemCenter);

                                if (itemDistance < distance) {
                                    distance = itemDistance;
                                    nearest = index;
                                }
                            });

                            const edgeTolerance = 32;
                            const maxScroll = Math.max(0, rail.scrollWidth - rail.clientWidth);

                            this.active = nearest;
                            this.canPrevious = rail.scrollLeft > edgeTolerance;
                            this.canNext = rail.scrollLeft < maxScroll - edgeTolerance;
                        },
                    }"
                >
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

                    @if ($module->tracks->isNotEmpty())
                        <div class="mt-6 flex items-center justify-between gap-3 sm:hidden">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 sm:hidden">Arraste para o lado</p>
                        </div>
                    @endif

                    <div class="relative mt-4">
                        @if ($module->tracks->count() > 1)
                            <button
                                type="button"
                                class="absolute -left-5 top-1/2 z-10 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full border border-white/10 bg-slate-950/90 text-slate-100 shadow-xl shadow-slate-950/40 transition hover:border-sky-400/40 hover:bg-sky-400/10 lg:inline-flex"
                                x-on:click.stop="previous()"
                                x-show="canPrevious"
                                x-cloak
                                aria-label="Trilha anterior"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L9.08 10l3.69 3.71a.75.75 0 1 1-1.06 1.06l-4.22-4.25a.75.75 0 0 1 0-1.06l4.22-4.25a.75.75 0 0 1 1.08.02Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                class="absolute -right-5 top-1/2 z-10 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full border border-white/10 bg-slate-950/90 text-slate-100 shadow-xl shadow-slate-950/40 transition hover:border-sky-400/40 hover:bg-sky-400/10 lg:inline-flex"
                                x-on:click.stop="next()"
                                x-show="canNext"
                                x-cloak
                                aria-label="Próxima trilha"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L10.92 10 7.23 6.29a.75.75 0 1 1 1.06-1.06l4.22 4.25a.75.75 0 0 1 0 1.06l-4.22 4.25a.75.75 0 0 1-1.08-.02Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        @endif

                        <div
                            class="flex snap-x snap-mandatory gap-5 overflow-x-auto scroll-smooth pb-3 lg:px-6 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                            x-ref="tracks"
                            x-on:scroll.throttle.50ms="sync()"
                        >
                            @forelse ($module->tracks as $track)
                                @php
                                    $trackLessonCount = $track->lessons->count();
                                    $trackMediaLessonCount = $track->lessons
                                        ->filter(fn ($lesson) => filled($lesson->panda_video_id)
                                            || filled($lesson->panda_embed_url)
                                            || filled($lesson->panda_player_url)
                                            || in_array((string) $lesson->source_status, ['media_ready', 'published'], true))
                                        ->count();
                                    $trackHasMediaLessons = $trackMediaLessonCount > 0;
                                    $trackCompletedCount = $track->lessons->filter(fn ($lesson) => $completedLessonIds->contains($lesson->id))->count();
                                    $trackProgress = $trackLessonCount > 0 ? (int) round(($trackCompletedCount / $trackLessonCount) * 100) : 0;
                                    $trackEntryLesson = $trackEntryLessons->get($track->id);
                                    $trackHasInProgressLesson = $trackEntryLesson && $inProgressLessonIds->contains($trackEntryLesson->id);
                                @endphp
                                @if ($hasAccess && $trackEntryLesson)
                                    <a href="{{ route('courses.lessons.show', [$course->slug, $trackEntryLesson]) }}" class="group flex w-[min(20rem,82vw)] shrink-0 snap-start flex-col overflow-hidden rounded-2xl border border-white/10 bg-slate-950/60 transition hover:-translate-y-0.5 hover:border-sky-400/40 hover:bg-slate-900/80 sm:w-[20rem]">
                                        <div class="bg-slate-950 p-3">
                                            <div class="flex h-40 w-full items-center justify-center rounded-xl bg-slate-900/80">
                                                <img src="{{ $track->thumbnail_display_url }}" alt="{{ $track->name }}" class="max-h-full max-w-full rounded-lg object-contain">
                                            </div>
                                        </div>
                                        <div class="flex flex-1 flex-col gap-4 p-5">
                                            <div>
                                                <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Trilha</p>
                                                <h4 class="mt-2 text-base font-semibold leading-6 text-white">{{ $track->name }}</h4>
                                            </div>
                                            <div class="space-y-2">
                                                <div class="flex items-center justify-between text-xs font-semibold text-slate-300">
                                                    <span class="flex items-center gap-2">
                                                        <span>{{ $trackLessonCount }} aula(s)</span>
                                                        @unless ($trackHasMediaLessons)
                                                            <span class="rounded-full border border-amber-300/20 bg-amber-300/10 px-2 py-0.5 text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-amber-200">Aulas em breve</span>
                                                        @endunless
                                                    </span>
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
                                    <div class="flex w-[min(20rem,82vw)] shrink-0 snap-start flex-col overflow-hidden rounded-2xl border border-white/10 bg-slate-950/60 sm:w-[20rem]">
                                        <div class="bg-slate-950 p-3">
                                            @if ($trackLessonCount > 0)
                                                <div class="flex h-40 w-full items-center justify-center rounded-xl bg-slate-900/80">
                                                    <img src="{{ $track->thumbnail_display_url }}" alt="{{ $track->name }}" class="max-h-full max-w-full rounded-lg object-contain opacity-80">
                                                </div>
                                            @else
                                                <div class="flex h-40 w-full flex-col items-center justify-center rounded-xl border border-dashed border-amber-300/30 bg-amber-300/10 px-5 text-center">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-300">Aulas em breve</p>
                                                    <p class="mt-3 text-sm leading-5 text-slate-300">Você poderá acessar esta trilha assim que as aulas forem adicionadas.</p>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="flex flex-1 flex-col gap-4 p-5">
                                            <div>
                                                <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Trilha</p>
                                                <h4 class="mt-2 text-base font-semibold leading-6 text-white">{{ $track->name }}</h4>
                                            </div>
                                            <div class="space-y-2">
                                                <div class="flex items-center justify-between text-xs font-semibold text-slate-300">
                                                    <span class="flex items-center gap-2">
                                                        <span>{{ $trackLessonCount }} aula(s)</span>
                                                        @unless ($trackHasMediaLessons)
                                                            <span class="rounded-full border border-amber-300/20 bg-amber-300/10 px-2 py-0.5 text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-amber-200">Aulas em breve</span>
                                                        @endunless
                                                    </span>
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
                                                {{ $trackLessonCount === 0 ? 'Aulas disponíveis em breve' : ($hasAccess ? 'Sem aulas publicadas' : 'Bloqueada') }}
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
                    </div>

                    @if ($module->tracks->count() > 1)
                        <div class="mt-1 flex justify-center gap-2 sm:hidden">
                            @foreach ($module->tracks as $track)
                                <button
                                    type="button"
                                    class="h-2.5 rounded-full transition"
                                    x-on:click="scrollTo({{ $loop->index }})"
                                    x-bind:class="active === {{ $loop->index }} ? 'w-6 bg-sky-300' : 'w-2.5 bg-slate-600'"
                                    aria-label="Ir para a trilha {{ $loop->iteration }}"
                                ></button>
                            @endforeach
                        </div>
                    @endif
                </details>
            @empty
                <div class="card-subtle">
                    <p class="text-sm text-slate-400">Este curso ainda não possui módulos publicados.</p>
                </div>
            @endforelse
        </div>
    </section>
</x-app-layout>
