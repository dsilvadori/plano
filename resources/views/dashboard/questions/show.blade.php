<x-app-layout>
    <x-slot name="header">
        <div class="hero-panel">
            <p class="text-sm uppercase tracking-[0.25em] text-amber-300">
                {{ $bank->modules->pluck('name')->merge($bank->tracks->pluck('name'))->merge($bank->lessons->pluck('title'))->take(2)->join(' / ') ?: 'Banco de questões' }}
            </p>
            <h1 class="mt-2 text-3xl font-semibold text-white">{{ $bank->title }}</h1>
            <p class="mt-3 max-w-2xl text-sm text-slate-300">{{ $questions->count() }} questão(ões) para praticar.</p>
            @if ($returnPlan)
                <a href="{{ route('study-plans.show', $returnPlan) }}" class="mt-4 inline-flex rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">
                    Voltar para o plano
                </a>
            @endif
        </div>
    </x-slot>

    <div class="space-y-5">
        @forelse ($questions as $question)
            @php
                $attempt = $question->attempts->first();
                $questionImageUrls = collect(data_get($question->metadata, 'image_urls', []))->filter()->values();
                $questionImageDescription = data_get($question->metadata, 'image_description');
            @endphp
            <article id="questao-{{ $question->id }}" class="card-panel" data-question-card="{{ $question->id }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-300">Questão {{ $question->number }}</p>
                        @if ($question->topic)
                            <p class="mt-1 text-xs font-semibold uppercase tracking-[0.16em] text-sky-200">{{ $question->topic }}</p>
                        @endif
                    </div>
                    <span id="questao-status-{{ $question->id }}" @class([
                        'rounded-full border px-3 py-1 text-xs font-semibold',
                        'hidden' => ! $attempt,
                        'border-emerald-400/30 bg-emerald-400/10 text-emerald-100' => $attempt?->is_correct,
                        'border-rose-400/30 bg-rose-400/10 text-rose-100' => $attempt && ! $attempt->is_correct,
                    ])>
                        {{ $attempt?->is_correct ? 'Acertou' : 'Errou' }}
                    </span>
                </div>

                <div class="question-rich-text mt-5 text-base leading-8 text-slate-100">
                    {{ \App\Support\QuestionTextRenderer::render($question->statement) }}
                </div>

                @if ($questionImageUrls->isNotEmpty() || filled($questionImageDescription))
                    <div class="mt-5 grid gap-3">
                        @foreach ($questionImageUrls as $imageUrl)
                            <img
                                src="{{ $imageUrl }}"
                                alt="Imagem da questão {{ $question->number }}"
                                class="question-image"
                                loading="lazy"
                            >
                        @endforeach

                        @if (filled($questionImageDescription))
                            <div class="rounded-2xl border border-amber-300/20 bg-amber-300/10 p-4 text-sm leading-6 text-amber-50">
                                {{ $questionImageDescription }}
                            </div>
                        @endif
                    </div>
                @endif

                <div class="mt-6 grid gap-3">
                    @foreach ($question->options as $option)
                        @php
                            $isSelected = $attempt?->question_option_id === $option->id;
                            $showResult = (bool) $attempt;
                        @endphp
                        <form method="POST" action="{{ route('questions.answer', $question) }}" data-question-answer-form>
                            @csrf
                            <input type="hidden" name="question_option_id" value="{{ $option->id }}">
                            <input type="hidden" name="return_url" value="{{ request()->fullUrl() }}">
                            <button
                                type="submit"
                                data-option-id="{{ $option->id }}"
                                @class([
                                    'w-full rounded-2xl border px-4 py-4 text-left text-base leading-7 transition',
                                    'border-emerald-400/30 bg-emerald-400/10 text-emerald-100' => $showResult && $option->is_correct,
                                    'border-rose-400/30 bg-rose-400/10 text-rose-100' => $showResult && $isSelected && ! $option->is_correct,
                                    'border-white/10 bg-white/5 text-slate-200 hover:border-sky-400/30 hover:bg-sky-400/10' => ! ($showResult && ($option->is_correct || $isSelected)),
                                ])
                            >
                                <span class="font-semibold text-sky-100">{{ strtoupper($option->label) }}.</span>
                                <span>{{ \App\Support\QuestionTextRenderer::renderInline($option->text) }}</span>
                            </button>
                        </form>
                    @endforeach
                </div>

                <div id="gabarito-{{ $question->id }}" @class([
                    'mt-5 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-4',
                    'hidden' => ! $attempt,
                ])>
                    <p id="gabarito-label-{{ $question->id }}" class="text-sm font-semibold text-emerald-100">
                        @if ($question->answer_key)
                            Gabarito: {{ strtoupper($question->answer_key) }}
                        @endif
                    </p>
                    <div id="comentario-{{ $question->id }}" class="question-rich-text question-commentary mt-2 text-sm leading-6 text-slate-200">
                        @if ($attempt)
                            {{ \App\Support\QuestionTextRenderer::renderCommentary($question->commentary ?: 'Comentário em preparação.') }}
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="card-panel">
                <p class="text-sm text-slate-300">Este banco ainda não tem questões publicadas.</p>
            </div>
        @endforelse
    </div>

    <script>
        (() => {
            const baseClass = 'w-full rounded-2xl border px-4 py-4 text-left text-base leading-7 transition';
            const neutralClass = `${baseClass} border-white/10 bg-white/5 text-slate-200 hover:border-sky-400/30 hover:bg-sky-400/10`;
            const correctClass = `${baseClass} border-emerald-400/30 bg-emerald-400/10 text-emerald-100`;
            const wrongClass = `${baseClass} border-rose-400/30 bg-rose-400/10 text-rose-100`;

            document.querySelectorAll('[data-question-answer-form]').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    if (! window.fetch) {
                        return;
                    }

                    event.preventDefault();

                    const article = form.closest('[data-question-card]');
                    const buttons = article ? [...article.querySelectorAll('[data-option-id]')] : [];

                    buttons.forEach((button) => {
                        button.disabled = true;
                    });

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form),
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        if (! response.ok) {
                            throw new Error('answer-request-failed');
                        }

                        const payload = await response.json();
                        const correctOptionIds = new Set((payload.correct_option_ids || []).map(Number));

                        buttons.forEach((button) => {
                            const optionId = Number(button.dataset.optionId);

                            button.className = correctOptionIds.has(optionId)
                                ? correctClass
                                : (optionId === Number(payload.selected_option_id) ? wrongClass : neutralClass);
                        });

                        const status = document.getElementById(`questao-status-${payload.question_id}`);

                        if (status) {
                            status.textContent = payload.is_correct ? 'Acertou' : 'Errou';
                            status.className = payload.is_correct
                                ? 'rounded-full border px-3 py-1 text-xs font-semibold border-emerald-400/30 bg-emerald-400/10 text-emerald-100'
                                : 'rounded-full border px-3 py-1 text-xs font-semibold border-rose-400/30 bg-rose-400/10 text-rose-100';
                        }

                        const answer = document.getElementById(`gabarito-${payload.question_id}`);
                        const answerLabel = document.getElementById(`gabarito-label-${payload.question_id}`);
                        const commentary = document.getElementById(`comentario-${payload.question_id}`);

                        if (answer) {
                            answer.classList.remove('hidden');
                        }

                        if (answerLabel && payload.answer_key) {
                            answerLabel.textContent = `Gabarito: ${payload.answer_key}`;
                        }

                        if (commentary) {
                            commentary.innerHTML = payload.commentary_html || payload.commentary || 'Comentário em preparação.';
                        }
                    } catch (error) {
                        form.submit();

                        return;
                    } finally {
                        buttons.forEach((button) => {
                            button.disabled = false;
                        });
                    }
                });
            });
        })();
    </script>
</x-app-layout>
