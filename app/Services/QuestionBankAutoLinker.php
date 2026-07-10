<?php

namespace App\Services;

use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\QuestionBank;
use App\Support\LessonTitleNormalizer;

class QuestionBankAutoLinker
{
    public function linkAll(?callable $progress = null): array
    {
        $totals = [
            'banks' => 0,
            'modules' => 0,
            'tracks' => 0,
            'lessons' => 0,
        ];

        QuestionBank::query()
            ->orderBy('id')
            ->chunkById(100, function ($banks) use (&$totals, $progress): void {
                foreach ($banks as $bank) {
                    $result = $this->link($bank);

                    $totals['banks']++;
                    $totals['modules'] += $result['modules'];
                    $totals['tracks'] += $result['tracks'];
                    $totals['lessons'] += $result['lessons'];

                    if ($progress) {
                        $progress($bank, $result);
                    }
                }
            });

        return $totals;
    }

    public function link(QuestionBank $bank): array
    {
        $matches = $this->matchesFor($bank);

        return [
            'modules' => count($bank->modules()->syncWithoutDetaching($matches['modules'])['attached'] ?? []),
            'tracks' => count($bank->tracks()->syncWithoutDetaching($matches['tracks'])['attached'] ?? []),
            'lessons' => count($bank->lessons()->syncWithoutDetaching($matches['lessons'])['attached'] ?? []),
        ];
    }

    public function matchesFor(QuestionBank $bank): array
    {
        $bankTitle = trim((string) $bank->title);

        if ($bankTitle === '') {
            return [
                'modules' => [],
                'tracks' => [],
                'lessons' => [],
            ];
        }

        return [
            'modules' => CourseModule::query()
                ->select(['id', 'name'])
                ->orderBy('id')
                ->get()
                ->filter(fn (CourseModule $module): bool => $this->titlesMatch($bankTitle, $module->name, 88))
                ->pluck('id')
                ->all(),
            'tracks' => CourseModuleTrack::query()
                ->select(['id', 'name'])
                ->orderBy('id')
                ->get()
                ->filter(fn (CourseModuleTrack $track): bool => $this->titlesMatch($bankTitle, $track->name, 86))
                ->pluck('id')
                ->all(),
            'lessons' => Lesson::query()
                ->select(['id', 'title'])
                ->orderBy('id')
                ->get()
                ->filter(fn (Lesson $lesson): bool => $this->titlesMatch($bankTitle, $lesson->title, 84))
                ->pluck('id')
                ->all(),
        ];
    }

    protected function titlesMatch(string $bankTitle, string $candidateTitle, int $minimumScore): bool
    {
        $bankKey = LessonTitleNormalizer::matchKey($bankTitle);
        $candidateKey = LessonTitleNormalizer::matchKey($candidateTitle);

        if ($bankKey === '' || $candidateKey === '') {
            return false;
        }

        if ($bankKey === $candidateKey) {
            return true;
        }

        if ($this->hasEnoughTokens($candidateKey) && str_contains($bankKey, $candidateKey)) {
            return true;
        }

        if ($this->hasEnoughTokens($bankKey) && str_contains($candidateKey, $bankKey)) {
            return true;
        }

        return LessonTitleNormalizer::matchScore($bankTitle, $candidateTitle) >= $minimumScore;
    }

    protected function hasEnoughTokens(string $key): bool
    {
        return collect(explode(' ', $key))
            ->filter(fn (string $token): bool => $token !== '')
            ->count() >= 2;
    }
}
