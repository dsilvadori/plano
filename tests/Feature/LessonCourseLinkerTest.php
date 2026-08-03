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

    public function test_it_replaces_lei_organica_placeholders_with_ready_abbreviated_lessons_by_contextual_number(): void
    {
        $course = Course::factory()->create(['name' => 'Gabaritando Santos']);
        $module = CourseModule::factory()->for($course)->create(['name' => 'Conhecimentos Básicos de Legislação Municipal']);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Lei Orgânica - Santos',
            'slug' => 'lei-organica-santos',
            'sort_order' => 1,
            'status' => 'published',
        ]);

        $track->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 1]]);

        $firstPlaceholder = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '1 - Lei Orgânica - Santos',
            'slug' => '1-lei-organica-santos-placeholder',
            'type' => 'video',
            'duration_seconds' => 60,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $secondPlaceholder = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '2 - Lei Orgânica - Santos',
            'slug' => '2-lei-organica-santos-placeholder',
            'type' => 'video',
            'duration_seconds' => 60,
            'sort_order' => 2,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $firstReadyLesson = Lesson::query()->create([
            'title' => '1 - Lei Orgânica - Santos',
            'slug' => '1-lei-organica-santos-ready',
            'type' => 'video',
            'duration_seconds' => 1200,
            'sort_order' => 1,
            'status' => 'published',
            'panda_video_id' => 'panda-lo-santos-1',
            'source_status' => 'media_ready',
        ]);
        $secondReadyLesson = Lesson::query()->create([
            'title' => '03 - Lo Santos 2',
            'slug' => '03-lo-santos-2-ready',
            'type' => 'video',
            'duration_seconds' => 1140,
            'sort_order' => 3,
            'status' => 'published',
            'panda_video_id' => 'panda-lo-santos-2',
            'source_status' => 'media_ready',
            'metadata' => [
                'drive_source_folder_path' => 'Conhecimentos Básicos de Legislação Municipal / Lei Orgânica Santos',
            ],
        ]);

        $stats = app(LessonCourseLinker::class)->sync($course);

        $this->assertSame(2, $stats['linked']);
        $this->assertSame(2, $stats['replaced']);
        $this->assertDatabaseHas('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $firstReadyLesson->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $secondReadyLesson->id,
            'sort_order' => 2,
        ]);
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $firstPlaceholder->id,
        ]);
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $secondPlaceholder->id,
        ]);
    }
}
