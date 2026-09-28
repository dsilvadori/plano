<?php

namespace Tests\Feature;

use App\Http\Controllers\CourseCatalogController;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CourseAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourseTrackLessonScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_can_publish_subset_of_shared_track_lessons(): void
    {
        [$courseA, $courseB, $lessonA, $lessonB] = $this->sharedTrackFixture();
        $controller = app(CourseCatalogController::class);
        $method = new \ReflectionMethod($controller, 'publishedLessonsForCourse');
        $method->setAccessible(true);

        $trackedLessonIds = [$lessonA->id, $lessonB->id];
        $courseALessons = $method->invoke($controller, $courseA)->pluck('id')->intersect($trackedLessonIds)->values()->all();
        $courseBLessons = $method->invoke($controller, $courseB)->pluck('id')->intersect($trackedLessonIds)->values()->all();

        $this->assertSame([$lessonA->id], $courseALessons);
        $this->assertSame([$lessonB->id], $courseBLessons);
    }

    public function test_lesson_access_respects_course_track_lesson_scope(): void
    {
        [$courseA, $courseB, $lessonA, $lessonB] = $this->sharedTrackFixture();
        $controller = app(CourseCatalogController::class);
        $method = new \ReflectionMethod($controller, 'lessonBelongsToCourse');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($controller, $lessonA->fresh(['tracks']), $courseA));
        $this->assertFalse($method->invoke($controller, $lessonB->fresh(['tracks']), $courseA));
        $this->assertFalse($method->invoke($controller, $lessonA->fresh(['tracks']), $courseB));
        $this->assertTrue($method->invoke($controller, $lessonB->fresh(['tracks']), $courseB));
    }

    public function test_student_catalog_and_lesson_sidebar_respect_course_track_lesson_scope(): void
    {
        [$courseA, , $lessonA, $lessonB, $track] = $this->sharedTrackFixture();
        $student = User::factory()->create(['role' => 'student']);

        $student->courses()->attach($courseA, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.show', $courseA->slug))
            ->assertOk()
            ->assertSee(route('courses.lessons.show', [$courseA->slug, $lessonA]), false)
            ->assertDontSee(route('courses.lessons.show', [$courseA->slug, $lessonB]), false);

        $this->actingAs($student)
            ->get(route('courses.modules.tracks.lessons.index', [$courseA->slug, $track->module, $track]))
            ->assertOk()
            ->assertSee('Aula 1')
            ->assertDontSee('Aula 2')
            ->assertSee(route('courses.lessons.show', [$courseA->slug, $lessonA]), false)
            ->assertDontSee(route('courses.lessons.show', [$courseA->slug, $lessonB]), false);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$courseA->slug, $lessonA]))
            ->assertOk()
            ->assertSee('Aulas da trilha')
            ->assertSee('Aula 1')
            ->assertDontSee('Aula 2')
            ->assertSee(route('courses.lessons.show', [$courseA->slug, $lessonA]), false)
            ->assertDontSee(route('courses.lessons.show', [$courseA->slug, $lessonB]), false);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$courseA->slug, $lessonB]))
            ->assertNotFound();
    }

    public function test_course_access_resolver_matches_noisy_product_name(): void
    {
        $course = Course::factory()->create([
            'name' => 'Oficial de Administração',
            'tutory_product_id' => null,
        ]);

        $matched = app(CourseAccessResolver::class)
            ->courseForProduct(null, 'Pacote Preparatório Oficial Administração 2026 - Online');

        $this->assertTrue($course->is($matched));
    }

    protected function sharedTrackFixture(): array
    {
        $courseA = Course::factory()->create([
            'name' => 'Curso A',
            'status' => 'published',
            'is_active' => true,
        ]);
        $courseB = Course::factory()->create([
            'name' => 'Curso B',
            'status' => 'published',
            'is_active' => true,
        ]);
        $module = CourseModule::factory()->create([
            'course_id' => null,
            'name' => 'Módulo X',
        ]);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Trilha Z',
            'slug' => 'trilha-z',
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $lessonA = Lesson::factory()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => 'Aula 1',
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $lessonB = Lesson::factory()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => 'Aula 2',
            'sort_order' => 2,
            'status' => 'published',
        ]);

        $module->courses()->syncWithoutDetaching([
            $courseA->id => ['sort_order' => 1],
            $courseB->id => ['sort_order' => 1],
        ]);
        $track->courses()->syncWithoutDetaching([
            $courseA->id => ['sort_order' => 1],
            $courseB->id => ['sort_order' => 1],
        ]);
        $track->lessons()->syncWithoutDetaching([
            $lessonA->id => ['sort_order' => 1],
            $lessonB->id => ['sort_order' => 2],
        ]);

        DB::table('course_module_track_lesson_course')->insert([
            [
                'course_id' => $courseA->id,
                'course_module_track_id' => $track->id,
                'lesson_id' => $lessonA->id,
                'sort_order' => 1,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'course_id' => $courseB->id,
                'course_module_track_id' => $track->id,
                'lesson_id' => $lessonB->id,
                'sort_order' => 1,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return [$courseA, $courseB, $lessonA, $lessonB, $track];
    }
}
