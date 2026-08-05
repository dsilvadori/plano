<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Models\StudyTrack;
use App\Models\User;
use App\Livewire\StudyPlanBuilder;
use App\Livewire\StudyPlanViewer;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
            'exam_date' => now()->addWeeks(52)->toDateString(),
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
        $response->assertSessionHasNoErrors();

        $response->assertRedirect(route('study-plans.show', $plan));
        $this->assertNotNull($plan);
        $this->assertGreaterThan(0, $plan->items()->count());
    }

    public function test_builder_suggests_minimum_weekly_load_when_course_has_exam_date(): void
    {
        $course = Course::factory()->create([
            'exam_date' => now()->addDays(13)->toDateString(),
        ]);
        CourseModule::factory()->create([
            'course_id' => $course->id,
            'type' => 'basic',
            'workload_minutes' => 600,
            'sort_order' => 1,
        ]);
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        Livewire::actingAs($student)
            ->test(StudyPlanBuilder::class)
            ->set('course_id', $course->id)
            ->set('available_days', ['monday', 'wednesday', 'friday'])
            ->set('available_minutes_by_day.monday', '01:00')
            ->set('available_minutes_by_day.wednesday', '01:00')
            ->set('available_minutes_by_day.friday', '01:00')
            ->assertSee('Carga mínima sugerida')
            ->assertSee('6:30 por semana')
            ->assertSee('Atual: 3:00 / semana')
            ->assertSee('aumente pelo menos 3:30 por semana');
    }

    public function test_builder_fills_admin_exam_date_when_course_is_selected(): void
    {
        $adminExamDate = now()->addMonth()->toDateString();
        $course = Course::factory()->create([
            'name' => 'Gabaritando Santos - Administrador',
            'exam_date' => $adminExamDate,
        ]);
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        Livewire::actingAs($student)
            ->test(StudyPlanBuilder::class)
            ->call('selectCourse', $course->id)
            ->assertSet('exam_date_locked', true)
            ->assertSet('exam_date', $adminExamDate)
            ->assertSee('(definida no curso)')
            ->assertSee($adminExamDate);
    }

    public function test_student_cannot_generate_plan_below_minimum_weekly_load_when_exam_date_exists(): void
    {
        $course = Course::factory()->create([
            'exam_date' => now()->addDays(13)->toDateString(),
        ]);
        CourseModule::factory()->create([
            'course_id' => $course->id,
            'type' => 'basic',
            'workload_minutes' => 600,
            'sort_order' => 1,
        ]);
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)->post('/dashboard/plano', [
            'course_id' => $course->id,
            'exam_date' => now()->addDays(13)->toDateString(),
            'start_date' => now()->toDateString(),
            'available_days' => ['monday', 'wednesday', 'friday'],
            'available_minutes_by_day' => [
                'monday' => '1:00',
                'wednesday' => '1:00',
                'friday' => '1:00',
            ],
            'intensity' => 'balanced',
        ])->assertSessionHasErrors([
            'available_days' => 'Para gerar este plano até a prova, informe pelo menos 6:30 por semana. Hoje você informou 3:00.',
        ]);

        $this->assertSame(0, StudyPlan::query()->count());
    }

    public function test_builder_keeps_submitted_course_and_exam_date_after_minimum_weekly_load_error(): void
    {
        $course = Course::factory()->create();
        CourseModule::factory()->create([
            'course_id' => $course->id,
            'type' => 'basic',
            'workload_minutes' => 600,
            'sort_order' => 1,
        ]);
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);
        $examDate = now()->addDays(13)->toDateString();

        $this->withSession(['_old_input' => [
            'course_id' => (string) $course->id,
            'exam_date' => $examDate,
            'start_date' => now()->toDateString(),
            'available_days' => ['monday', 'wednesday', 'friday'],
            'available_minutes_by_day' => [
                'monday' => '1:00',
                'wednesday' => '1:00',
                'friday' => '1:00',
            ],
            'intensity' => 'balanced',
        ]]);

        $response = $this->actingAs($student)
            ->get('/dashboard/plano/novo')
            ->assertOk()
            ->assertSee('Carga mínima sugerida')
            ->assertSee('6:30 por semana')
            ->assertSee('Atual: 3:00 / semana')
            ->assertSee($examDate);

        $content = $response->getContent();

        foreach (['monday', 'wednesday', 'friday'] as $day) {
            $this->assertMatchesRegularExpression(
                '/name="available_days\[\]"\s+wire:model\.live="available_days"\s+value="'.$day.'"\s+type="checkbox"\s+checked/s',
                $content,
            );
        }
    }

    public function test_builder_shows_admin_exam_date_when_returning_after_validation_error(): void
    {
        $adminExamDate = now()->addMonth()->toDateString();
        $course = Course::factory()->create([
            'name' => 'Gabaritando Santos - Administrador',
            'exam_date' => $adminExamDate,
        ]);
        CourseModule::factory()->create([
            'course_id' => $course->id,
            'type' => 'basic',
            'workload_minutes' => 600,
            'sort_order' => 1,
        ]);
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        $this->withSession(['_old_input' => [
            'course_id' => (string) $course->id,
            'start_date' => now()->toDateString(),
            'available_days' => ['monday', 'wednesday', 'friday'],
            'available_minutes_by_day' => [
                'monday' => '2:00',
                'wednesday' => '2:00',
                'friday' => '2:00',
            ],
            'intensity' => 'balanced',
        ]]);

        $this->actingAs($student)
            ->get('/dashboard/plano/novo')
            ->assertOk()
            ->assertSee('Gabaritando Santos - Administrador')
            ->assertSee('(definida no curso)')
            ->assertSee($adminExamDate);
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
            'exam_date' => now()->addWeeks(52)->toDateString(),
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
            'exam_date' => now()->addWeeks(52)->toDateString(),
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

    public function test_student_can_manually_adjust_a_plan_day_without_losing_completed_items(): void
    {
        ['student' => $student, 'course' => $course, 'track' => $track] = $this->makeStudentWithCourse();

        $firstModule = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Pontuação',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Pontuação - Parte 01', 'minutes' => 30],
                ['name' => 'Pontuação - Parte 02', 'minutes' => 30],
                ['name' => 'Pontuação - Parte 03', 'minutes' => 30],
            ],
            'workload_minutes' => 90,
            'sort_order' => 1,
        ]);
        $secondModule = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Classes de Palavras',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Classes de palavras', 'minutes' => 45],
            ],
            'workload_minutes' => 45,
            'sort_order' => 2,
        ]);
        $track->modules()->sync([
            $firstModule->id => ['weight' => 1, 'sort_order' => 1],
            $secondModule->id => ['weight' => 1, 'sort_order' => 2],
        ]);
        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'study_track_id' => $track->id,
            'status' => 'active',
        ]);
        $date = now()->next('monday')->toDateString();
        $completedItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $firstModule->id,
            'scheduled_date' => $date,
            'week_number' => 1,
            'day_of_week' => 'monday',
            'type' => 'basic',
            'estimated_minutes' => 30,
            'completed_at' => now(),
            'sort_order' => 1,
        ]);
        $pendingItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $firstModule->id,
            'scheduled_date' => $date,
            'week_number' => 1,
            'day_of_week' => 'monday',
            'title' => 'Bloco 2 · Matéria Básica: Português - Pontuação',
            'type' => 'basic',
            'estimated_minutes' => 30,
            'completed_at' => null,
            'sort_order' => 2,
        ]);
        $unchangedPendingItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $firstModule->id,
            'scheduled_date' => $date,
            'week_number' => 1,
            'day_of_week' => 'monday',
            'title' => 'Bloco 3 · Matéria Básica: Português - Pontuação',
            'description' => 'Descrição original que deve ficar intacta.',
            'type' => 'basic',
            'estimated_minutes' => 30,
            'completed_at' => null,
            'sort_order' => 3,
        ]);
        $targetOriginalItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $secondModule->id,
            'scheduled_date' => now()->next('monday')->addWeek()->toDateString(),
            'week_number' => 2,
            'day_of_week' => 'monday',
            'title' => 'Bloco 1 · Matéria Básica: Português - Classes de Palavras',
            'description' => 'Destino original da aula escolhida.',
            'type' => 'basic',
            'estimated_minutes' => 45,
            'completed_at' => null,
            'sort_order' => 4,
        ]);

        Livewire::actingAs($student)
            ->test(StudyPlanViewer::class, ['studyPlan' => $plan])
            ->call('editDay', $date)
            ->assertSet('manualDayRows.0.block_number', 2)
            ->assertSet('manualDayRows.0.lesson_index', '1')
            ->set('manualDayRows.0.module_id', (string) $secondModule->id)
            ->assertSet('manualDayRows.0.lesson_index', '0')
            ->assertSet('manualDayRows.0.minutes', '0:45')
            ->call('saveManualDay')
            ->assertSet('manualDayConfirmation.swaps.0.missing_target', false)
            ->call('confirmManualDay')
            ->assertHasNoErrors();

        $this->assertNotNull($completedItem->fresh()->completed_at);
        $this->assertSame($firstModule->id, $completedItem->fresh()->course_module_id);
        $this->assertDatabaseHas('study_plan_items', [
            'id' => $pendingItem->id,
            'study_plan_id' => $plan->id,
            'course_module_id' => $secondModule->id,
            'title' => 'Bloco 2 · Matéria Básica: Português - Classes de Palavras',
            'estimated_minutes' => 45,
        ]);
        $this->assertDatabaseHas('study_plan_items', [
            'id' => $unchangedPendingItem->id,
            'study_plan_id' => $plan->id,
            'course_module_id' => $firstModule->id,
            'title' => 'Bloco 3 · Matéria Básica: Português - Pontuação',
            'description' => 'Descrição original que deve ficar intacta.',
            'estimated_minutes' => 30,
        ]);
        $this->assertSame('Ajuste manual do aluno. Aulas do bloco: Classes de palavras.', $pendingItem->fresh()->description);
        $this->assertDatabaseHas('study_plan_items', [
            'id' => $targetOriginalItem->id,
            'study_plan_id' => $plan->id,
            'course_module_id' => $firstModule->id,
            'title' => 'Bloco 1 · Matéria Básica: Português - Pontuação',
            'estimated_minutes' => 30,
        ]);
        $this->assertSame('Ajuste manual do aluno. Aulas do bloco: Pontuação - Parte 02.', $targetOriginalItem->fresh()->description);
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

    public function test_student_can_open_manual_day_editor_from_viewer(): void
    {
        ['student' => $student, 'course' => $course] = $this->makeStudentWithCourse();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Classes de Palavras',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Substantivo', 'minutes' => 45],
            ],
            'workload_minutes' => 45,
        ]);
        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'start_date' => now()->toDateString(),
        ]);
        $date = now()->toDateString();
        $item = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'scheduled_date' => $date,
            'week_number' => 1,
            'day_of_week' => strtolower(now()->englishDayOfWeek),
            'title' => 'Bloco 1 · Matéria Básica: Português - Classes de Palavras',
            'type' => 'basic',
            'estimated_minutes' => 45,
            'completed_at' => null,
            'sort_order' => 1,
        ]);

        Livewire::actingAs($student)
            ->test(StudyPlanViewer::class, ['studyPlan' => $plan])
            ->assertSee('Editar dia')
            ->call('editDay', $date)
            ->assertSet('editingDate', $date)
            ->assertSet('manualDayRows.0.item_id', (string) $item->id)
            ->assertSee('Ajuste manual do dia')
            ->assertSee('Português - Classes de Palavras');
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

        $pastPendingItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'scheduled_date' => now()->subDay()->toDateString(),
            'week_number' => 1,
            'day_of_week' => strtolower(now()->subDay()->englishDayOfWeek),
            'type' => 'basic',
            'estimated_minutes' => 60,
            'completed_at' => null,
            'sort_order' => 2,
        ]);

        $currentWeekPendingItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'week_number' => 1,
            'day_of_week' => strtolower(now()->addDay()->englishDayOfWeek),
            'type' => 'basic',
            'estimated_minutes' => 60,
            'completed_at' => null,
            'sort_order' => 3,
        ]);
        $nextWeekPendingItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'scheduled_date' => now()->addWeek()->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
            'week_number' => 2,
            'day_of_week' => 'monday',
            'type' => 'basic',
            'estimated_minutes' => 60,
            'completed_at' => null,
            'sort_order' => 4,
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
            'id' => $pastPendingItem->id,
            'study_plan_id' => $plan->id,
        ]);
        $this->assertDatabaseHas('study_plan_items', [
            'id' => $currentWeekPendingItem->id,
            'study_plan_id' => $plan->id,
        ]);
        $this->assertDatabaseMissing('study_plan_items', [
            'id' => $nextWeekPendingItem->id,
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

        $pastPendingItem = StudyPlanItem::factory()->create([
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
        $this->assertDatabaseHas('study_plan_items', [
            'id' => $pastPendingItem->id,
            'study_plan_id' => $plan->id,
        ]);
        $this->assertTrue($plan->items()->whereNotNull('completed_at')->exists());
        $this->assertTrue($plan->items()->whereDate('scheduled_date', '>=', now()->toDateString())->exists());
        $this->assertGreaterThan(1, $plan->items()->count());
    }
}
