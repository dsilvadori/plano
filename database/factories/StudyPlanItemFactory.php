<?php

namespace Database\Factories;

use App\Models\CourseModule;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyPlanItem>
 */
class StudyPlanItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'study_plan_id' => StudyPlan::factory(),
            'course_module_id' => CourseModule::factory(),
            'scheduled_date' => now()->toDateString(),
            'week_number' => 1,
            'day_of_week' => 'monday',
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'type' => fake()->randomElement(['basic', 'specific', 'review', 'questions']),
            'estimated_minutes' => fake()->numberBetween(30, 120),
            'completed_at' => null,
            'sort_order' => 1,
        ];
    }
}
