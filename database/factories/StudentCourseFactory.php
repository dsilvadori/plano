<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\StudentCourse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentCourse>
 */
class StudentCourseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'course_id' => Course::factory(),
            'source' => fake()->randomElement(['manual', 'tutory']),
            'external_purchase_id' => fake()->optional()->uuid(),
        ];
    }
}
