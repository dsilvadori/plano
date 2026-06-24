@php
    $playerUrl = $lesson->player_url;
    $isCompleted = $progress->status === 'completed';
    $progressPercentage = (int) ($progressSummary['percentage'] ?? 0);
    $visibleAiArtifacts = $aiArtifacts->where('artifact_type', '!=', 'panda_payload')->keyBy('artifact_type');
    $artifactContent = fn (string $type) => $visibleAiArtifacts->get($type)?->content;

    $contentToText = function (mixed $value) use (&$contentToText): string {
        if (is_string($value)) {
            return trim($value);
        }

        if (! is_array($value)) {
            return '';
        }

        foreach (['text', 'content', 'summary', 'abstract', 'answer', 'description'] as $key) {
            if (isset($value[$key])) {
                $text = $contentToText($value[$key]);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return collect($value)
            ->map(fn ($item) => $contentToText($item))
            ->filter()
            ->join("\n\n");
    };

    $normalizeQuizItems = function (mixed $value) use (&$normalizeQuizItems, $contentToText): array {
        if (! is_array($value)) {
            return [];
        }

        $items = $value['questions'] ?? $value['quiz'] ?? $value['items'] ?? $value;

        if (! is_array($items)) {
            return [];
        }

        if (isset($items['question']) || isset($items['enunciado']) || isset($items['title'])) {
            $items = [$items];
        }

        return collect($items)
            ->filter(fn ($item) => is_array($item) || is_string($item))
            ->map(function ($item, int $index) use ($contentToText) {
                if (is_string($item)) {
                    return [
                        'question' => $item,
                        'options' => [],
                        'answer' => '',
                        'comment' => '',
                    ];
                }

                $options = $item['options'] ?? $item['alternatives'] ?? $item['alternativas'] ?? [];

                return [
                    'question' => $contentToText($item['question'] ?? $item['enunciado'] ?? $item['title'] ?? ('Questão ' . ($index + 1))),
                    'options' => is_array($options) ? array_values($options) : [],
                    'answer' => $contentToText($item['answer'] ?? $item['correct_answer'] ?? $item['gabarito'] ?? ''),
                    'comment' => $contentToText($item['comment'] ?? $item['comentario'] ?? $item['explanation'] ?? ''),
                ];
            })
            ->values()
            ->all();
    };

    $normalizeMindMapBranches = function (mixed $value) use (&$normalizeMindMapBranches, $contentToText): array {
        if (! is_array($value)) {
            return [];
        }

        $items = $value['nodes'] ?? $value['items'] ?? $value['topics'] ?? $value['mindmap'] ?? $value['mind_map'] ?? $value;

        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(function ($item, $key) use ($contentToText) {
                if (is_string($item)) {
                    return ['title' => $item, 'children' => []];
                }

                if (! is_array($item)) {
                    return null;
                }

                $children = $item['children'] ?? $item['items'] ?? $item['topics'] ?? $item['subtopics'] ?? [];

                return [
                    'title' => $contentToText($item['title'] ?? $item['label'] ?? $item['name'] ?? (is_string($key) ? $key : 'Tópico')),
                    'children' => is_array($children)
                        ? collect($children)->map(fn ($child) => $contentToText($child))->filter()->take(4)->values()->all()
                        : [],
                ];
            })
            ->filter(fn ($item) => is_array($item) && $item['title'] !== '')
            ->take(8)
            ->values()
            ->all();
    };

    $summaryText = $contentToText($artifactContent('summary') ?? $artifactContent('abstract') ?? null);
    $transcriptText = $contentToText($artifactContent('transcript') ?? null);
    $quizItems = $normalizeQuizItems($artifactContent('quiz') ?? null);
    $mindMapBranches = $normalizeMindMapBranches($artifactContent('mindmap') ?? $artifactContent('mind_map') ?? null);

    if ($summaryText === '' && $lesson->description) {
        $summaryText = $lesson->description;
    }

    if ($mindMapBranches === [] && $summaryText !== '') {
        $mindMapBranches = collect(preg_split('/(?<=[.!?])\s+/', $summaryText) ?: [])
            ->map(fn ($sentence) => trim($sentence))
            ->filter()
            ->take(5)
            ->map(fn ($sentence) => ['title' => $sentence, 'children' => []])
            ->values()
            ->all();
    }
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

            <div class="lesson-ai-panel mt-6" x-data="{ activeTab: 'summary' }">
                <div class="flex flex-col gap-3 border-b border-white/10 p-5 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Assistente da aula</p>
                        <h2 class="mt-2 text-2xl font-semibold text-white">Estude este conteúdo com IA</h2>
                    </div>
                </div>

                <div class="lesson-ai-tabs" role="tablist" aria-label="Recursos da aula">
                    <button type="button" class="lesson-ai-tab" :class="{ 'is-active': activeTab === 'summary' }" @click="activeTab = 'summary'">Resumo</button>
                    <button type="button" class="lesson-ai-tab" :class="{ 'is-active': activeTab === 'quiz' }" @click="activeTab = 'quiz'">Questões</button>
                    <button type="button" class="lesson-ai-tab" :class="{ 'is-active': activeTab === 'mindmap' }" @click="activeTab = 'mindmap'">Mapa mental</button>
                    <button type="button" class="lesson-ai-tab" :class="{ 'is-active': activeTab === 'question' }" @click="activeTab = 'question'">Tirar dúvidas</button>
                </div>

                <div class="p-5">
                    <div x-show="activeTab === 'summary'" x-cloak>
                        @if ($summaryText !== '')
                            <div class="lesson-ai-prose">
                                @foreach (preg_split('/\n{2,}/', $summaryText) ?: [] as $paragraph)
                                    @if (trim($paragraph) !== '')
                                        <p>{{ trim($paragraph) }}</p>
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <div class="lesson-ai-empty">
                                O resumo desta aula ainda não foi gerado. Quando o artefato `summary` vier do Panda ou da nossa IA, ele aparecerá aqui.
                            </div>
                        @endif

                        @if ($transcriptText !== '')
                            <details class="mt-5 rounded-2xl border border-white/10 bg-slate-950/50 p-4">
                                <summary class="cursor-pointer text-sm font-semibold text-white">Ver transcrição usada pela IA</summary>
                                <div class="lesson-ai-prose mt-4 max-h-80 overflow-auto text-sm">
                                    <p>{{ $transcriptText }}</p>
                                </div>
                            </details>
                        @endif
                    </div>

                    <div x-show="activeTab === 'quiz'" x-cloak>
                        @if ($quizItems !== [])
                            <div class="space-y-4">
                                @foreach ($quizItems as $index => $item)
                                    <article class="rounded-2xl border border-white/10 bg-slate-950/50 p-4">
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-300">Questão {{ $index + 1 }}</p>
                                        <h3 class="mt-2 text-base font-semibold leading-6 text-white">{{ $item['question'] }}</h3>

                                        @if ($item['options'] !== [])
                                            <div class="mt-4 grid gap-2">
                                                @foreach ($item['options'] as $optionIndex => $option)
                                                    <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-slate-200">
                                                        <span class="font-semibold text-sky-100">{{ chr(65 + $optionIndex) }}.</span>
                                                        {{ is_array($option) ? $contentToText($option) : $option }}
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if ($item['answer'] !== '' || $item['comment'] !== '')
                                            <details class="mt-4 rounded-xl border border-emerald-400/20 bg-emerald-400/10 p-3">
                                                <summary class="cursor-pointer text-sm font-semibold text-emerald-100">Ver gabarito e comentário</summary>
                                                @if ($item['answer'] !== '')
                                                    <p class="mt-3 text-sm font-semibold text-emerald-100">Gabarito: {{ $item['answer'] }}</p>
                                                @endif
                                                @if ($item['comment'] !== '')
                                                    <p class="mt-2 text-sm leading-6 text-slate-200">{{ $item['comment'] }}</p>
                                                @endif
                                            </details>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        @else
                            <div class="lesson-ai-empty">
                                As questões desta aula ainda não foram geradas. Quando o artefato `quiz` estiver disponível, elas entram nesta aba com gabarito e comentário.
                            </div>
                        @endif
                    </div>

                    <div x-show="activeTab === 'mindmap'" x-cloak>
                        @if ($mindMapBranches !== [])
                            <div class="lesson-mindmap" aria-label="Mapa mental da aula">
                                <div class="lesson-mindmap-center">
                                    <span>Aula</span>
                                    <strong>{{ $lesson->title }}</strong>
                                </div>
                                <div class="lesson-mindmap-branches">
                                    @foreach ($mindMapBranches as $branch)
                                        <section class="lesson-mindmap-branch">
                                            <h3>{{ $branch['title'] }}</h3>
                                            @if ($branch['children'] !== [])
                                                <ul>
                                                    @foreach ($branch['children'] as $child)
                                                        <li>{{ $child }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </section>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="lesson-ai-empty">
                                O mapa mental ainda não foi gerado. Assim que o artefato `mindmap` vier da IA, ele será apresentado aqui em formato visual.
                            </div>
                        @endif
                    </div>

                    <div x-show="activeTab === 'question'" x-cloak>
                        <div class="rounded-2xl border border-white/10 bg-slate-950/50 p-4">
                            <label for="lesson-ai-question" class="text-sm font-semibold text-white">Sua dúvida sobre a aula</label>
                            <textarea id="lesson-ai-question" rows="4" maxlength="700" class="mt-3 w-full rounded-2xl border-white/10 bg-slate-950/80 text-sm text-slate-100 placeholder:text-slate-500" placeholder="Digite uma pergunta objetiva sobre o conteúdo desta aula"></textarea>
                            <div class="mt-4 rounded-2xl border border-amber-300/20 bg-amber-300/10 p-4 text-sm leading-6 text-amber-100">
                                A interface está pronta para receber o tira-dúvidas. Falta conectar o endpoint de conversa com IA usando a transcrição/resumo desta aula como contexto.
                            </div>
                            <button type="button" disabled class="mt-4 w-full rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-sm font-semibold text-slate-400">
                                Enviar pergunta
                            </button>
                        </div>
                    </div>
                </div>
            </div>
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
