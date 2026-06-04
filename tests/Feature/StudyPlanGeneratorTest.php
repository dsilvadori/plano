<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\StudyTrack;
use App\Models\User;
use App\Services\StudyPlanGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyPlanGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generator_creates_items_in_weekly_cycles(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);
        $track = StudyTrack::factory()->create(['course_id' => $course->id]);

        $modules = collect([
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'basic', 'workload_minutes' => 180, 'sort_order' => 1]),
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'specific', 'workload_minutes' => 240, 'sort_order' => 2]),
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'questions', 'workload_minutes' => 120, 'sort_order' => 3]),
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'review', 'workload_minutes' => 120, 'sort_order' => 4]),
        ]);

        $track->modules()->sync($modules->mapWithKeys(fn ($module, $index) => [
            $module->id => ['weight' => 1, 'sort_order' => $index + 1],
        ])->all());

        $generator = app(StudyPlanGenerator::class);

        $plan = $generator->generate(
            $student,
            $course,
            $track,
            now()->addWeeks(4)->toDateString(),
            now()->toDateString(),
            ['monday', 'tuesday', 'thursday', 'saturday'],
            ['monday' => 120, 'tuesday' => 90, 'thursday' => 120, 'saturday' => 120],
            'balanced',
        );

        $this->assertGreaterThan(0, $plan->items()->count());
        $this->assertTrue($plan->items()->where('type', 'basic')->exists());
        $this->assertTrue($plan->items()->where('type', 'specific')->exists());
        $this->assertTrue($plan->items()->where('type', 'questions')->exists());
        $this->assertTrue($plan->items()->where('type', 'review')->exists());
        $this->assertGreaterThanOrEqual(1, $plan->items()->distinct('week_number')->count('week_number'));
        $this->assertTrue($plan->items()->get()->every(fn ($item) => $item->estimated_minutes <= 60));
        $this->assertTrue($plan->items()->where('day_of_week', 'saturday')->exists());
        $this->assertTrue($plan->items()->where('day_of_week', 'saturday')->get()->every(fn ($item) => in_array($item->type, ['review', 'questions'], true)));
    }

    public function test_generator_uses_calendar_weeks_from_monday_to_sunday(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        collect([
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'basic', 'workload_minutes' => 420, 'sort_order' => 1]),
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'specific', 'workload_minutes' => 420, 'sort_order' => 2]),
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'review', 'workload_minutes' => 240, 'sort_order' => 3]),
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'questions', 'workload_minutes' => 240, 'sort_order' => 4]),
        ]);

        $generator = app(StudyPlanGenerator::class);
        $startDate = now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->addDays(2);
        $examDate = $startDate->copy()->addWeek()->endOfWeek(\Carbon\CarbonInterface::SUNDAY);

        $plan = $generator->generate(
            $student,
            $course,
            null,
            $examDate->toDateString(),
            $startDate->toDateString(),
            ['wednesday', 'thursday', 'friday', 'saturday', 'monday'],
            ['wednesday' => 60, 'thursday' => 60, 'friday' => 60, 'saturday' => 60, 'monday' => 60],
            'balanced',
        );

        $wednesdayItem = $plan->items()->where('day_of_week', 'wednesday')->first();
        $mondayItem = $plan->items()->where('day_of_week', 'monday')->first();

        $this->assertNotNull($wednesdayItem);
        $this->assertNotNull($mondayItem);
        $this->assertSame(1, $wednesdayItem->week_number);
        $this->assertSame(2, $mondayItem->week_number);
    }

    public function test_generator_interleaves_basic_and_specific_with_questions_and_reviews_after_each_cycle(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        collect([
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'basic', 'workload_minutes' => 420, 'sort_order' => 1]),
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'specific', 'workload_minutes' => 420, 'sort_order' => 2]),
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'review', 'workload_minutes' => 240, 'sort_order' => 3]),
            CourseModule::factory()->create(['course_id' => $course->id, 'type' => 'questions', 'workload_minutes' => 240, 'sort_order' => 4]),
        ]);

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->addWeek()->toDateString(),
            now()->next('monday')->toDateString(),
            ['monday'],
            ['monday' => 180],
            'balanced',
        );

        $types = $plan->items()->where('day_of_week', 'monday')->orderBy('sort_order')->pluck('type')->values()->all();

        $this->assertSame([
            'basic',
            'specific',
            'questions',
            'review',
            'basic',
            'specific',
            'questions',
            'review',
        ], $types);
    }

    public function test_generator_skips_intro_module_and_uses_generic_review_when_needed(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Apresentação e Boas-Vindas',
            'type' => 'review',
            'workload_minutes' => 60,
            'sort_order' => 1,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Classes de Palavras',
            'type' => 'basic',
            'workload_minutes' => 120,
            'sort_order' => 2,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Legislação - Lei Orgânica',
            'type' => 'specific',
            'workload_minutes' => 120,
            'sort_order' => 3,
        ]);

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->addWeek()->toDateString(),
            now()->next('monday')->toDateString(),
            ['monday'],
            ['monday' => 90],
            'balanced',
        );

        $titles = $plan->items()->orderBy('sort_order')->pluck('title')->implode(' | ');

        $this->assertStringNotContainsString('Apresentação e Boas-Vindas', $titles);
        $this->assertStringContainsString('Bloco 4 · Revisão', $titles);
    }
}
