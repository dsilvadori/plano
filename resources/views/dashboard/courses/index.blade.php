<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Início</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">Escolha o próximo curso para avançar.</h1>
                <p class="mt-3 max-w-2xl text-sm text-slate-300">Veja seus cursos liberados, descubra novas turmas e continue estudando com o plano, as aulas e os materiais no mesmo lugar.</p>
            </div>
            <a href="{{ route('courses.mine') }}" class="rounded-2xl border border-sky-400/20 bg-sky-400/10 px-5 py-3 text-sm font-semibold text-sky-100">
                Meus cursos
            </a>
        </div>
    </x-slot>

    <div class="space-y-8">
        @if ($featuredCourses->isNotEmpty())
            <section class="card-panel">
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Destaques</p>
                        <h2 class="mt-2 text-2xl font-semibold text-white">Cursos principais</h2>
                    </div>
                </div>

                <div class="mt-6 grid gap-5 sm:grid-cols-[repeat(auto-fill,minmax(18rem,22rem))]">
                    @foreach ($featuredCourses as $course)
                        @include('dashboard.courses.partials.course-card', ['course' => $course, 'hasAccess' => $accessibleCourseIds->contains($course->id), 'progress' => $courseProgress[$course->id] ?? null, 'activePlan' => $activePlansByCourse->get($course->id)])
                    @endforeach
                </div>
            </section>
        @endif

        <section class="card-panel">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Catálogo</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">Últimos cursos disponíveis</h2>
            </div>

            <div class="mt-6 grid gap-5 sm:grid-cols-[repeat(auto-fill,minmax(18rem,22rem))]">
                @forelse ($latestCourses as $course)
                    @include('dashboard.courses.partials.course-card', ['course' => $course, 'hasAccess' => $accessibleCourseIds->contains($course->id), 'progress' => $courseProgress[$course->id] ?? null, 'activePlan' => $activePlansByCourse->get($course->id)])
                @empty
                    <div class="card-subtle">
                        <p class="text-sm text-slate-300">Nenhum curso publicado no momento.</p>
                    </div>
                @endforelse
            </div>
        </section>

        @if ($coursesBySphere->isNotEmpty())
            <section class="space-y-6">
                @foreach ($coursesBySphere as $sphere)
                    <div class="card-panel">
                        <div>
                            <p class="text-sm uppercase tracking-[0.25em] text-amber-300">{{ $sphere->name }}</p>
                            <h2 class="mt-2 text-2xl font-semibold text-white">Cursos por esfera</h2>
                        </div>

                        <div class="mt-6 grid gap-5 sm:grid-cols-[repeat(auto-fill,minmax(18rem,22rem))]">
                            @foreach ($sphere->courses as $course)
                                @include('dashboard.courses.partials.course-card', ['course' => $course, 'hasAccess' => $accessibleCourseIds->contains($course->id), 'progress' => $courseProgress[$course->id] ?? null, 'activePlan' => $activePlansByCourse->get($course->id)])
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

        @if ($educationLevels->isNotEmpty())
            <section class="card-panel">
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Escolaridade</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">Filtros que já estão prontos para o catálogo</h2>
                <div class="mt-5 flex flex-wrap gap-3">
                    @foreach ($educationLevels as $level)
                        <span class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-100">
                            {{ $level->name }} · {{ $level->courses_count }} curso(s)
                        </span>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="card-panel flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Plano de Estudos</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">Acesse seus planos de estudo.</h2>
                <p class="mt-2 max-w-2xl text-sm text-slate-300">Retome ciclos criados para os cursos e acompanhe o que já está em andamento.</p>
            </div>
            <a href="{{ route('study-plans.index') }}" class="inline-flex justify-center rounded-2xl border border-sky-400/20 bg-sky-400/10 px-5 py-3 text-sm font-semibold text-sky-100">
                Meus planos de estudos
            </a>
        </section>
    </div>
</x-app-layout>
