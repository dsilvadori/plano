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
            ->filter(fn (Course $course): bool => $this->normalizedName($course->name) === $normalizedProductName)
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
            ->replaceMatches('/\s+/', ' ')
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
