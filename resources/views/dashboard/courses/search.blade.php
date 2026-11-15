<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel">
            <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Busca</p>
            <h1 class="mt-2 text-3xl font-semibold text-white">
                @if ($hasValidTerm)
                    Resultados para “{{ $term }}”
                @else
                    Encontre cursos e aulas
                @endif
            </h1>
            <p class="mt-3 max-w-2xl text-sm text-slate-300">
                @if ($hasValidTerm)
                    {{ $courses->count() }} curso(s) e {{ $lessonResultsCount }} aula(s) encontrados.
                @else
                    Digite pelo menos 2 letras. A busca procura no nome dos cursos e no título das aulas liberadas para você.
                @endif
            </p>

            @include('dashboard.courses.partials.search-form', [
                'searchInputId' => 'course-search-page',
                'formClass' => 'mt-6 max-w-2xl',
                'searchValue' => $term,
                'showButton' => true,
                'placeholder' => 'Ex.: português, crase, direito constitucional',
            ])
        </div>
    </x-slot>

    @if ($hasValidTerm)
        <div class="space-y-8">
            <section class="card-panel">
                <div>
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Aulas</p>
                    <h2 class="mt-2 text-2xl font-semibold text-white">Aulas dos seus cursos</h2>
                </div>

                @forelse ($lessonResults as $group)
                    @php
                        $groupCourse = $group['course'];
                    @endphp

                    <div class="mt-6">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-lg font-semibold text-white">{{ $groupCourse->name }}</h3>
                            <a href="{{ route('courses.show', $groupCourse->slug) }}" class="text-sm font-semibold text-sky-200 hover:text-sky-100">Ver curso</a>
                        </div>

                        <ul class="mt-3 space-y-3">
                            @foreach ($group['lessons'] as $lesson)
                                @php
                                    $lessonCompleted = $group['completed_lesson_ids']->contains($lesson->id);
                                    $lessonContext = collect([$lesson->course_module_order_name, $lesson->search_track_name])
                                        ->filter()
                                        ->unique()
                                        ->join(' / ');
                                @endphp

                                <li>
                                    <a href="{{ route('courses.lessons.show', [$groupCourse->slug, $lesson]) }}" class="card-subtle flex flex-col gap-2 transition hover:border-sky-400/30 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                            @if ($lessonContext !== '')
                                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-amber-300">{{ $lessonContext }}</p>
                                            @endif
                                            <p class="mt-1 font-semibold leading-6 text-white">{{ $lesson->title }}</p>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-2">
                                            @if ($lessonCompleted)
                                                <span class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-2 py-0.5 text-xs font-semibold text-emerald-200">Concluída</span>
                                            @endif
                                            @if ($lesson->duration_minutes > 0)
                                                <span class="text-xs font-semibold text-slate-500">{{ $lesson->duration_minutes }} min</span>
                                            @endif
                                        </div>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <div class="card-subtle mt-6">
                        <p class="text-sm font-semibold text-white">Nenhuma aula encontrada.</p>
                        <p class="mt-2 text-sm text-slate-400">Confira a grafia ou tente uma palavra mais curta. Só aparecem aulas dos cursos liberados para você.</p>
                    </div>
                @endforelse
            </section>

            <section class="card-panel">
                <div>
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Cursos</p>
                    <h2 class="mt-2 text-2xl font-semibold text-white">Cursos do catálogo</h2>
                </div>

                @include('dashboard.courses.partials.course-carousel', [
                    'courses' => $courses,
                    'accessibleCourseIds' => $accessibleCourseIds,
                    'courseProgress' => $courseProgress,
                    'activePlansByCourse' => $activePlansByCourse,
                    'emptyMessage' => 'Nenhum curso encontrado para essa busca.',
                    'showOwnedBadge' => true,
                ])
            </section>
        </div>
    @endif
</x-app-layout>
