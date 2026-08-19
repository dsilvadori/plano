<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Models\StudyTrack;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function makeStudentWithCourse(): array
    {
        $course = Course::factory()->create();

        $track = StudyTrack::factory()->create([
            'course_id' => $course->id,
        ]);

        $modules = CourseModule::factory()->count(4)->create(['course_id' => $course->id]);
        $track->modules()->sync($modules->mapWithKeys(fn ($module, $index) => [
            $module->id => ['weight' => 1, 'sort_order' => $index + 1],
        ])->all());

        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        return compact('student', 'course', 'track');
    }

    public function test_student_can_access_dashboard(): void
    {
        ['student' => $student] = $this->makeStudentWithCourse();

        $this->actingAs($student)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_student_can_generate_study_plan(): void
    {
        ['student' => $student, 'course' => $course, 'track' => $track] = $this->makeStudentWithCourse();

        $response = $this->actingAs($student)->post('/dashboard/plano', [
            'course_id' => $course->id,
            'study_track_id' => $track->id,
            'exam_date' => now()->addWeeks(8)->toDateString(),
            'start_date' => now()->toDateString(),
            'available_days' => ['monday', 'wednesday', 'friday'],
            'available_minutes_by_day' => [
                'monday' => '2:00',
                'wednesday' => '1:30',
                'friday' => '2:00',
            ],
            'intensity' => 'balanced',
        ]);

        $plan = StudyPlan::first();

        $response->assertRedirect(route('study-plans.show', $plan));
        $this->assertNotNull($plan);
        $this->assertGreaterThan(0, $plan->items()->count());
    }

    public function test_study_plan_viewer_uses_planned_track_lessons_instead_of_module_wide_lessons(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Conhecimentos Específicos',
            'type' => 'specific',
            'lessons' => [
                ['name' => '01 - Licitação', 'minutes' => 34],
                ['name' => 'Aula 01 - Conceitos de Recursos Materiais', 'minutes' => 17],
            ],
            'workload_minutes' => 51,
        ]);

        $archiveTrack = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Arquivologia',
            'slug' => 'arquivologia',
            'sort_order' => 1,
            'status' => 'published',
        ]);

        $archiveLesson = Lesson::factory()->create([
            'title' => 'Conceitos de Arquivologia',
            'duration_seconds' => 1020,
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $archiveTrack->lessons()->attach($archiveLesson->id, ['sort_order' => 1]);

        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $plan->items()->create([
            'course_module_id' => $module->id,
            'scheduled_date' => now()->toDateString(),
            'week_number' => 1,
            'day_of_week' => strtolower(now()->englishDayOfWeek),
            'title' => 'Bloco 1 · Conhecimentos Específicos: Arquivologia',
            'description' => 'Bloco de até 17 minutos para avançar em conhecimentos específicos com foco em Arquivologia. Aulas do bloco: Conceitos de Arquivologia.',
            'type' => 'specific',
            'estimated_minutes' => 17,
            'sort_order' => 1,
        ]);

        $this->actingAs($student)
            ->get(route('study-plans.show', $plan))
            ->assertOk()
            ->assertSee('Conceitos de Arquivologia')
            ->assertDontSee('01 - Licitação')
            ->assertDontSee('Aula 01 - Conceitos de Recursos Materiais');
    }

    public function test_study_plan_creation_validation_messages_are_translated(): void
    {
        ['student' => $student, 'course' => $course] = $this->makeStudentWithCourse();

        $this->actingAs($student)->post('/dashboard/plano', [
            'course_id' => $course->id,
        ])->assertSessionHasErrors([
            'start_date' => 'O campo data de início é obrigatório.',
            'available_days' => 'O campo dias disponíveis é obrigatório.',
            'available_minutes_by_day' => 'O campo tempo disponível é obrigatório.',
            'intensity' => 'O campo intensidade é obrigatório.',
        ]);
    }

    public function test_study_plan_creation_requires_course_with_translated_message(): void
    {
        ['student' => $student] = $this->makeStudentWithCourse();

        $this->actingAs($student)
            ->post('/dashboard/plano')
            ->assertSessionHasErrors([
                'course_id' => 'O campo curso é obrigatório.',
            ]);
    }

    public function test_student_cannot_create_more_than_one_active_plan_for_the_same_course(): void
    {
        ['student' => $student, 'course' => $course] = $this->makeStudentWithCourse();
        $existingPlan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($student)->post('/dashboard/plano', [
            'course_id' => $course->id,
            'exam_date' => now()->addWeeks(8)->toDateString(),
            'start_date' => now()->toDateString(),
            'available_days' => ['monday', 'wednesday', 'friday'],
            'available_minutes_by_day' => [
                'monday' => '2:00',
                'wednesday' => '1:30',
                'friday' => '2:00',
            ],
            'intensity' => 'balanced',
        ]);

        $response
            ->assertRedirect(route('study-plans.show', $existingPlan))
            ->assertSessionHas('status', 'Este curso já tem um plano ativo. Você pode continuar ou editar o plano existente.');

        $this->assertSame(1, $student->studyPlans()->where('course_id', $course->id)->where('status', 'active')->count());
    }

    public function test_student_can_create_one_active_plan_for_each_enrolled_course(): void
    {
        ['student' => $student, 'course' => $firstCourse] = $this->makeStudentWithCourse();
        $secondCourse = Course::factory()->create();
        CourseModule::factory()->count(3)->create(['course_id' => $secondCourse->id]);
        $student->courses()->attach($secondCourse, ['source' => 'manual']);

        StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $firstCourse->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($student)->post('/dashboard/plano', [
            'course_id' => $secondCourse->id,
            'exam_date' => now()->addWeeks(8)->toDateString(),
            'start_date' => now()->toDateString(),
            'available_days' => ['monday', 'wednesday', 'friday'],
            'available_minutes_by_day' => [
                'monday' => '2:00',
                'wednesday' => '1:30',
                'friday' => '2:00',
            ],
            'intensity' => 'balanced',
        ]);

        $newPlan = $student->studyPlans()->where('course_id', $secondCourse->id)->first();

        $response->assertRedirect(route('study-plans.show', $newPlan));
        $this->assertSame(2, $student->studyPlans()->where('status', 'active')->count());
        $this->assertTrue($student->studyPlans()->where('course_id', $firstCourse->id)->where('status', 'active')->exists());
        $this->assertTrue($student->studyPlans()->where('course_id', $secondCourse->id)->where('status', 'active')->exists());
    }

    public function test_daily_availability_is_capped_at_eight_hours(): void
    {
        ['student' => $student, 'course' => $course] = $this->makeStudentWithCourse();

        $this->actingAs($student)->post('/dashboard/plano', [
            'course_id' => $course->id,
            'exam_date' => now()->addWeeks(8)->toDateString(),
            'start_date' => now()->toDateString(),
            'available_days' => ['monday', 'wednesday'],
            'available_minutes_by_day' => [
                'monday' => '9',
                'wednesday' => '08:30',
            ],
            'intensity' => 'balanced',
        ])->assertRedirect();

        $plan = StudyPlan::query()->latest('id')->first();

        $this->assertSame(480, $plan->available_minutes_by_day['monday']);
        $this->assertSame(480, $plan->available_minutes_by_day['wednesday']);
    }

    public function test_student_cannot_access_another_students_plan(): void
    {
        ['student' => $owner, 'course' => $course] = $this->makeStudentWithCourse();
        ['student' => $other] = $this->makeStudentWithCourse();

        $plan = StudyPlan::factory()->create([
            'user_id' => $owner->id,
            'course_id' => $course->id,
        ]);

        $this->actingAs($other)
            ->get(route('study-plans.show', $plan))
            ->assertForbidden();
    }

    public function test_student_can_toggle_task_completion(): void
    {
        ['student' => $student, 'course' => $course] = $this->makeStudentWithCourse();

        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);

        $item = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
        ]);

        $this->actingAs($student)
            ->post(route('study-plans.items.toggle', [$plan, $item]))
            ->assertRedirect();

        $this->assertNotNull($item->fresh()->completed_at);
    }

    public function test_study_plan_viewer_shows_each_lesson_with_its_own_duration(): void
    {
        ['student' => $student, 'course' => $course] = $this->makeStudentWithCourse();

        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Classes de Palavras',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Substantivo e Adjetivo', 'minutes' => 25],
                ['name' => 'Advérbio e Conjunção', 'minutes' => 35],
            ],
            'workload_minutes' => 60,
            'sort_order' => 1,
        ]);

        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'total_required_minutes' => 60,
            'status' => 'active',
        ]);

        StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'scheduled_date' => now()->toDateString(),
            'week_number' => 1,
            'day_of_week' => strtolower(now()->englishDayOfWeek),
            'title' => 'Bloco 1 · Matéria Básica: Português - Classes de Palavras',
            'description' => 'Descrição antiga com aulas em texto corrido.',
            'type' => 'basic',
            'estimated_minutes' => 60,
            'sort_order' => 1,
        ]);

        $this->actingAs($student)
            ->get(route('study-plans.show', $plan))
            ->assertOk()
            ->assertSee('Substantivo e Adjetivo')
            ->assertSee('25 min')
            ->assertSee('Advérbio e Conjunção')
            ->assertSee('35 min')
            ->assertDontSee('Descrição antiga com aulas em texto corrido.');
    }

    public function test_student_can_delete_own_plan(): void
    {
        ['student' => $student, 'course' => $course] = $this->makeStudentWithCourse();

        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);

        $this->actingAs($student)
            ->delete(route('study-plans.destroy', $plan))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('study_plans', [
            'id' => $plan->id,
        ]);
    }

    public function test_student_can_access_edit_screen_for_own_plan(): void
    {
        ['student' => $student, 'course' => $course] = $this->makeStudentWithCourse();

        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);

        $this->actingAs($student)
            ->get(route('study-plans.edit', $plan))
            ->assertOk()
            ->assertSee('Editar plano');
    }

    public function test_student_can_update_own_plan_without_changing_the_url_identifier(): void
    {
        ['student' => $student, 'course' => $course, 'track' => $track] = $this->makeStudentWithCourse();

        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'study_track_id' => null,
            'status' => 'active',
        ]);

        $response = $this->actingAs($student)->put(route('study-plans.update', $plan), [
            'course_id' => $course->id,
            'study_track_id' => $track->id,
            'exam_date' => now()->addWeeks(10)->toDateString(),
            'start_date' => now()->addDay()->toDateString(),
            'available_days' => ['monday', 'tuesday', 'thursday'],
            'available_minutes_by_day' => [
                'monday' => '2',
                'tuesday' => '1:30',
                'thursday' => '2:15',
            ],
            'intensity' => 'intense',
        ]);

        $response->assertRedirect(route('study-plans.show', $plan));
        $plan->refresh();

        $this->assertSame($course->id, $plan->course_id);
        $this->assertNull($plan->study_track_id);
        $this->assertSame('intense', $plan->intensity);
        $this->assertTrue($plan->exam_date_confirmed);
        $this->assertGreaterThan(0, $plan->items()->count());
    }

    public function test_student_cannot_update_plan_to_a_course_that_already_has_an_active_plan(): void
    {
        ['student' => $student, 'course' => $firstCourse] = $this->makeStudentWithCourse();
        $secondCourse = Course::factory()->create();
        CourseModule::factory()->count(3)->create(['course_id' => $secondCourse->id]);
        $student->courses()->attach($secondCourse, ['source' => 'manual']);

        $firstPlan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $firstCourse->id,
            'status' => 'active',
        ]);

        StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $secondCourse->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($student)->from(route('study-plans.edit', $firstPlan))->put(route('study-plans.update', $firstPlan), [
            'course_id' => $secondCourse->id,
            'exam_date' => now()->addWeeks(10)->toDateString(),
            'start_date' => now()->addDay()->toDateString(),
            'available_days' => ['monday', 'tuesday', 'thursday'],
            'available_minutes_by_day' => [
                'monday' => '2',
                'tuesday' => '1:30',
                'thursday' => '2:15',
            ],
            'intensity' => 'intense',
        ]);

        $response
            ->assertRedirect(route('study-plans.edit', $firstPlan))
            ->assertSessionHasErrors('course_id');

        $this->assertSame($firstCourse->id, $firstPlan->fresh()->course_id);
    }

    public function test_student_can_update_plan_without_losing_completed_progress(): void
    {
        ['student' => $student, 'course' => $course] = $this->makeStudentWithCourse();

        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'type' => 'basic',
            'workload_minutes' => 180,
            'sort_order' => 1,
        ]);
        $course->modules()->attach($module->id, ['sort_order' => 1]);

        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'available_days' => ['monday', 'wednesday'],
            'available_minutes_by_day' => [
                'monday' => 120,
                'wednesday' => 120,
            ],
            'intensity' => 'balanced',
        ]);

        $completedItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'scheduled_date' => now()->subDay()->toDateString(),
            'week_number' => 1,
            'day_of_week' => strtolower(now()->subDay()->englishDayOfWeek),
            'type' => 'basic',
            'estimated_minutes' => 60,
            'completed_at' => now(),
            'sort_order' => 1,
        ]);

        $pendingItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'week_number' => 1,
            'day_of_week' => strtolower(now()->addDay()->englishDayOfWeek),
            'type' => 'basic',
            'estimated_minutes' => 60,
            'completed_at' => null,
            'sort_order' => 2,
        ]);

        $response = $this->actingAs($student)->put(route('study-plans.update', $plan), [
            'course_id' => $course->id,
            'exam_date' => now()->addWeeks(10)->toDateString(),
            'start_date' => now()->toDateString(),
            'available_days' => ['monday', 'tuesday', 'thursday'],
            'available_minutes_by_day' => [
                'monday' => '2:00',
                'tuesday' => '1:30',
                'thursday' => '2:15',
            ],
            'intensity' => 'intense',
        ]);

        $response->assertRedirect(route('study-plans.show', $plan));

        $this->assertDatabaseHas('study_plan_items', [
            'id' => $completedItem->id,
            'study_plan_id' => $plan->id,
        ]);
        $this->assertDatabaseHas('study_plan_items', [
            'id' => $pendingItem->id,
            'study_plan_id' => $plan->id,
        ]);
        $this->assertTrue($plan->fresh()->items()->whereNotNull('completed_at')->exists());
        $this->assertTrue($plan->fresh()->items()->whereDate('scheduled_date', '>=', now()->addWeek()->startOfWeek(CarbonInterface::MONDAY)->toDateString())->exists());
    }

    public function test_student_can_rebalance_plan_automatically(): void
    {
        ['student' => $student, 'course' => $course, 'track' => $track] = $this->makeStudentWithCourse();

        $moduleA = CourseModule::factory()->create([
            'course_id' => $course->id,
            'type' => 'basic',
            'workload_minutes' => 120,
            'sort_order' => 1,
        ]);
        $moduleB = CourseModule::factory()->create([
            'course_id' => $course->id,
            'type' => 'specific',
            'workload_minutes' => 180,
            'sort_order' => 2,
        ]);

        $track->modules()->sync([
            $moduleA->id => ['weight' => 1, 'sort_order' => 1],
            $moduleB->id => ['weight' => 1, 'sort_order' => 2],
        ]);

        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'study_track_id' => $track->id,
            'exam_date' => now()->addWeeks(8)->toDateString(),
            'start_date' => now()->subWeek()->toDateString(),
            'available_days' => ['monday', 'wednesday', 'friday'],
            'available_minutes_by_day' => [
                'monday' => 120,
                'wednesday' => 90,
                'friday' => 120,
            ],
            'intensity' => 'balanced',
            'status' => 'active',
        ]);

        $completedItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $moduleA->id,
            'scheduled_date' => now()->subDays(3)->toDateString(),
            'week_number' => 1,
            'day_of_week' => strtolower(now()->subDays(3)->englishDayOfWeek),
            'type' => 'basic',
            'estimated_minutes' => 60,
            'completed_at' => now()->subDays(2),
            'sort_order' => 1,
        ]);

        $pendingItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $moduleB->id,
            'scheduled_date' => now()->subDay()->toDateString(),
            'week_number' => 1,
            'day_of_week' => strtolower(now()->subDay()->englishDayOfWeek),
            'type' => 'specific',
            'estimated_minutes' => 60,
            'completed_at' => null,
            'sort_order' => 2,
        ]);

        $response = $this->actingAs($student)
            ->post(route('study-plans.rebalance', $plan));

        $response->assertRedirect(route('study-plans.show', $plan));

        $plan->refresh();

        $this->assertSame('active', $plan->status);
        $this->assertDatabaseHas('study_plan_items', [
            'id' => $completedItem->id,
            'study_plan_id' => $plan->id,
        ]);
        $this->assertDatabaseMissing('study_plan_items', [
            'id' => $pendingItem->id,
        ]);
        $this->assertTrue($plan->items()->whereNotNull('completed_at')->exists());
        $this->assertTrue($plan->items()->whereDate('scheduled_date', '>=', now()->toDateString())->exists());
        $this->assertGreaterThan(1, $plan->items()->count());
    }
}
