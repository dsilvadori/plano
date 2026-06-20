<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSphere;
use App\Models\EducationLevel;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseCatalogFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_catalog_relations_are_available(): void
    {
        $sphere = CourseSphere::factory()->create(['name' => 'Federal']);
        $level = EducationLevel::factory()->create(['name' => 'Ensino Médio']);

        $course = Course::factory()->create([
            'sphere_id' => $sphere->id,
            'education_level_id' => $level->id,
            'status' => 'published',
            'is_featured' => true,
        ]);

        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português',
            'panda_folder_id' => 'folder_123',
        ]);

        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Classes de palavras',
            'panda_video_id' => 'video_123',
            'duration_seconds' => 125,
        ]);

        $this->assertTrue($course->fresh()->sphere->is($sphere));
        $this->assertTrue($course->fresh()->educationLevel->is($level));
        $this->assertTrue($course->fresh()->lessons->contains($lesson));
        $this->assertTrue($module->fresh()->onlineLessons->contains($lesson));
        $this->assertSame(3, $lesson->duration_minutes);
    }
}
