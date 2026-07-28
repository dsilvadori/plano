<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\StudyTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivateStaleOfficialModulesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_deactivates_active_modules_outside_official_track(): void
    {
        $course = Course::factory()->create();
        $officialModule = CourseModule::factory()->for($course)->create([
            'name' => 'Módulo oficial',
            'is_active' => true,
        ]);
        $staleModule = CourseModule::factory()->for($course)->create([
            'name' => 'Módulo antigo acumulado',
            'is_active' => true,
        ]);
        $track = StudyTrack::factory()->for($course)->create([
            'name' => 'Trilha Oficial - Curso',
            'is_active' => true,
        ]);
        $track->modules()->sync([
            $officialModule->id => ['weight' => 1, 'sort_order' => 1],
        ]);

        $this->artisan('courses:deactivate-stale-official-modules', [
            '--dry-run' => true,
            '--course-id' => [$course->id],
        ])->assertExitCode(0);

        $this->assertTrue($staleModule->fresh()->is_active);

        $this->artisan('courses:deactivate-stale-official-modules', [
            '--course-id' => [$course->id],
        ])->assertExitCode(0);

        $this->assertTrue($officialModule->fresh()->is_active);
        $this->assertFalse($staleModule->fresh()->is_active);
    }
}
