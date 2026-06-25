<?php

namespace App\Services;

use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionBank;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class QuestionLessonLinker
{
    public function linkBank(QuestionBank $bank): int
    {
        if (! $bank->course_id) {
            return 0;
        }

        $modules = CourseModule::query()
            ->where('course_id', $bank->course_id)
            ->get();
        $lessons = Lesson::query()
            ->where('course_id', $bank->course_id)
            ->get();

        $updated = 0;

        $bank->questions()->get()->each(function (Question $question) use ($bank, $modules, $lessons, &$updated): void {
            $lesson = $this->bestLessonForQuestion($question, $bank, $lessons);
            $module = $lesson?->module ?: $this->bestModuleForQuestion($question, $bank, $modules);

            if (! $module && ! $lesson) {
                return;
            }

            $question->forceFill([
                'course_id' => $bank->course_id,
                'course_module_id' => $module?->id,
                'lesson_id' => $lesson?->id,
            ])->save();

            $updated++;
        });

        return $updated;
    }

    protected function bestLessonForQuestion(Question $question, QuestionBank $bank, Collection $lessons): ?Lesson
    {
        return $this->bestMatch(
            $lessons,
            $this->questionNeedles($question, $bank),
            fn (Lesson $lesson): string => $lesson->title.' '.$lesson->description
        );
    }

    protected function bestModuleForQuestion(Question $question, QuestionBank $bank, Collection $modules): ?CourseModule
    {
        return $this->bestMatch(
            $modules,
            $this->questionNeedles($question, $bank),
            fn (CourseModule $module): string => $module->name.' '.$module->description
        );
    }

    protected function bestMatch(Collection $records, array $needles, callable $haystackResolver): mixed
    {
        $bestRecord = null;
        $bestScore = 0;

        foreach ($records as $record) {
            $haystack = $this->normalize((string) $haystackResolver($record));
            $score = 0;

            foreach ($needles as $needle) {
                if ($needle === '') {
                    continue;
                }

                if (str_contains($haystack, $needle)) {
                    $score += 6 + strlen($needle);
                }

                foreach (explode(' ', $needle) as $word) {
                    if (strlen($word) >= 4 && str_contains($haystack, $word)) {
                        $score += 2;
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRecord = $record;
            }
        }

        return $bestScore >= 8 ? $bestRecord : null;
    }

    /**
     * @return array<int, string>
     */
    protected function questionNeedles(Question $question, QuestionBank $bank): array
    {
        return collect([
            $question->subtopic,
            $question->topic,
            $question->subject,
            $bank->title,
        ])
            ->map(fn ($value): string => $this->normalize((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/\.(mp4|pdf)\b/', ' ')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\b(a|as|o|os|e|de|da|do|das|dos|em|para|por|com|sem|um|uma|uns|umas|classe|classes|palavra|palavras|questao|questoes|aula)\b/', ' ')
            ->squish()
            ->value();
    }
}
