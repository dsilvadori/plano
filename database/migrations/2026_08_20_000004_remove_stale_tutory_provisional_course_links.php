<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $courses = DB::table('courses')
            ->select(['id', 'name', 'slug', 'tutory_product_id', 'is_active', 'status'])
            ->get();

        $publishedCourses = $courses
            ->where('is_active', true)
            ->where('status', 'published')
            ->values();

        if ($publishedCourses->isEmpty()) {
            return;
        }

        DB::table('student_courses')
            ->join('courses', 'courses.id', '=', 'student_courses.course_id')
            ->where('student_courses.source', 'tutory')
            ->where(function ($query): void {
                $query->where('courses.is_active', false)
                    ->orWhere('courses.status', '!=', 'published');
            })
            ->select([
                'student_courses.id',
                'student_courses.user_id',
                'student_courses.external_purchase_id',
                'courses.name',
                'courses.slug',
                'courses.tutory_product_id',
            ])
            ->orderBy('student_courses.id')
            ->chunkById(500, function ($studentCourses) use ($publishedCourses): void {
                $idsToDelete = [];

                foreach ($studentCourses as $studentCourse) {
                    $equivalentCourseIds = $publishedCourses
                        ->filter(fn ($course): bool => $this->coursesAreEquivalent($studentCourse, $course))
                        ->pluck('id')
                        ->all();

                    if ($equivalentCourseIds === []) {
                        continue;
                    }

                    $hasEquivalentEnrollment = DB::table('student_courses')
                        ->where('user_id', $studentCourse->user_id)
                        ->whereIn('course_id', $equivalentCourseIds)
                        ->exists();

                    if (! $hasEquivalentEnrollment) {
                        continue;
                    }

                    $idsToDelete[] = $studentCourse->id;
                }

                if ($idsToDelete !== []) {
                    DB::table('student_courses')
                        ->whereIn('id', $idsToDelete)
                        ->delete();
                }
            }, 'student_courses.id', 'id');
    }

    public function down(): void
    {
        //
    }

    private function coursesAreEquivalent(object $left, object $right): bool
    {
        if (filled($left->tutory_product_id) && filled($right->tutory_product_id) && $left->tutory_product_id === $right->tutory_product_id) {
            return true;
        }

        if (filled($left->slug) && filled($right->slug) && $left->slug === $right->slug) {
            return true;
        }

        return $this->normalizedName((string) $left->name) === $this->normalizedName((string) $right->name);
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
