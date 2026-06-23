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
        $saturdayItems = $plan->items()->where('day_of_week', 'saturday')->orderBy('sort_order')->get()->values();
        $this->assertTrue(in_array($saturdayItems->last()->type, ['questions', 'review'], true));
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

    public function test_generator_keeps_two_strong_theory_blocks_before_practice_when_day_has_more_time(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        $firstModule = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Interpretação de Texto',
            'type' => 'basic',
            'workload_minutes' => 90,
            'sort_order' => 1,
        ]);

        $secondModule = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Direito Administrativo - Atos',
            'type' => 'specific',
            'workload_minutes' => 120,
            'sort_order' => 2,
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

        $items = $plan->items()->where('day_of_week', 'monday')->orderBy('sort_order')->get()->values();

        $this->assertSame([$firstModule->id, $secondModule->id], $items->take(2)->pluck('course_module_id')->all());
        $this->assertSame(['basic', 'specific', 'questions', 'review'], $items->pluck('type')->all());
        $this->assertSame(67, $items[0]->estimated_minutes);
        $this->assertSame(68, $items[1]->estimated_minutes);
        $this->assertSame(23, $items[2]->estimated_minutes);
        $this->assertSame(22, $items[3]->estimated_minutes);
    }

    public function test_generator_does_not_split_a_lesson_when_next_one_does_not_fit_in_remaining_time(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Classe de Palavras',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Classe de Palavras - Aula 1', 'minutes' => 50],
                ['name' => 'Classe de Palavras - Aula 2', 'minutes' => 15],
            ],
            'workload_minutes' => 65,
            'sort_order' => 1,
        ]);

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->addDay()->toDateString(),
            now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->toDateString(),
            ['monday', 'tuesday'],
            ['monday' => 90, 'tuesday' => 60],
            'balanced',
        );

        $mondayItems = $plan->items()->where('day_of_week', 'monday')->orderBy('sort_order')->get()->values();
        $tuesdayItems = $plan->items()->where('day_of_week', 'tuesday')->orderBy('sort_order')->get()->values();

        $this->assertSame($module->id, $mondayItems[0]->course_module_id);
        $this->assertSame(50, $mondayItems[0]->estimated_minutes);
        $this->assertStringContainsString('Classe de Palavras - Aula 1', $mondayItems[0]->description);
        $this->assertSame($module->id, $tuesdayItems[0]->course_module_id);
        $this->assertSame(15, $tuesdayItems[0]->estimated_minutes);
        $this->assertStringContainsString('Classe de Palavras - Aula 2', $tuesdayItems[0]->description);
    }

    public function test_generator_keeps_daily_questions_and_review_at_ten_minutes_each_when_day_has_one_hour(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Classes de Palavras',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Substantivo', 'minutes' => 15],
                ['name' => 'Adjetivo', 'minutes' => 15],
                ['name' => 'Advérbio', 'minutes' => 10],
            ],
            'workload_minutes' => 40,
            'sort_order' => 1,
        ]);

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->next('monday')->toDateString(),
            now()->next('monday')->toDateString(),
            ['monday'],
            ['monday' => 60],
            'balanced',
        );

        $items = $plan->items()->where('day_of_week', 'monday')->orderBy('sort_order')->get()->values();

        $this->assertSame(['basic', 'questions', 'review'], $items->pluck('type')->all());
        $this->assertSame([40, 10, 10], $items->pluck('estimated_minutes')->all());
    }

    public function test_generator_prefers_basic_and_specific_or_complementary_theory_blocks_on_the_same_day_when_possible(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Classes de Palavras',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Substantivo', 'minutes' => 20],
                ['name' => 'Adjetivo', 'minutes' => 20],
            ],
            'workload_minutes' => 40,
            'sort_order' => 1,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Direito Administrativo - Atos',
            'type' => 'specific',
            'lessons' => [
                ['name' => 'Conceitos iniciais', 'minutes' => 20],
                ['name' => 'Requisitos', 'minutes' => 20],
            ],
            'workload_minutes' => 40,
            'sort_order' => 2,
        ]);

        $startDate = now()->next('monday');

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            $startDate->toDateString(),
            $startDate->toDateString(),
            ['monday'],
            ['monday' => 60],
            'balanced',
        );

        $items = $plan->items()->where('day_of_week', 'monday')->orderBy('sort_order')->get()->values();

        $this->assertSame(['basic', 'specific', 'questions', 'review'], $items->pluck('type')->all());
        $this->assertSame([20, 20, 10, 10], $items->pluck('estimated_minutes')->all());
    }

    public function test_generator_uses_remaining_time_for_larger_questions_and_review_after_two_theory_blocks(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Classe de Palavras',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Substantivo e Adjetivo', 'minutes' => 10],
                ['name' => 'Advérbio', 'minutes' => 11],
                ['name' => 'Conjunção coordenativa', 'minutes' => 16],
            ],
            'workload_minutes' => 37,
            'sort_order' => 1,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Legislação - Lei Orgânica',
            'type' => 'specific',
            'lessons' => [
                ['name' => 'Lei Orgânica', 'minutes' => 35],
            ],
            'workload_minutes' => 35,
            'sort_order' => 2,
        ]);

        $startDate = now()->next('monday');

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            $startDate->toDateString(),
            $startDate->toDateString(),
            ['monday'],
            ['monday' => 120],
            'balanced',
        );

        $items = $plan->items()->where('day_of_week', 'monday')->orderBy('sort_order')->get()->values();

        $this->assertSame(['basic', 'specific', 'questions', 'review'], $items->pluck('type')->all());
        $this->assertSame([37, 35, 24, 24], $items->pluck('estimated_minutes')->all());
    }

    public function test_generator_reduces_practice_reserve_to_keep_basic_and_specific_blocks_when_they_fit_the_day(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Interpretação',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Interpretação de textos', 'minutes' => 50],
            ],
            'workload_minutes' => 50,
            'sort_order' => 1,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Arquivologia',
            'type' => 'specific',
            'lessons' => [
                ['name' => '01 - Conceitos Iniciais de Arquivologia', 'minutes' => 45],
            ],
            'workload_minutes' => 45,
            'sort_order' => 2,
        ]);

        $startDate = now()->next('monday');

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            $startDate->toDateString(),
            $startDate->toDateString(),
            ['monday'],
            ['monday' => 120],
            'balanced',
        );

        $items = $plan->items()->where('day_of_week', 'monday')->orderBy('sort_order')->get()->values();

        $this->assertSame(['basic', 'specific', 'questions', 'review'], $items->pluck('type')->all());
        $this->assertSame([50, 45, 13, 12], $items->pluck('estimated_minutes')->all());
    }

    public function test_generator_interleaves_theory_types_without_breaking_each_module_sequence(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        $basicModule = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Interpretação',
            'type' => 'basic',
            'workload_minutes' => 120,
            'sort_order' => 1,
        ]);

        $specificModule = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Direito Administrativo - Atos',
            'type' => 'specific',
            'workload_minutes' => 120,
            'sort_order' => 2,
        ]);

        $complementaryModule = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática - Excel',
            'type' => 'complementary',
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
            ['monday' => 240],
            'balanced',
        );

        $items = $plan->items()->where('day_of_week', 'monday')->orderBy('sort_order')->get()->values();

        $this->assertSame([$basicModule->id, $specificModule->id], $items->take(2)->pluck('course_module_id')->all());
        $this->assertSame(['basic', 'specific', 'questions', 'review'], $items->pluck('type')->all());
        $this->assertSame([90, 90, 30, 30], $items->pluck('estimated_minutes')->all());
    }

    public function test_generator_alternates_subjects_inside_each_theory_type_between_days(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Interpretação',
            'type' => 'basic',
            'workload_minutes' => 180,
            'sort_order' => 1,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Matemática - Porcentagem',
            'type' => 'basic',
            'workload_minutes' => 180,
            'sort_order' => 2,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Direito Administrativo - Atos',
            'type' => 'specific',
            'workload_minutes' => 180,
            'sort_order' => 3,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Legislação - Lei Orgânica',
            'type' => 'specific',
            'workload_minutes' => 180,
            'sort_order' => 4,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática - Excel',
            'type' => 'complementary',
            'workload_minutes' => 180,
            'sort_order' => 5,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Inglês - Interpretação',
            'type' => 'complementary',
            'workload_minutes' => 180,
            'sort_order' => 6,
        ]);

        $startDate = now()->next('monday');

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            $startDate->copy()->addDay()->toDateString(),
            $startDate->toDateString(),
            ['monday', 'tuesday'],
            ['monday' => 210, 'tuesday' => 210],
            'balanced',
        );

        $theoryItemsByDay = $plan->items()
            ->with('courseModule')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($item) => in_array($item->type, ['basic', 'specific', 'complementary'], true))
            ->groupBy('day_of_week');

        $mondaySubjects = $theoryItemsByDay['monday']
            ->mapWithKeys(fn ($item) => [$item->type => str($item->courseModule->name)->before(' - ')->toString()])
            ->all();

        $tuesdaySubjects = $theoryItemsByDay['tuesday']
            ->mapWithKeys(fn ($item) => [$item->type => str($item->courseModule->name)->before(' - ')->toString()])
            ->all();

        $this->assertSame([
            'basic' => 'Português',
            'specific' => 'Direito Administrativo',
        ], $mondaySubjects);

        $this->assertSame([
            'basic' => 'Matemática',
            'specific' => 'Legislação',
        ], $tuesdaySubjects);
    }

    public function test_generator_uses_extra_time_for_questions_and_reviews_at_the_end_of_the_day(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Raciocínio Lógico - Proposições',
            'type' => 'basic',
            'workload_minutes' => 300,
            'sort_order' => 1,
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

        $items = $plan->items()->where('day_of_week', 'monday')->orderBy('sort_order')->get()->values();

        $this->assertSame(['basic', 'questions', 'review'], $items->pluck('type')->all());
        $this->assertSame([60, 15, 15], $items->pluck('estimated_minutes')->all());
    }

    public function test_generator_distributes_remaining_day_time_between_questions_and_review(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Interpretação',
            'type' => 'basic',
            'workload_minutes' => 40,
            'sort_order' => 1,
        ]);

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->addDays(5)->toDateString(),
            now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->toDateString(),
            ['monday', 'saturday'],
            ['monday' => 60, 'saturday' => 90],
            'balanced',
        );

        $saturdayItems = $plan->items()->where('day_of_week', 'saturday')->orderBy('sort_order')->get()->values();

        $this->assertSame(['questions', 'review'], $saturdayItems->pluck('type')->all());
        $this->assertSame([45, 45], $saturdayItems->pluck('estimated_minutes')->all());
    }

    public function test_generator_prioritizes_questions_and_review_before_theory_on_saturday_when_week_time_is_not_enough(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Classe de Palavras',
            'type' => 'basic',
            'workload_minutes' => 180,
            'sort_order' => 1,
        ]);

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->addWeek()->toDateString(),
            now()->next('saturday')->toDateString(),
            ['saturday'],
            ['saturday' => 90],
            'balanced',
        );

        $items = $plan->items()->where('day_of_week', 'saturday')->orderBy('sort_order')->get()->values();

        $this->assertSame(['basic', 'questions', 'review'], $items->pluck('type')->all());
        $this->assertSame([45, 23, 22], $items->pluck('estimated_minutes')->all());
    }

    public function test_generator_uses_saturday_only_for_review_and_questions_after_week_reaches_theory_target(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        foreach (range(1, 8) as $index) {
            CourseModule::factory()->create([
                'course_id' => $course->id,
                'name' => 'Português - Conteúdo ' . $index,
                'type' => 'basic',
                'workload_minutes' => 60,
                'sort_order' => $index,
            ]);
        }

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->addDays(5)->toDateString(),
            now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->toDateString(),
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            ['monday' => 120, 'tuesday' => 120, 'wednesday' => 120, 'thursday' => 120, 'friday' => 120, 'saturday' => 60],
            'balanced',
        );

        $saturdayItems = $plan->items()->where('day_of_week', 'saturday')->orderBy('sort_order')->get()->values();

        $this->assertTrue($saturdayItems->every(fn ($item) => in_array($item->type, ['questions', 'review'], true)));
        $this->assertSame(['questions', 'review'], $saturdayItems->pluck('type')->all());
        $this->assertSame([30, 30], $saturdayItems->pluck('estimated_minutes')->all());
    }

    public function test_generator_keeps_questions_and_reviews_at_end_of_day_while_theory_remains(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Classes de Palavras',
            'type' => 'basic',
            'workload_minutes' => 240,
            'sort_order' => 1,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática - Windows',
            'type' => 'complementary',
            'workload_minutes' => 240,
            'sort_order' => 2,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Caderno de Questões',
            'type' => 'questions',
            'workload_minutes' => 600,
            'sort_order' => 3,
        ]);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Revisão Geral',
            'type' => 'review',
            'workload_minutes' => 600,
            'sort_order' => 4,
        ]);

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->addWeek()->toDateString(),
            now()->next('monday')->toDateString(),
            ['monday', 'tuesday', 'wednesday'],
            ['monday' => 150, 'tuesday' => 150, 'wednesday' => 150],
            'balanced',
        );

        $theoryDays = $plan->items()
            ->orderBy('sort_order')
            ->get()
            ->groupBy('day_of_week')
            ->map(fn ($items) => $items->takeWhile(fn ($item) => ! in_array($item->type, ['questions', 'review'], true))->pluck('type')->all());

        $this->assertContains('basic', $theoryDays['monday']);
        $this->assertContains('complementary', $theoryDays['monday']);
        $this->assertContains('basic', $theoryDays['tuesday']);
        $this->assertContains('complementary', $theoryDays['tuesday']);
        $this->assertTrue($plan->items()->where('type', 'questions')->exists());
        $this->assertTrue($plan->items()->where('type', 'review')->exists());
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
        $this->assertStringContainsString('Revisão', $titles);
    }

    public function test_generator_informs_minimum_daily_study_time_when_schedule_is_tight(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Interpretação',
            'type' => 'basic',
            'workload_minutes' => 600,
            'sort_order' => 1,
        ]);

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->addDays(9)->toDateString(),
            now()->next('monday')->toDateString(),
            ['monday', 'wednesday', 'friday'],
            ['monday' => 60, 'wednesday' => 60, 'friday' => 60],
            'balanced',
        );

        $this->assertContains($plan->viability_status, ['warning', 'critical']);
        $this->assertStringContainsString('Para cumprir 100% da carga até a prova', $plan->viability_message);
        $this->assertStringContainsString('por dia', $plan->viability_message);
    }

    public function test_generator_informs_minimum_daily_study_time_when_exam_is_close_even_if_viable(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);

        CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática - Excel',
            'type' => 'complementary',
            'workload_minutes' => 180,
            'sort_order' => 1,
        ]);

        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->addDays(10)->toDateString(),
            now()->next('monday')->toDateString(),
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            ['monday' => 60, 'tuesday' => 60, 'wednesday' => 60, 'thursday' => 60, 'friday' => 60],
            'balanced',
        );

        $this->assertSame('good', $plan->viability_status);
        $this->assertStringContainsString('Para cumprir 100% da carga até a prova', $plan->viability_message);
    }

    public function test_plan_progress_percentage_does_not_exceed_one_hundred_percent(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $plan = $student->studyPlans()->create([
            'course_id' => $course->id,
            'name' => 'Plano teste',
            'exam_date' => now()->addWeek(),
            'exam_date_confirmed' => true,
            'start_date' => now(),
            'available_days' => ['monday'],
            'available_minutes_by_day' => ['monday' => 120],
            'total_available_minutes' => 120,
            'total_required_minutes' => 60,
            'intensity' => 'balanced',
            'status' => 'active',
            'viability_status' => 'good',
            'viability_message' => 'ok',
            'generated_at' => now(),
        ]);

        $plan->items()->createMany([
            [
                'scheduled_date' => now()->toDateString(),
                'week_number' => 1,
                'day_of_week' => 'monday',
                'title' => 'Teoria',
                'description' => 'Teoria',
                'type' => 'basic',
                'estimated_minutes' => 60,
                'completed_at' => now(),
                'sort_order' => 1,
            ],
            [
                'scheduled_date' => now()->toDateString(),
                'week_number' => 1,
                'day_of_week' => 'monday',
                'title' => 'Questões',
                'description' => 'Questões',
                'type' => 'questions',
                'estimated_minutes' => 30,
                'completed_at' => now(),
                'sort_order' => 2,
            ],
        ]);

        $this->assertSame(100, $plan->fresh()->progress_percentage);
    }

    public function test_plan_progress_percentage_uses_all_planned_items_not_only_course_workload(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $plan = $student->studyPlans()->create([
            'course_id' => $course->id,
            'name' => 'Plano teste',
            'exam_date' => now()->addWeek(),
            'exam_date_confirmed' => true,
            'start_date' => now(),
            'available_days' => ['monday'],
            'available_minutes_by_day' => ['monday' => 120],
            'total_available_minutes' => 120,
            'total_required_minutes' => 60,
            'intensity' => 'balanced',
            'status' => 'active',
            'viability_status' => 'good',
            'viability_message' => 'ok',
            'generated_at' => now(),
        ]);

        $plan->items()->createMany([
            [
                'scheduled_date' => now()->toDateString(),
                'week_number' => 1,
                'day_of_week' => 'monday',
                'title' => 'Teoria',
                'description' => 'Teoria',
                'type' => 'basic',
                'estimated_minutes' => 60,
                'completed_at' => now(),
                'sort_order' => 1,
            ],
            [
                'scheduled_date' => now()->toDateString(),
                'week_number' => 1,
                'day_of_week' => 'monday',
                'title' => 'Questões',
                'description' => 'Questões',
                'type' => 'questions',
                'estimated_minutes' => 30,
                'completed_at' => null,
                'sort_order' => 2,
            ],
        ]);

        $plan->refresh();

        $this->assertSame(67, $plan->progress_percentage);
        $this->assertSame(30, $plan->pending_minutes);
    }

    public function test_plan_progress_percentage_does_not_round_pending_plan_up_to_one_hundred_percent(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        $plan = $student->studyPlans()->create([
            'course_id' => $course->id,
            'name' => 'Plano teste',
            'exam_date' => now()->addWeek(),
            'exam_date_confirmed' => true,
            'start_date' => now(),
            'available_days' => ['monday'],
            'available_minutes_by_day' => ['monday' => 120],
            'total_available_minutes' => 120,
            'total_required_minutes' => 1000,
            'intensity' => 'balanced',
            'status' => 'active',
            'viability_status' => 'good',
            'viability_message' => 'ok',
            'generated_at' => now(),
        ]);

        $plan->items()->createMany([
            [
                'scheduled_date' => now()->toDateString(),
                'week_number' => 1,
                'day_of_week' => 'monday',
                'title' => 'Carga concluída',
                'description' => 'Carga concluída',
                'type' => 'basic',
                'estimated_minutes' => 996,
                'completed_at' => now(),
                'sort_order' => 1,
            ],
            [
                'scheduled_date' => now()->toDateString(),
                'week_number' => 1,
                'day_of_week' => 'monday',
                'title' => 'Pendência final',
                'description' => 'Pendência final',
                'type' => 'review',
                'estimated_minutes' => 4,
                'completed_at' => null,
                'sort_order' => 2,
            ],
        ]);

        $this->assertSame(99, $plan->fresh()->progress_percentage);
    }
}
