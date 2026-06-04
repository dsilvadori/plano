<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\StudyTrack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyTrack>
 */
class StudyTrackFactory extends Factory
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
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
