<?php

namespace App\Jobs;

use App\Models\Question;
use App\Services\GeminiQuestionCommentaryGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateQuestionCommentary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public int $questionId,
        public bool $replaceExisting = true,
    ) {}

    public function handle(GeminiQuestionCommentaryGenerator $generator): void
    {
        $question = Question::query()->with('options')->find($this->questionId);

        if (! $question || blank($question->answer_key)) {
            return;
        }

        if (! $this->replaceExisting && filled($question->commentary)) {
            return;
        }

        $commentary = $generator->generate($question);

        $question->forceFill([
            'commentary' => $commentary,
            'commentary_provider' => 'gemini'.($generator->lastModel() ? ':'.$generator->lastModel() : ''),
        ])->save();
    }
}
