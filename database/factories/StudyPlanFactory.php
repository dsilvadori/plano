<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\StudyPlan;
use App\Models\StudyTrack;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyPlan>
 */
class StudyPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = now()->toDateString();
        $examDate = now()->addMonths(3)->toDateString();

        return [
            'user_id' => User::factory(),
            'course_id' => Course::factory(),
            'study_track_id' => null,
            'name' => 'Plano de Estudos',
            'exam_date' => $examDate,
            'start_date' => $startDate,
            'available_days' => ['monday', 'wednesday', 'friday'],
            'available_minutes_by_day' => ['monday' => 120, 'wednesday' => 120, 'friday' => 120],
            'total_available_minutes' => 1440,
            'total_required_minutes' => 1200,
            'intensity' => 'balanced',
            'status' => 'active',
            'viability_status' => 'good',
            'viability_message' => 'Plano viável.',
            'generated_at' => now(),
        ];
    }
}
