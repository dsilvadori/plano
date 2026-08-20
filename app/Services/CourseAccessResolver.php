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

        return Course::query()
            ->where('name', $productName)
            ->orderByDesc('is_active')
            ->orderByRaw("case when status = 'published' then 1 else 0 end desc")
            ->first();
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
