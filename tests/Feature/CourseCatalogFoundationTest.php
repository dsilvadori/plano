<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSphere;
use App\Models\EducationLevel;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Models\User;
use App\Services\StudyPlanGenerator;
use Carbon\CarbonInterface;
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

    public function test_student_can_browse_catalog_and_only_sees_enrolled_courses_in_my_courses(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $enrolledCourse = Course::factory()->create([
            'name' => 'Curso liberado',
            'status' => 'published',
            'is_featured' => true,
        ]);
        $lockedCourse = Course::factory()->create([
            'name' => 'Curso bloqueado',
            'status' => 'published',
            'checkout_url' => 'https://checkout.example.com/curso',
        ]);

        $student->courses()->attach($enrolledCourse, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.index'))
            ->assertOk()
            ->assertSee('Curso liberado')
            ->assertSee('Curso bloqueado')
            ->assertSee('Comprar acesso');

        $this->actingAs($student)
            ->get(route('courses.mine'))
            ->assertOk()
            ->assertSee('Curso liberado')
            ->assertDontSee('Curso bloqueado');

        $this->actingAs($student)
            ->get(route('courses.show', $lockedCourse->slug))
            ->assertOk()
            ->assertSee('Acesso bloqueado')
            ->assertSee('Comprar acesso');
    }

    public function test_enrolled_student_can_watch_and_complete_lesson(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create([
            'name' => 'Curso com player',
            'status' => 'published',
        ]);
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Módulo inicial',
            'sort_order' => 1,
        ]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Aula com Panda',
            'panda_embed_url' => 'https://player.example.com/embed/video-123',
            'duration_seconds' => 900,
            'status' => 'published',
        ]);

        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertOk()
            ->assertSee('Aula com Panda')
            ->assertSee('https://player.example.com/embed/video-123', false);

        $this->assertDatabaseHas('lesson_progress', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($student)
            ->post(route('courses.lessons.complete', [$course->slug, $lesson]))
            ->assertRedirect();

        $progress = LessonProgress::query()
            ->where('user_id', $student->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $this->assertSame('completed', $progress->status);
        $this->assertSame(900, $progress->progress_seconds);
        $this->assertNotNull($progress->completed_at);

        $this->actingAs($student)
            ->get(route('courses.show', $course->slug))
            ->assertOk()
            ->assertSee('1 de 1 aula(s) concluída(s).')
            ->assertSee('Concluída');
    }

    public function test_locked_student_cannot_open_lesson_player(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'status' => 'published',
        ]);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertForbidden();
    }

    public function test_generated_study_plan_links_real_online_lessons(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português',
            'type' => 'basic',
            'workload_minutes' => 30,
            'sort_order' => 1,
            'lessons' => [
                ['name' => 'Classes de palavras', 'minutes' => 30],
            ],
        ]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Classes de palavras',
            'duration_seconds' => 1800,
            'status' => 'published',
        ]);

        $student->courses()->attach($course, ['source' => 'manual']);

        $startDate = now()->next(CarbonInterface::MONDAY)->toDateString();
        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->next(CarbonInterface::MONDAY)->addWeek()->toDateString(),
            $startDate,
            ['monday'],
            ['monday' => 60],
            'balanced',
        );

        $item = $plan->items()->where('course_module_id', $module->id)->firstOrFail();

        $this->assertTrue($item->lessons()->whereKey($lesson->id)->exists());

        $this->actingAs($student)
            ->get(route('study-plans.show', $plan))
            ->assertOk()
            ->assertSee('Classes de palavras')
            ->assertSee(route('courses.lessons.show', [$course->slug, $lesson]), false);
    }

    public function test_completing_linked_lesson_marks_plan_item_when_all_lessons_are_done(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'status' => 'published',
            'duration_seconds' => 600,
        ]);
        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $item = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'completed_at' => null,
        ]);

        $student->courses()->attach($course, ['source' => 'manual']);
        $item->lessons()->attach($lesson, ['sort_order' => 1]);

        $this->actingAs($student)
            ->post(route('courses.lessons.complete', [$course->slug, $lesson]))
            ->assertRedirect();

        $this->assertNotNull($item->fresh()->completed_at);
    }
}
