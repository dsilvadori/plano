<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $courses = DB::table('courses')
            ->select(['id', 'name', 'slug', 'tutory_product_id', 'combo_name', 'is_active', 'status'])
            ->get();

        $activeCourses = $courses
            ->where('is_active', true)
            ->where('status', 'published')
            ->values();

        if ($activeCourses->isEmpty()) {
            return;
        }

        $coursesByProduct = $activeCourses
            ->filter(fn ($course) => filled($course->tutory_product_id))
            ->groupBy('tutory_product_id');
        $coursesBySlug = $activeCourses->groupBy('slug');
        $coursesByName = $activeCourses->groupBy(fn ($course) => $this->normalizedName((string) $course->name));
        $coursesByCombo = collect();

        foreach ($activeCourses->filter(fn ($course) => filled($course->combo_name)) as $course) {
            foreach (explode(',', (string) $course->combo_name) as $comboName) {
                $normalizedComboName = $this->normalizedName($comboName);

                if ($normalizedComboName === '') {
                    continue;
                }

                $coursesByCombo->put(
                    $normalizedComboName,
                    $coursesByCombo->get($normalizedComboName, collect())->push($course),
                );
            }
        }

        DB::table('student_courses')
            ->orderBy('id')
            ->chunkById(500, function ($studentCourses) use ($courses, $coursesByProduct, $coursesBySlug, $coursesByName, $coursesByCombo): void {
                $rows = [];
                $now = now();

                foreach ($studentCourses as $studentCourse) {
                    $sourceCourse = $courses->firstWhere('id', $studentCourse->course_id);

                    if (! $sourceCourse) {
                        continue;
                    }

                    $targetCourses = collect();

                    if (filled($sourceCourse->tutory_product_id)) {
                        $targetCourses = $targetCourses->merge($coursesByProduct->get($sourceCourse->tutory_product_id, collect()));
                    }

                    $targetCourses = $targetCourses
                        ->merge($coursesBySlug->get($sourceCourse->slug, collect()))
                        ->merge($coursesByName->get($this->normalizedName((string) $sourceCourse->name), collect()));

                    if (filled($sourceCourse->combo_name)) {
                        foreach (explode(',', (string) $sourceCourse->combo_name) as $comboName) {
                            $targetCourses = $targetCourses->merge($coursesByCombo->get($this->normalizedName($comboName), collect()));
                        }
                    }

                    foreach ($targetCourses->unique('id') as $targetCourse) {
                        if ((int) $targetCourse->id === (int) $studentCourse->course_id) {
                            continue;
                        }

                        $rows[] = [
                            'user_id' => $studentCourse->user_id,
                            'course_id' => $targetCourse->id,
                            'source' => $studentCourse->source ?: 'manual',
                            'external_purchase_id' => $studentCourse->external_purchase_id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('student_courses')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        //
    }

    private function normalizedName(string $name): string
    {
        return Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
};
