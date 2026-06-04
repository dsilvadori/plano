<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseModule>
 */
class CourseModuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'name' => fake()->sentence(2),
            'type' => fake()->randomElement(['basic', 'specific', 'review', 'questions']),
            'workload_minutes' => fake()->numberBetween(120, 900),
            'sort_order' => fake()->numberBetween(1, 10),
            'is_active' => true,
        ];
    }
}
