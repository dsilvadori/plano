@php
    $playerUrl = $lesson->player_url;
    $isCompleted = $progress->status === 'completed';
    $progressPercentage = (int) ($progressSummary['percentage'] ?? 0);
    $providerPriority = ['panda' => 1, 'manual' => 2];
    $visibleAiArtifacts = $aiArtifacts
        ->where('artifact_type', '!=', 'panda_payload')
        ->sortBy(fn ($artifact) => $providerPriority[$artifact->provider] ?? 0)
        ->keyBy('artifact_type');
    $artifactContent = fn (string $type) => $visibleAiArtifacts->get($type)?->content;

    $contentToText = function (mixed $value) use (&$contentToText): string {
        if (is_string($value)) {
            return trim($value);
        }

        if (! is_array($value)) {
            return '';
        }

        foreach (['text', 'content', 'summary', 'abstract', 'answer', 'description', 'enunciate', 'question', 'title', 'theme'] as $key) {
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

    $summaryBlocks = function (string $markdown): array {
        $markdown = trim(str_replace('```', '', $markdown));
        $blocks = [];
        $currentList = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                if ($currentList !== []) {
                    $blocks[] = ['type' => 'list', 'items' => $currentList];
                    $currentList = [];
                }

                continue;
            }

            if (str_starts_with($line, '- ')) {
                $currentList[] = trim(substr($line, 2));

                continue;
            }

            if ($currentList !== []) {
                $blocks[] = ['type' => 'list', 'items' => $currentList];
                $currentList = [];
            }

            $level = str_starts_with($line, '#### ') ? 4 : (str_starts_with($line, '### ') ? 3 : (str_starts_with($line, '## ') ? 2 : (str_starts_with($line, '# ') ? 1 : 0)));

            if ($level > 0) {
                $blocks[] = ['type' => 'heading', 'level' => $level, 'text' => trim(preg_replace('/^#{1,4}\s+/', '', $line))];
            } else {
                $blocks[] = ['type' => 'paragraph', 'text' => $line];
            }
        }

        if ($currentList !== []) {
            $blocks[] = ['type' => 'list', 'items' => $currentList];
        }

        return $blocks;
    };

    $inlineSegments = function (string $text): array {
        $segments = [];
        $offset = 0;

        preg_match_all('/\*\*(.+?)\*\*/s', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $match) {
            [$fullMatch, $position] = $match;

            if ($position > $offset) {
                $segments[] = [
                    'text' => substr($text, $offset, $position - $offset),
                    'bold' => false,
                ];
            }

            $segments[] = [
                'text' => $matches[1][$index][0],
                'bold' => true,
            ];

            $offset = $position + strlen($fullMatch);
        }

        if ($offset < strlen($text)) {
            $segments[] = [
                'text' => substr($text, $offset),
                'bold' => false,
            ];
        }

        return $segments !== [] ? $segments : [['text' => $text, 'bold' => false]];
    };

    $normalizeQuizItems = function (mixed $value) use (&$normalizeQuizItems, $contentToText): array {
        if (! is_array($value)) {
            return [];
        }

        $items = $value['questions'] ?? $value['quiz'] ?? $value['items'] ?? $value;

        if (! is_array($items)) {
            return [];
        }

        if (isset($items['question']) || isset($items['enunciado']) || isset($items['enunciate']) || isset($items['title'])) {
            $items = [$items];
        }

        return collect($items)
            ->filter(fn ($item) => is_array($item) || is_string($item))
            ->values()
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
                $normalizedOptions = is_array($options)
                    ? collect($options)
                        ->map(fn ($option) => [
                            'text' => is_array($option) ? $contentToText($option) : (string) $option,
                            'correct' => is_array($option) ? (bool) ($option['correct'] ?? $option['is_correct'] ?? false) : false,
                        ])
                        ->filter(fn (array $option) => $option['text'] !== '')
                        ->values()
                        ->all()
                    : [];
                $correctOption = collect($normalizedOptions)->firstWhere('correct', true);
                $rawAnswer = $item['answer'] ?? $item['correct_answer'] ?? $item['gabarito'] ?? null;
                $answerBool = null;

                if (is_bool($rawAnswer)) {
                    $answerBool = $rawAnswer;
                } elseif (is_string($rawAnswer)) {
                    $normalizedAnswer = str($rawAnswer)->lower()->ascii()->trim()->toString();

                    if (in_array($normalizedAnswer, ['certo', 'correto', 'true', 'verdadeiro'], true)) {
                        $answerBool = true;
                    } elseif (in_array($normalizedAnswer, ['errado', 'incorreto', 'false', 'falso'], true)) {
                        $answerBool = false;
                    }
                }

                return [
                    'question' => $contentToText($item['question'] ?? $item['enunciado'] ?? $item['enunciate'] ?? $item['title'] ?? ('Questão ' . ($index + 1))),
                    'options' => $normalizedOptions,
                    'answer' => $answerBool === null
                        ? $contentToText($rawAnswer ?? ($correctOption['text'] ?? ''))
                        : ($answerBool ? 'Certo' : 'Errado'),
                    'answer_bool' => $answerBool,
                    'comment' => $contentToText($item['comment'] ?? $item['comentario'] ?? $item['explanation'] ?? ''),
                ];
            })
            ->values()
            ->all();
    };

    $mindMapChildren = function (mixed $children) use (&$mindMapChildren, $contentToText): array {
        if (! is_array($children)) {
            return [];
        }

        return collect($children)
            ->map(function ($child) use (&$mindMapChildren, $contentToText) {
                if (is_string($child)) {
                    return ['title' => $child, 'time' => null, 'children' => []];
                }

                if (! is_array($child)) {
                    return null;
                }

                return [
                    'title' => $contentToText($child['text'] ?? $child['title'] ?? $child['label'] ?? $child['name'] ?? ''),
                    'time' => $child['time'] ?? null,
                    'children' => $mindMapChildren($child['children'] ?? $child['items'] ?? $child['topics'] ?? $child['subtopics'] ?? []),
                ];
            })
            ->filter(fn ($item) => is_array($item) && $item['title'] !== '')
            ->values()
            ->all();
    };

    $normalizeMindMapBranches = function (mixed $value) use ($mindMapChildren, $contentToText): array {
        if (! is_array($value)) {
            return [];
        }

        $root = $value['mindmap'] ?? $value['mind_map'] ?? $value;
        $items = $root['children'] ?? $root['nodes'] ?? $root['items'] ?? $root['topics'] ?? $root;

        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(function ($item, $key) use ($mindMapChildren, $contentToText) {
                if (is_string($item)) {
                    return ['title' => $item, 'time' => null, 'children' => []];
                }

                if (! is_array($item)) {
                    return null;
                }

                $children = $item['children'] ?? $item['items'] ?? $item['topics'] ?? $item['subtopics'] ?? [];

                return [
                    'title' => $contentToText($item['text'] ?? $item['title'] ?? $item['label'] ?? $item['name'] ?? (is_string($key) ? $key : 'Tópico')),
                    'time' => $item['time'] ?? null,
                    'children' => $mindMapChildren($children),
                ];
            })
            ->filter(fn ($item) => is_array($item) && $item['title'] !== '')
            ->values()
            ->all();
    };

    $timeToSeconds = function (mixed $time): ?float {
        if (! is_string($time) || trim($time) === '') {
            return null;
        }

        $parts = array_reverse(explode(':', trim($time)));
        $seconds = 0.0;

        foreach ($parts as $index => $part) {
            if (! is_numeric($part)) {
                return null;
            }

            $seconds += (float) $part * (60 ** $index);
        }

        return round($seconds, 3);
    };

    $summaryText = $contentToText($artifactContent('summary') ?? $artifactContent('abstract') ?? null);
    $transcriptText = $contentToText($artifactContent('transcript') ?? null);
    $quizItems = $normalizeQuizItems($artifactContent('quiz') ?? null);
    $mindMapContent = $artifactContent('mindmap') ?? $artifactContent('mind_map') ?? null;
    $mindMapTitle = is_array($mindMapContent) ? $contentToText($mindMapContent['theme'] ?? $mindMapContent['title'] ?? null) : '';
    $mindMapBranches = $normalizeMindMapBranches($mindMapContent);

    $summaryBlocks = $summaryText !== '' ? $summaryBlocks($summaryText) : [];
    $mindMapTitle = $mindMapTitle !== '' ? $mindMapTitle : $lesson->title;

    $summaryTimeline = [];
    $appendTimelineItem = function (array $item, int $depth = 0) use (&$appendTimelineItem, &$summaryTimeline, $timeToSeconds): void {
        $seconds = $timeToSeconds($item['time'] ?? null);

        if (($item['time'] ?? null) && $seconds !== null) {
            $summaryTimeline[] = [
                'title' => $item['title'],
                'time' => $item['time'],
                'seconds' => $seconds,
                'depth' => min($depth, 2),
            ];
        }

        foreach ($item['children'] ?? [] as $child) {
            if (is_array($child)) {
                $appendTimelineItem($child, $depth + 1);
            }
        }
    };

    foreach ($mindMapBranches as $branch) {
        $appendTimelineItem($branch);
    }

    $summaryTimelineIndex = 0;
    $usedSummaryTimelineIndexes = [];
    $summaryTextTokens = function (string $text): array {
        $normalized = str($text)->lower()->ascii()->replaceMatches('/[^a-z0-9\s]+/', ' ')->squish()->toString();

        return collect(explode(' ', $normalized))
            ->filter(fn (string $word) => strlen($word) >= 4)
            ->unique()
            ->values()
            ->all();
    };
    $timelineForSummaryText = function (string $text, bool $allowSequentialFallback = true) use (&$summaryTimelineIndex, &$usedSummaryTimelineIndexes, $summaryTimeline, $summaryTextTokens): ?array {
        if ($summaryTimeline === []) {
            return null;
        }

        $textTokens = $summaryTextTokens($text);

        if ($textTokens !== []) {
            foreach ($summaryTimeline as $index => $item) {
                if (in_array($index, $usedSummaryTimelineIndexes, true)) {
                    continue;
                }

                $itemTokens = $summaryTextTokens($item['title']);
                $sharedTokens = count(array_intersect($textTokens, $itemTokens));
                $minimumTokens = max(1, min(count($textTokens), count($itemTokens)));

                if ($sharedTokens / $minimumTokens >= 0.35) {
                    $usedSummaryTimelineIndexes[] = $index;

                    return $item;
                }
            }
        }

        if (! $allowSequentialFallback) {
            return null;
        }

        while (isset($summaryTimeline[$summaryTimelineIndex]) && in_array($summaryTimelineIndex, $usedSummaryTimelineIndexes, true)) {
            $summaryTimelineIndex++;
        }

        if (! isset($summaryTimeline[$summaryTimelineIndex])) {
            return null;
        }

        $usedSummaryTimelineIndexes[] = $summaryTimelineIndex;

        return $summaryTimeline[$summaryTimelineIndex++];
    };

    $cachedAiTabs = [
        'summary' => $summaryBlocks !== [],
        'quiz' => $quizItems !== [],
        'mindmap' => $mindMapBranches !== [],
        'question' => (bool) $pandaTutorUrl,
    ];
    $hasLessonAiPanel = in_array(true, $cachedAiTabs, true);
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

    <div class="grid gap-6 xl:grid-cols-[1fr_20rem]" x-data="lessonCompletion(@js($isCompleted))">
        <section class="card-panel">
            @if (session('status'))
                <div class="mb-6 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif
            @if (session('error'))
                <div class="mb-6 rounded-2xl border border-rose-400/20 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">
                    {{ session('error') }}
                </div>
            @endif

            <div id="lesson-video-frame" class="overflow-hidden rounded-3xl border border-white/10 bg-slate-950">
                @if ($playerUrl && in_array($lesson->type, ['video', 'mixed'], true))
                    <iframe
                        id="lesson-player"
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
                        <h2 class="mt-3 text-2xl font-semibold text-white">Entrará em breve</h2>
                        <p class="mt-3 max-w-lg text-sm text-slate-400">A mídia desta aula entrará em breve. Você já pode navegar pela trilha e acompanhar o planejamento do curso.</p>
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
                        <span x-show="completed" x-cloak class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-200">Concluída</span>
                        <span x-show="!completed" x-cloak class="rounded-full border border-amber-300/20 bg-amber-300/10 px-3 py-1 text-xs font-semibold text-amber-200">Em andamento</span>
                    </div>

                    @if ($lesson->description && $lesson->description !== 'Aula importada por planilha.')
                        <p class="mt-4 text-sm leading-6 text-slate-300">{{ $lesson->description }}</p>
                    @endif
                    <p x-show="error" x-text="error" x-cloak class="mt-3 text-sm font-semibold text-rose-200"></p>
                </div>

                <form method="POST" action="{{ route('courses.lessons.complete', [$course->slug, $lesson]) }}" class="shrink-0" @submit.prevent="toggle">
                    @csrf
                    <button type="submit" class="w-full rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20 disabled:cursor-wait disabled:opacity-70" :disabled="loading">
                        <span x-text="loading ? 'Atualizando...' : buttonLabel">{{ $isCompleted ? 'Desmarcar como concluída' : 'Marcar como concluída' }}</span>
                    </button>
                </form>
            </div>

            @if ($hasLessonAiPanel)
                <div
                    class="lesson-ai-panel mt-6"
                    x-data="{
                        activeTab: null,
                        cachedTabs: @js($cachedAiTabs),
                        readyTabs: {},
                        loadingTabs: {},
                        loadingTimers: {},
                        tutorTabVisible: @js((bool) $pandaTutorUrl),
                        tutorUrl: @js($pandaTutorUrl),
                        tutorCandidateUrl: @js($pandaTutorCandidateUrl),
                        tutorConfigUrl: @js($pandaTutorConfigUrl),
                        openAiTab(tab) {
                            this.activeTab = tab;

                            if (this.readyTabs[tab]) {
                                return;
                            }

                            if (! this.cachedTabs[tab] || tab === 'question') {
                                this.readyTabs[tab] = true;
                                return;
                            }

                            this.loadingTabs[tab] = true;
                            clearTimeout(this.loadingTimers[tab]);
                            this.loadingTimers[tab] = setTimeout(() => {
                                this.readyTabs[tab] = true;
                                this.loadingTabs[tab] = false;
                            }, 2500);
                        },
                        isTabLoading(tab) {
                            return this.activeTab === tab && this.loadingTabs[tab] && ! this.readyTabs[tab];
                        },
                        isTabReady(tab) {
                            return this.activeTab === tab && this.readyTabs[tab];
                        },
                        seekLessonVideo(seconds) {
                            const frame = document.getElementById('lesson-player');
                            const wrapper = document.getElementById('lesson-video-frame');

                            if (! frame?.contentWindow) return;

                            frame.contentWindow.postMessage({ type: 'currentTime', parameter: seconds }, '*');
                            frame.contentWindow.postMessage({ type: 'play' }, '*');
                            wrapper?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        },
                    }"
                >
                    <div class="flex flex-col gap-3 border-b border-white/10 p-5 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Assistente da aula</p>
                            <h2 class="mt-2 text-2xl font-semibold text-white">Estude este conteúdo com IA</h2>
                        </div>
                        @if ($summaryBlocks !== [])
                            <a href="{{ route('courses.lessons.summary.pdf', [$course->slug, $lesson]) }}" class="inline-flex items-center justify-center rounded-2xl border border-amber-300/30 bg-amber-300/10 px-4 py-2 text-sm font-semibold text-amber-100 transition hover:border-amber-200/50 hover:bg-amber-300/20">
                                Baixar resumo em PDF
                            </a>
                        @endif
                    </div>

                    <div class="lesson-ai-tabs" role="tablist" aria-label="Recursos da aula">
                        @if ($summaryBlocks !== [])
                            <button type="button" class="lesson-ai-tab" :class="{ 'is-active': activeTab === 'summary' }" @click="openAiTab('summary')">Resumo</button>
                        @endif
                        @if ($quizItems !== [])
                            <button type="button" class="lesson-ai-tab" :class="{ 'is-active': activeTab === 'quiz' }" @click="openAiTab('quiz')">Questões para fixação</button>
                        @endif
                        @if ($mindMapBranches !== [])
                            <button type="button" class="lesson-ai-tab" :class="{ 'is-active': activeTab === 'mindmap' }" @click="openAiTab('mindmap')">Mapa mental</button>
                        @endif
                        @if ($pandaTutorUrl)
                            <button type="button" class="lesson-ai-tab" :class="{ 'is-active': activeTab === 'question' }" @click="openAiTab('question')">Tirar dúvidas</button>
                        @endif
                    </div>

                    <div class="p-5">
                        @if ($summaryBlocks !== [])
                            <div x-show="isTabLoading('summary')" x-cloak class="lesson-ai-loading">
                                <span></span>
                                Gerando resumo da aula...
                            </div>

                            <div x-show="isTabReady('summary')" x-cloak>
                                <div class="lesson-ai-prose">
                                    @foreach ($summaryBlocks as $block)
                                        @if ($block['type'] === 'heading')
                                            @if ($block['level'] <= 1)
                                                <h2>
                                                    @foreach ($inlineSegments($block['text']) as $segment)
                                                        @if ($segment['bold'])
                                                            <strong>{{ $segment['text'] }}</strong>
                                                        @else
                                                            {{ $segment['text'] }}
                                                        @endif
                                                    @endforeach
                                                </h2>
                                            @elseif ($block['level'] === 2)
                                                <h3>
                                                    @foreach ($inlineSegments($block['text']) as $segment)
                                                        @if ($segment['bold'])
                                                            <strong>{{ $segment['text'] }}</strong>
                                                        @else
                                                            {{ $segment['text'] }}
                                                        @endif
                                                    @endforeach
                                                </h3>
                                            @else
                                                <h4>
                                                    @foreach ($inlineSegments($block['text']) as $segment)
                                                        @if ($segment['bold'])
                                                            <strong>{{ $segment['text'] }}</strong>
                                                        @else
                                                            {{ $segment['text'] }}
                                                        @endif
                                                    @endforeach
                                                </h4>
                                            @endif
                                        @elseif ($block['type'] === 'list')
                                            <ul>
                                                @foreach ($block['items'] as $item)
                                                    <li>
                                                        @foreach ($inlineSegments($item) as $segment)
                                                            @if ($segment['bold'])
                                                                <strong>{{ $segment['text'] }}</strong>
                                                            @else
                                                                {{ $segment['text'] }}
                                                            @endif
                                                        @endforeach
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <p>
                                                @foreach ($inlineSegments($block['text']) as $segment)
                                                    @if ($segment['bold'])
                                                        <strong>{{ $segment['text'] }}</strong>
                                                    @else
                                                        {{ $segment['text'] }}
                                                    @endif
                                                @endforeach
                                            </p>
                                        @endif
                                    @endforeach
                                </div>

                                @if ($transcriptText !== '')
                                    <details class="mt-5 rounded-2xl border border-white/10 bg-slate-950/50 p-4">
                                        <summary class="cursor-pointer text-sm font-semibold text-white">Ver transcrição usada pela IA</summary>
                                        <div class="lesson-ai-prose mt-4 max-h-80 overflow-auto text-sm">
                                            <p>{{ $transcriptText }}</p>
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @endif

                        @if ($quizItems !== [])
                            <div x-show="isTabLoading('quiz')" x-cloak class="lesson-ai-loading">
                                <span></span>
                                Gerando questões da aula...
                            </div>

                            <div x-show="isTabReady('quiz')" x-cloak>
                                <div class="space-y-4">
                                    @foreach ($quizItems as $index => $item)
                                        <article class="rounded-2xl border border-white/10 bg-slate-950/50 p-4" x-data="{ revealed: false, selected: null }">
                                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-300">Questão {{ $index + 1 }}</p>
                                            <h3 class="mt-2 text-base font-semibold leading-6 text-white">{{ $item['question'] }}</h3>

                                            @if ($item['options'] !== [])
                                                <div class="mt-4 grid gap-2">
                                                    @foreach ($item['options'] as $optionIndex => $option)
                                                        <button
                                                            type="button"
                                                            @click="selected = {{ $optionIndex }}; revealed = true"
                                                            class="w-full rounded-xl border px-3 py-2 text-left text-sm transition"
                                                            :class="revealed && {{ $option['correct'] ? 'true' : 'false' }}
                                                                ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-100'
                                                                : (selected === {{ $optionIndex }} ? 'border-rose-400/30 bg-rose-400/10 text-rose-100' : 'border-white/10 bg-white/5 text-slate-200 hover:border-sky-400/30 hover:bg-sky-400/10')"
                                                        >
                                                            <span class="font-semibold text-sky-100">{{ chr(65 + $optionIndex) }}.</span>
                                                            {{ $option['text'] }}
                                                        </button>
                                                    @endforeach
                                                </div>
                                            @elseif ($item['answer_bool'] !== null)
                                                <div class="mt-4 grid grid-cols-2 gap-2 sm:max-w-sm">
                                                    <button type="button" @click="selected = true; revealed = true" class="rounded-xl border px-3 py-2 text-center text-sm font-semibold transition" :class="revealed && {{ $item['answer_bool'] ? 'true' : 'false' }} ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-100' : (selected === true ? 'border-rose-400/30 bg-rose-400/10 text-rose-100' : 'border-white/10 bg-white/5 text-slate-300 hover:border-sky-400/30 hover:bg-sky-400/10')">
                                                        Certo
                                                    </button>
                                                    <button type="button" @click="selected = false; revealed = true" class="rounded-xl border px-3 py-2 text-center text-sm font-semibold transition" :class="revealed && {{ ! $item['answer_bool'] ? 'true' : 'false' }} ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-100' : (selected === false ? 'border-rose-400/30 bg-rose-400/10 text-rose-100' : 'border-white/10 bg-white/5 text-slate-300 hover:border-sky-400/30 hover:bg-sky-400/10')">
                                                        Errado
                                                    </button>
                                                </div>
                                            @endif

                                            @if ($item['answer'] !== '' || $item['comment'] !== '')
                                                <div x-show="revealed" x-transition class="mt-4 rounded-xl border border-emerald-400/20 bg-emerald-400/10 p-3">
                                                    @if ($item['answer'] !== '')
                                                        <p class="mt-3 text-sm font-semibold text-emerald-100">Gabarito: {{ $item['answer'] }}</p>
                                                    @endif
                                                    @if ($item['comment'] !== '')
                                                        <p class="mt-2 text-sm leading-6 text-slate-200">{{ $item['comment'] }}</p>
                                                    @endif
                                                </div>
                                            @endif
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($mindMapBranches !== [])
                            <div x-show="isTabLoading('mindmap')" x-cloak class="lesson-ai-loading">
                                <span></span>
                                Gerando mapa mental da aula...
                            </div>

                            <div x-show="isTabReady('mindmap')" x-cloak>
                                <div class="lesson-mindmap" aria-label="Mapa mental da aula">
                                    <div class="lesson-mindmap-center">
                                        <span>Mapa mental</span>
                                        <strong>{{ $mindMapTitle }}</strong>
                                    </div>
                                    <div class="lesson-mindmap-branches">
                                        @foreach ($mindMapBranches as $branch)
                                            <section class="lesson-mindmap-branch lesson-mindmap-tone-{{ ($loop->index % 6) + 1 }}">
                                                <h3>{{ $branch['title'] }}</h3>
                                                @if ($branch['children'] !== [])
                                                    <ul>
                                                        @foreach ($branch['children'] as $child)
                                                            <li>
                                                                <strong>{{ $child['title'] }}</strong>
                                                                @if ($child['children'] !== [])
                                                                    <small>
                                                                        @foreach (collect($child['children'])->take(3) as $grandchild)
                                                                            <span>{{ $grandchild['title'] }}</span>
                                                                            @unless ($loop->last)
                                                                                <span aria-hidden="true"> · </span>
                                                                            @endunless
                                                                        @endforeach
                                                                    </small>
                                                                @endif
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </section>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if ($pandaTutorUrl)
                            <div x-show="isTabReady('question')" x-cloak>
                                <div x-show="tutorUrl" class="lesson-tutor-frame">
                                    <iframe
                                        :src="tutorUrl || 'about:blank'"
                                        title="Tutor da aula"
                                        class="h-full w-full"
                                        allow="clipboard-write"
                                        referrerpolicy="strict-origin-when-cross-origin"
                                    ></iframe>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if ($lessonQuestionLinks->isNotEmpty())
                <div class="mt-6 rounded-3xl border border-sky-400/20 bg-sky-400/10 p-5">
                    <p class="text-sm uppercase tracking-[0.25em] text-sky-200">Resolução de questões</p>
                    <h2 class="mt-2 text-xl font-semibold text-white">Pratique com os bancos vinculados a este conteúdo.</h2>
                    <div class="mt-4 space-y-2">
                        @foreach ($lessonQuestionLinks as $questionLink)
                            <a href="{{ $questionLink['url'] }}" class="block rounded-xl border border-sky-400/20 bg-slate-950/40 px-3 py-2 text-sm font-semibold text-sky-100 transition hover:border-sky-300/40 hover:bg-sky-400/15">
                                <span class="block font-semibold">{{ $questionLink['label'] }}</span>
                                <span class="mt-1 block text-xs text-sky-100/80">{{ $questionLink['scope'] }} · resolva no seu ritmo e volte para continuar a aula.</span>
                            </a>
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

                                @if ($planItem->type === 'questions' && $lessonQuestionLinks->isNotEmpty())
                                    <div class="mt-3 space-y-2">
                                        @foreach ($lessonQuestionLinks as $questionLink)
                                            <a href="{{ $questionLink['url'] }}" class="block rounded-xl border border-sky-400/20 bg-sky-400/10 px-3 py-2 text-sm font-semibold text-sky-100 transition hover:border-sky-300/40 hover:bg-sky-400/15">
                                                <span class="block font-semibold">{{ $questionLink['label'] }}</span>
                                                <span class="mt-1 block text-xs text-sky-100/80">{{ $questionLink['scope'] }} · abrir área de questões</span>
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif ($trackLessonContext)
                <div class="card-panel">
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Aulas da trilha</p>
                    <p class="mt-3 text-lg font-semibold text-white">{{ $trackLessonContext['track']->name }}</p>

                    <div class="mt-5 space-y-2">
                        @foreach ($trackLessonContext['lessons'] as $trackLesson)
                            @php
                                $isCurrentTrackLesson = $trackLesson->is($lesson);
                            @endphp
                            <a href="{{ route('courses.lessons.show', [$course->slug, $trackLesson]) }}" class="block rounded-xl border px-3 py-2 text-sm {{ $isCurrentTrackLesson ? 'border-amber-300/40 bg-amber-300/15 text-amber-100' : 'border-white/10 bg-slate-950/40 text-slate-200' }}">
                                <span class="block font-semibold">{{ $trackLesson->title }}</span>
                                @if ($trackLesson->duration_minutes > 0)
                                    <span class="mt-1 block text-xs {{ $isCurrentTrackLesson ? 'text-amber-100/80' : 'text-slate-400' }}">
                                        {{ $trackLesson->duration_minutes }} min
                                    </span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="card-panel">
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Status</p>
                <p class="mt-3 text-2xl font-semibold text-white" x-text="statusLabel">{{ $isCompleted ? 'Aula concluída' : 'Em andamento' }}</p>
                <p class="mt-2 text-sm text-slate-400">Ao concluir, o progresso do curso é atualizado automaticamente nos cards e na página do curso.</p>
            </div>
        </aside>
    </div>
</x-app-layout>
