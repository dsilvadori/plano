<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Services\ManualStudentCourseLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualStudentCourseLinkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_multiple_courses_to_a_student_without_detaching_existing_ones(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $existingCourse = Course::factory()->create();
        $newCourseA = Course::factory()->create();
        $newCourseB = Course::factory()->create();

        $student->courses()->attach($existingCourse, ['source' => 'manual']);

        app(ManualStudentCourseLinker::class)->link($student, [
            $newCourseA->id,
            $newCourseB->id,
        ]);

        $student->refresh();

        $this->assertCount(3, $student->courses);
        $this->assertTrue($student->courses->contains($existingCourse));
        $this->assertTrue($student->courses->contains($newCourseA));
        $this->assertTrue($student->courses->contains($newCourseB));
    }

    public function test_it_does_not_link_courses_for_admin_users(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();

        app(ManualStudentCourseLinker::class)->link($admin, [$course->id]);

        $this->assertCount(0, $admin->fresh()->courses);
    }
}
