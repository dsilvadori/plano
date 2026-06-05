<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestStudentCourseAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_course_is_automatically_attached_to_test_student(): void
    {
        $student = User::factory()->create([
            'email' => 'aluno@teste.com',
            'role' => 'student',
        ]);

        $course = Course::factory()->create();

        $this->assertTrue(
            $student->fresh()->courses->contains($course)
        );
    }
}
