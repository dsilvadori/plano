<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel">
            <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Meus cursos</p>
            <h1 class="mt-2 text-3xl font-semibold text-white">Continue de onde parou.</h1>
            <p class="mt-3 max-w-2xl text-sm text-slate-300">Aqui ficam os cursos liberados para o seu usuário. Em breve, esta área também vai mostrar progresso e última aula assistida.</p>
        </div>
    </x-slot>

    <section class="card-panel">
        @if ($courses->isNotEmpty())
            @include('dashboard.courses.partials.course-carousel', ['courses' => $courses, 'forceAccess' => true, 'courseProgress' => $courseProgress, 'activePlansByCourse' => $activePlansByCourse])
        @else
            <div class="card-subtle">
                <p class="text-sm font-semibold text-white">Você ainda não tem cursos liberados.</p>
                <p class="mt-2 text-sm text-slate-400">Quando uma matrícula for vinculada ao seu usuário, o curso aparecerá aqui.</p>
                <a href="{{ route('courses.index') }}" class="mt-4 inline-flex rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">
                    Ver catálogo
                </a>
            </div>
        @endif
    </section>
</x-app-layout>
