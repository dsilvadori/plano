<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CourseAccessResolver
{
    public function coursesForProduct(?string $productId, ?string $productName): Collection
    {
        $comboCourses = $this->coursesForCombo($productName);

        if ($comboCourses->isNotEmpty()) {
            return $comboCourses;
        }

        $course = $this->courseForProduct($productId, $productName);

        return $course ? collect([$course]) : collect();
    }

    public function coursesForCombo(?string $comboName): Collection
    {
        $normalizedComboName = $this->normalizedName((string) $comboName);

        if ($normalizedComboName === '') {
            return collect();
        }

        return Course::query()
            ->where('is_active', true)
            ->whereNotNull('combo_name')
            ->get()
            ->filter(fn (Course $course): bool => in_array($normalizedComboName, $this->normalizedComboNames((string) $course->combo_name), true))
            ->values();
    }

    public function courseForProduct(?string $productId, ?string $productName): ?Course
    {
        if (filled($productId)) {
            $course = Course::query()
                ->where('tutory_product_id', $productId)
                ->orderByDesc('is_active')
                ->orderByRaw("case when status = 'published' then 1 else 0 end desc")
                ->get()
                ->first(fn (Course $course): bool => blank($productName) || $this->namesAreCompatible($course->name, $productName));

            if ($course) {
                return $course;
            }
        }

        if (filled($productId) && blank($productName)) {
            $course = Course::query()
                ->where('tutory_product_id', $productId)
                ->orderByDesc('is_active')
                ->orderByRaw("case when status = 'published' then 1 else 0 end desc")
                ->first();

            if ($course) {
                return $course;
            }
        }

        if (blank($productName)) {
            return null;
        }

        $normalizedProductName = $this->normalizedName($productName);

        return Course::query()
            ->get()
            ->filter(fn (Course $course): bool => $this->normalizedName($course->name) === $normalizedProductName
                || $this->namesAreCompatible($course->name, $productName))
            ->sortByDesc(fn (Course $course): int => ((int) $course->is_active * 2) + ($course->status === 'published' ? 1 : 0))
            ->first();
    }

    public function rememberProductId(Course $course, ?string $productId): void
    {
        if (blank($productId) || filled($course->tutory_product_id)) {
            return;
        }

        $course->forceFill(['tutory_product_id' => $productId])->save();
    }

    public function publishedEquivalentFor(Course $course): ?Course
    {
        if ($course->is_active && $course->status === 'published') {
            return $course;
        }

        $normalizedName = $this->normalizedName($course->name);

        if (filled($course->tutory_product_id)) {
            $equivalentByProduct = Course::query()
                ->where('is_active', true)
                ->where('status', 'published')
                ->where('tutory_product_id', $course->tutory_product_id)
                ->first();

            if ($equivalentByProduct) {
                return $equivalentByProduct;
            }
        }

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
            ->replaceMatches('/\b(curso|preparatorio|preparatório|pacote|combo|turma|online|completo|edital|pos[- ]?edital|p[oó]s[- ]?edital)\b/u', ' ')
            ->replaceMatches('/\b(para|do|da|dos|das|de|e|a|o|as|os)\b/u', ' ')
            ->replaceMatches('/\b(20\d{2})\b/u', ' ')
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

    protected function namesAreCompatible(string $courseName, string $productName): bool
    {
        $normalizedCourseName = $this->normalizedName($courseName);
        $normalizedProductName = $this->normalizedName($productName);

        if ($normalizedCourseName === '' || $normalizedProductName === '') {
            return false;
        }

        if ($normalizedCourseName === $normalizedProductName) {
            return true;
        }

        if (mb_strlen($normalizedCourseName) < 8 || mb_strlen($normalizedProductName) < 8) {
            return false;
        }

        if (Str::contains($normalizedCourseName, $normalizedProductName)
            || Str::contains($normalizedProductName, $normalizedCourseName)) {
            return true;
        }

        $courseTokens = $this->nameTokens($normalizedCourseName);
        $productTokens = $this->nameTokens($normalizedProductName);

        if ($courseTokens === [] || $productTokens === []) {
            return false;
        }

        $intersection = array_intersect($courseTokens, $productTokens);
        $minimumSharedTokens = min(count($courseTokens), count($productTokens)) >= 3 ? 2 : 1;

        return count($intersection) >= $minimumSharedTokens
            && count($intersection) / max(1, min(count($courseTokens), count($productTokens))) >= 0.6;
    }

    protected function nameTokens(string $normalizedName): array
    {
        return Str::of($normalizedName)
            ->explode(' ')
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->unique()
            ->values()
            ->all();
    }
}
