<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\StudyPlan;
use App\Models\StudyTrack;
use App\Models\User;
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
}
