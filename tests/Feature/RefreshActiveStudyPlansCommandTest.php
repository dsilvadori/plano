<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Models\StudyTrack;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshActiveStudyPlansCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_refreshes_active_plans_with_current_official_track(): void
    {
        $course = Course::factory()->create([
            'name' => 'Secretário de Unidade Escolar',
        ]);
        $module = CourseModule::factory()->for($course)->create([
            'name' => 'Conhecimentos Básicos - Legislação',
            'type' => 'basic',
            'workload_minutes' => 70,
            'sort_order' => 1,
        ]);
        $track = StudyTrack::factory()->for($course)->create([
            'name' => 'Trilha Oficial - Secretário de Unidade Escolar',
        ]);
        $track->modules()->sync([
            $module->id => ['weight' => 1, 'sort_order' => 1],
        ]);
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);
        $plan = StudyPlan::factory()->for($student, 'user')->for($course)->create([
            'study_track_id' => null,
            'total_required_minutes' => 35,
            'available_days' => ['monday', 'tuesday', 'wednesday'],
            'available_minutes_by_day' => [
                'monday' => 120,
                'tuesday' => 120,
                'wednesday' => 120,
            ],
            'status' => 'active',
        ]);

        $this->artisan('study-plans:refresh-active', [
            '--dry-run' => true,
            '--course-id' => [$course->id],
        ])->assertExitCode(0);

        $this->assertSame(35, $plan->fresh()->total_required_minutes);

        $this->artisan('study-plans:refresh-active', [
            '--course-id' => [$course->id],
        ])->assertExitCode(0);

        $plan->refresh();

        $this->assertSame($track->id, $plan->study_track_id);
        $this->assertSame(70, $plan->total_required_minutes);
        $this->assertSame(70, $plan->items()->where('type', 'basic')->sum('estimated_minutes'));
    }

    public function test_command_preserves_the_next_two_weeks_when_refreshing_active_plans(): void
    {
        $course = Course::factory()->create();
        $module = CourseModule::factory()->for($course)->create([
            'type' => 'basic',
            'workload_minutes' => 180,
            'sort_order' => 1,
        ]);
        $track = StudyTrack::factory()->for($course)->create([
            'name' => 'Trilha Oficial - Curso',
        ]);
        $track->modules()->sync([
            $module->id => ['weight' => 1, 'sort_order' => 1],
        ]);
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);
        $plan = StudyPlan::factory()->for($student, 'user')->for($course)->create([
            'study_track_id' => $track->id,
            'available_days' => ['monday'],
            'available_minutes_by_day' => ['monday' => 60],
            'status' => 'active',
        ]);
        $protectedDate = now()->addWeek()->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $secondProtectedDate = now()->addWeeks(2)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $futureDate = now()->addWeeks(3)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $protectedItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'scheduled_date' => $protectedDate,
            'week_number' => 2,
            'day_of_week' => 'monday',
            'estimated_minutes' => 60,
            'sort_order' => 1,
        ]);
        $secondProtectedItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'scheduled_date' => $secondProtectedDate,
            'week_number' => 3,
            'day_of_week' => 'monday',
            'estimated_minutes' => 60,
            'sort_order' => 2,
        ]);
        $futureItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'scheduled_date' => $futureDate,
            'week_number' => 4,
            'day_of_week' => 'monday',
            'estimated_minutes' => 60,
            'sort_order' => 3,
        ]);

        CourseModule::factory()->for($course)->create([
            'type' => 'specific',
            'workload_minutes' => 60,
            'sort_order' => 2,
        ]);

        $this->artisan('study-plans:refresh-active', [
            '--course-id' => [$course->id],
        ])->assertExitCode(0);

        $this->assertTrue(StudyPlanItem::query()
            ->whereKey($protectedItem->id)
            ->whereDate('scheduled_date', $protectedDate)
            ->exists());
        $this->assertTrue(StudyPlanItem::query()
            ->whereKey($secondProtectedItem->id)
            ->whereDate('scheduled_date', $secondProtectedDate)
            ->exists());
        $this->assertDatabaseMissing('study_plan_items', [
            'id' => $futureItem->id,
        ]);
        $this->assertTrue($plan->fresh()->items()->whereDate('scheduled_date', '>=', $futureDate)->exists());
    }

    public function test_command_fills_future_with_questions_and_review_when_removed_module_has_no_replacement(): void
    {
        $course = Course::factory()->create();
        $module = CourseModule::factory()->for($course)->create([
            'type' => 'basic',
            'workload_minutes' => 60,
            'sort_order' => 1,
        ]);
        $track = StudyTrack::factory()->for($course)->create([
            'name' => 'Trilha Oficial - Curso',
        ]);
        $track->modules()->sync([
            $module->id => ['weight' => 1, 'sort_order' => 1],
        ]);
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);
        $plan = StudyPlan::factory()->for($student, 'user')->for($course)->create([
            'study_track_id' => $track->id,
            'exam_date' => now()->addWeeks(6),
            'available_days' => ['monday'],
            'available_minutes_by_day' => ['monday' => 60],
            'status' => 'active',
        ]);
        $futureDate = now()->addWeek()->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $oldFutureItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => null,
            'scheduled_date' => $futureDate,
            'week_number' => 2,
            'day_of_week' => 'monday',
            'type' => 'basic',
            'estimated_minutes' => 60,
            'sort_order' => 1,
        ]);

        $module->forceFill(['is_active' => false])->save();
        $track->modules()->detach($module->id);

        $this->artisan('study-plans:refresh-active', [
            '--course-id' => [$course->id],
        ])->assertExitCode(0);

        $this->assertTrue(StudyPlanItem::query()
            ->whereKey($oldFutureItem->id)
            ->whereNull('course_module_id')
            ->whereDate('scheduled_date', $futureDate)
            ->where('type', 'questions')
            ->where('estimated_minutes', 60)
            ->exists());
    }

    public function test_command_replaces_removed_module_slots_from_next_week_when_new_module_exists(): void
    {
        $course = Course::factory()->create();
        $oldModule = CourseModule::factory()->for($course)->create([
            'name' => 'Módulo Removido',
            'type' => 'basic',
            'workload_minutes' => 60,
            'sort_order' => 1,
        ]);
        $newModule = CourseModule::factory()->for($course)->create([
            'name' => 'Módulo Novo',
            'type' => 'specific',
            'workload_minutes' => 120,
            'sort_order' => 2,
        ]);
        $track = StudyTrack::factory()->for($course)->create([
            'name' => 'Trilha Oficial - Curso',
        ]);
        $track->modules()->sync([
            $newModule->id => ['weight' => 1, 'sort_order' => 1],
        ]);
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);
        $plan = StudyPlan::factory()->for($student, 'user')->for($course)->create([
            'study_track_id' => $track->id,
            'exam_date' => now()->addWeeks(6),
            'available_days' => ['monday'],
            'available_minutes_by_day' => ['monday' => 60],
            'status' => 'active',
        ]);
        $replaceDate = now()->addWeek()->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $completedAt = now()->subDay();
        $removedItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => null,
            'scheduled_date' => $replaceDate,
            'week_number' => 2,
            'day_of_week' => 'monday',
            'title' => 'Bloco 1 · Matéria Básica: Módulo Removido',
            'type' => 'basic',
            'estimated_minutes' => 60,
            'completed_at' => null,
            'sort_order' => 1,
        ]);
        $completedRemovedItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $oldModule->id,
            'scheduled_date' => $replaceDate,
            'week_number' => 2,
            'day_of_week' => 'monday',
            'title' => 'Bloco 2 · Matéria Básica: Módulo Removido',
            'type' => 'basic',
            'estimated_minutes' => 60,
            'completed_at' => $completedAt,
            'sort_order' => 2,
        ]);

        $oldModule->forceFill(['is_active' => false])->save();

        $this->artisan('study-plans:refresh-active', [
            '--course-id' => [$course->id],
        ])->assertExitCode(0);

        $this->assertTrue(StudyPlanItem::query()
            ->whereKey($removedItem->id)
            ->where('course_module_id', $newModule->id)
            ->whereDate('scheduled_date', $replaceDate)
            ->where('type', 'specific')
            ->where('estimated_minutes', 60)
            ->whereNull('completed_at')
            ->exists());
        $this->assertTrue(StudyPlanItem::query()
            ->whereKey($completedRemovedItem->id)
            ->where('course_module_id', $oldModule->id)
            ->whereNotNull('completed_at')
            ->exists());
    }
}
