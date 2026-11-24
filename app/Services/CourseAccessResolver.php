<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CourseAccessResolver
{
    /**
     * O ID do produto da Tutory não é usado: os IDs vêm de outro sistema e não
     * correspondem aos cursos daqui. A correspondência é feita apenas pelo nome.
     */
    public function coursesForProduct(?string $productName): Collection
    {
        $comboCourses = $this->coursesForCombo($productName);

        if ($comboCourses->isNotEmpty()) {
            return $comboCourses;
        }

        $course = $this->courseForProduct($productName);

        return $course ? collect([$course]) : collect();
    }

    public function coursesForCombo(?string $comboName): Collection
    {
        $normalizedComboName = $this->normalizedName((string) $comboName);

        if ($normalizedComboName === '') {
            return collect();
        }

        // Cursos arquivados saem do combo; rascunhos continuam, para o aluno já ter o curso quando ele for publicado.
        return Course::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'archived'))
            ->whereNotNull('combo_name')
            ->get()
            ->filter(fn (Course $course): bool => collect($this->normalizedComboNames((string) $course->combo_name))
                ->contains($normalizedComboName))
            ->values();
    }

    /**
     * Procura o curso cujo nome é exatamente o nome do produto (ignorando só
     * maiúsculas, acentos, pontuação e espaços extras).
     */
    public function courseForProduct(?string $productName): ?Course
    {
        $normalizedProductName = $this->normalizedName((string) $productName);

        if ($normalizedProductName === '') {
            return null;
        }

        return Course::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (Course $course): bool => $this->normalizedName((string) $course->name) === $normalizedProductName)
            ->sortByDesc(fn (Course $course): int => ((int) $course->is_active * 2) + ($course->status === 'published' ? 1 : 0))
            ->first();
    }

    public function publishedEquivalentFor(Course $course): ?Course
    {
        if ($course->is_active && $course->status === 'published') {
            return $course;
        }

        $normalizedName = $this->normalizedName($course->name);

        return Course::query()
            ->where('is_active', true)
            ->where('status', 'published')
            ->get()
            ->first(fn (Course $candidate): bool => $this->normalizedName($candidate->name) === $normalizedName);
    }

    public function normalizedName(string $name): string
    {
        return Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->trim()
            ->toString();
    }

    protected function normalizedComboNames(string $comboNames): array
    {
        return Str::of($comboNames)
            ->explode(',')
            ->map(fn (string $comboName): string => $this->normalizedName($comboName))
            ->filter()
            ->values()
            ->all();
    }
}
