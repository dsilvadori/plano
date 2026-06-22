@php
    $playerUrl = $lesson->player_url;
    $isCompleted = $progress->status === 'completed';
    $progressPercentage = (int) ($progressSummary['percentage'] ?? 0);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">{{ $lesson->module?->name ?: 'Aula' }}</p>
                    <h1 class="mt-2 max-w-4xl text-3xl font-semibold text-white">{{ $lesson->title }}</h1>
                    <p class="mt-3 max-w-2xl text-sm text-slate-300">{{ $course->name }}</p>
                </div>
                <a href="{{ route('courses.show', $course->slug) }}" class="rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-sm font-semibold text-slate-100">
                    Voltar ao curso
                </a>
            </div>

            <div class="mt-6 rounded-2xl border border-sky-400/20 bg-sky-400/10 p-4">
                <div class="mb-2 flex items-center justify-between text-sm font-semibold text-sky-100">
                    <span>{{ $progressSummary['completed'] }} de {{ $progressSummary['total'] }} aula(s) concluída(s)</span>
                    <span>{{ $progressPercentage }}%</span>
                </div>
                <div class="h-3 overflow-hidden rounded-full bg-slate-800">
                    <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $progressPercentage) }}%"></div>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <section class="card-panel">
            @if (session('status'))
                <div class="mb-6 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-950">
                @if ($playerUrl && in_array($lesson->type, ['video', 'mixed'], true))
                    <iframe
                        src="{{ $playerUrl }}"
                        title="{{ $lesson->title }}"
                        class="aspect-video w-full"
                        allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                        sandbox="allow-scripts allow-same-origin allow-presentation"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allowfullscreen
                    ></iframe>
                @else
                    <div class="flex aspect-video flex-col items-center justify-center p-8 text-center">
                        <p class="text-sm uppercase tracking-[0.25em] text-amber-300">{{ ucfirst($lesson->type) }}</p>
                        <h2 class="mt-3 text-2xl font-semibold text-white">Conteúdo em preparação</h2>
                        <p class="mt-3 max-w-lg text-sm text-slate-400">O player Panda, PDF ou material digital será exibido aqui assim que estiver cadastrado para esta aula.</p>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex flex-col gap-4 rounded-3xl border border-white/10 bg-white/5 p-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1 text-xs font-semibold text-sky-100">{{ match ($lesson->type) {
                            'video' => 'Vídeo',
                            'pdf' => 'PDF',
                            'mixed' => 'Mista',
                            'text' => 'Texto',
                            'quiz' => 'Questões',
                            default => $lesson->type,
                        } }}</span>
                        <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-slate-200">{{ $lesson->duration_minutes }} min</span>
                        @if ($isCompleted)
                            <span class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-200">Concluída</span>
                        @endif
                    </div>

                    @if ($lesson->description)
                        <p class="mt-4 text-sm leading-6 text-slate-300">{{ $lesson->description }}</p>
                    @endif
                </div>

                <form method="POST" action="{{ route('courses.lessons.complete', [$course->slug, $lesson]) }}" class="shrink-0">
                    @csrf
                    <button type="submit" class="w-full rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">
                        {{ $isCompleted ? 'Marcar concluída novamente' : 'Marcar como concluída' }}
                    </button>
                </form>
            </div>

            @if ($aiArtifacts->isNotEmpty())
                <div class="mt-6 rounded-3xl border border-white/10 bg-white/5 p-5">
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Recursos de IA</p>
                    <div class="mt-4 space-y-3">
                        @foreach ($aiArtifacts->where('artifact_type', '!=', 'panda_payload') as $artifact)
                            <details class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <summary class="cursor-pointer text-sm font-semibold text-white">
                                    {{ match ($artifact->artifact_type) {
                                        'summary' => 'Resumo',
                                        'transcript' => 'Transcrição',
                                        'chapters' => 'Capítulos',
                                        'quiz' => 'Questões',
                                        'mindmap' => 'Mapa mental',
                                        'raw_ai' => 'IA do Panda',
                                        default => ucfirst($artifact->artifact_type),
                                    } }}
                                </summary>
                                <pre class="mt-3 max-h-80 overflow-auto whitespace-pre-wrap rounded-2xl bg-slate-950 p-4 text-xs leading-5 text-slate-300">{{ json_encode($artifact->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        <aside class="space-y-4">
            <div class="card-panel">
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Navegação</p>
                <div class="mt-5 space-y-3">
                    @if ($previousLesson)
                        <a href="{{ route('courses.lessons.show', [$course->slug, $previousLesson]) }}" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-100">
                            Aula anterior
                        </a>
                    @endif

                    @if ($nextLesson)
                        <a href="{{ route('courses.lessons.show', [$course->slug, $nextLesson]) }}" class="block rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">
                            Próxima aula
                        </a>
                    @endif

                    @unless ($previousLesson || $nextLesson)
                        <p class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-slate-400">Esta é a única aula publicada neste curso.</p>
                    @endunless
                </div>
            </div>

            @if ($planLessonContext)
                <div class="card-panel">
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Trilha do plano</p>
                    <p class="mt-3 text-lg font-semibold text-white">{{ $planLessonContext['date_label'] }}</p>

                    <a href="{{ route('study-plans.show', $planLessonContext['plan']) }}" class="mt-4 block rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-center text-sm font-semibold text-sky-100">
                        Voltar ao plano
                    </a>

                    <div class="mt-5 space-y-3">
                        @foreach ($planLessonContext['items'] as $planItem)
                            <div class="rounded-2xl border {{ $planItem->id === $planLessonContext['current_item_id'] ? 'border-amber-300/40 bg-amber-300/10' : 'border-white/10 bg-white/5' }} p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ $planItem->display_title }}</p>

                                @if ($planItem->lessons->isNotEmpty())
                                    <div class="mt-3 space-y-2">
                                        @foreach ($planItem->orderedLessonsForDisplay() as $planLesson)
                                            @php
                                                $isCurrentPlanLesson = $planLesson->is($lesson);
                                                $isCompletedPlanLesson = in_array($planLesson->id, $planLessonContext['completed_lesson_ids'], true);
                                            @endphp
                                            <a href="{{ route('courses.lessons.show', [$course->slug, $planLesson]) }}" class="block rounded-xl border px-3 py-2 text-sm {{ $isCurrentPlanLesson ? 'border-amber-300/40 bg-amber-300/15 text-amber-100' : 'border-white/10 bg-slate-950/40 text-slate-200' }}">
                                                <span class="block font-semibold">{{ $planLesson->title }}</span>
                                                <span class="mt-1 block text-xs {{ $isCurrentPlanLesson ? 'text-amber-100/80' : 'text-slate-400' }}">
                                                    {{ $planLesson->duration_minutes }} min{{ $isCompletedPlanLesson ? ' · concluída' : '' }}
                                                </span>
                                            </a>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="mt-2 text-sm text-slate-400">{{ $planItem->estimated_minutes }} min reservados</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="card-panel">
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Status</p>
                <p class="mt-3 text-2xl font-semibold text-white">{{ $isCompleted ? 'Aula concluída' : 'Em andamento' }}</p>
                <p class="mt-2 text-sm text-slate-400">Ao concluir, o progresso do curso é atualizado automaticamente nos cards e na página do curso.</p>
            </div>
        </aside>
    </div>
</x-app-layout>
