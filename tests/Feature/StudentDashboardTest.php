<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Models\StudyTrack;
use App\Models\User;
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
