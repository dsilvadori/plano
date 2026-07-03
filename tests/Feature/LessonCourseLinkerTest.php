<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Services\LessonCourseLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LessonCourseLinkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_ready_standalone_lesson_to_course_structure_by_approximate_title(): void
    {
        $course = Course::factory()->create(['name' => 'Gabaritando Santos']);
        $module = CourseModule::factory()->for($course)->create(['name' => 'Informática']);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Word 2016',
            'slug' => 'word-2016',
            'sort_order' => 1,
            'status' => 'published',
        ]);

        $track->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 1]]);

        $placeholder = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '01 - Word 2016',
            'slug' => '01-word-2016-placeholder',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 1,
            'status' => 'draft',
            'source_status' => 'awaiting_media',
        ]);

        $readyLesson = Lesson::query()->create([
            'title' => '01-word-2016.mp4',
            'slug' => Str::slug('01-word-2016.mp4'),
            'type' => 'video',
            'duration_seconds' => 682,
            'sort_order' => 1,
            'status' => 'draft',
            'panda_video_id' => 'panda-video-123',
            'source_status' => 'media_ready',
            'metadata' => [
                'drive_source_folder_path' => 'Informática / OFFICE 2016 - NOVAS / 01 - Word 2016',
            ],
        ]);

        $stats = app(LessonCourseLinker::class)->sync($course);

        $this->assertSame(1, $stats['linked']);
        $this->assertSame(1, $stats['replaced']);
        $this->assertSame(1, $stats['published']);
        $this->assertDatabaseHas('course_module_lessons', [
            'course_module_id' => $module->id,
            'lesson_id' => $readyLesson->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $readyLesson->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $placeholder->id,
        ]);
        $this->assertDatabaseHas('lessons', [
            'id' => $readyLesson->id,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('lessons', [
            'id' => $placeholder->id,
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'status' => 'archived',
        ]);
    }
}
