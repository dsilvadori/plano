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

                @include('dashboard.courses.partials.course-carousel', ['courses' => $featuredCourses, 'accessibleCourseIds' => $accessibleCourseIds, 'courseProgress' => $courseProgress, 'activePlansByCourse' => $activePlansByCourse, 'showOwnedBadge' => true])
            </section>
        @endif

        <section class="card-panel">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Catálogo de cursos</p>
                <h2 class="mt-2 text-2xl font-semibold text-white">Todos os cursos publicados</h2>
                <p class="mt-2 text-sm text-slate-300">{{ $catalogCourses->count() }} curso(s) disponível(is) no catálogo.</p>
            </div>

            @include('dashboard.courses.partials.course-carousel', ['courses' => $catalogCourses, 'accessibleCourseIds' => $accessibleCourseIds, 'courseProgress' => $courseProgress, 'activePlansByCourse' => $activePlansByCourse, 'emptyMessage' => 'Nenhum curso publicado no momento.', 'showOwnedBadge' => true])
        </section>

        @if ($coursesBySphere->isNotEmpty())
            <section class="card-panel">
                <div>
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Esfera</p>
                    <h2 class="mt-2 text-2xl font-semibold text-white">Cursos por esfera</h2>
                </div>

                <div class="mt-6 space-y-4">
                    @foreach ($coursesBySphere as $sphere)
                        <details x-data="{ open: $el.open }" @toggle="open = $el.open" class="rounded-2xl border border-white/10 bg-white/[0.03] transition open:border-sky-400/30 open:bg-sky-400/[0.04]">
                            <summary class="flex cursor-pointer list-none flex-col gap-4 px-5 py-4 marker:hidden sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Esfera {{ $loop->iteration }}</p>
                                    <h3 class="mt-2 text-lg font-semibold text-white">{{ $sphere->name }}</h3>
                                    <p class="mt-1 text-sm text-slate-400">{{ $sphere->courses->count() }} curso(s) publicado(s).</p>
                                </div>
                                <span class="course-accordion-toggle inline-flex min-w-24 items-center justify-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold">
                                    <span x-show="!open">Abrir</span>
                                    <span x-show="open" x-cloak>Fechar</span>
                                    <svg class="h-4 w-4 transition" :class="{ 'rotate-180': open }" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </span>
                            </summary>

                            <div class="border-t border-white/10 px-5 pb-5">
                                @include('dashboard.courses.partials.course-carousel', ['courses' => $sphere->courses, 'accessibleCourseIds' => $accessibleCourseIds, 'courseProgress' => $courseProgress, 'activePlansByCourse' => $activePlansByCourse, 'showOwnedBadge' => true])
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($coursesByEducationLevel->isNotEmpty())
            <section class="card-panel">
                <div>
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Escolaridade</p>
                    <h2 class="mt-2 text-2xl font-semibold text-white">Cursos por escolaridade</h2>
                </div>

                <div class="mt-6 space-y-4">
                    @foreach ($coursesByEducationLevel as $level)
                        <details x-data="{ open: $el.open }" @toggle="open = $el.open" class="rounded-2xl border border-white/10 bg-white/[0.03] transition open:border-sky-400/30 open:bg-sky-400/[0.04]">
                            <summary class="flex cursor-pointer list-none flex-col gap-4 px-5 py-4 marker:hidden sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs uppercase tracking-[0.2em] text-amber-300">Escolaridade {{ $loop->iteration }}</p>
                                    <h3 class="mt-2 text-lg font-semibold text-white">{{ $level->name }}</h3>
                                    <p class="mt-1 text-sm text-slate-400">{{ $level->courses->count() }} curso(s) publicado(s).</p>
                                </div>
                                <span class="course-accordion-toggle inline-flex min-w-24 items-center justify-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold">
                                    <span x-show="!open">Abrir</span>
                                    <span x-show="open" x-cloak>Fechar</span>
                                    <svg class="h-4 w-4 transition" :class="{ 'rotate-180': open }" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </span>
                            </summary>

                            <div class="border-t border-white/10 px-5 pb-5">
                                @include('dashboard.courses.partials.course-carousel', ['courses' => $level->courses, 'accessibleCourseIds' => $accessibleCourseIds, 'courseProgress' => $courseProgress, 'activePlansByCourse' => $activePlansByCourse, 'showOwnedBadge' => true])
                            </div>
                        </details>
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
