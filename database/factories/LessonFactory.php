<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    public function definition(): array
    {
        $course = Course::factory();
        $title = fake()->unique()->sentence(4);

        return [
            'course_id' => $course,
            'course_module_id' => CourseModule::factory(['course_id' => $course]),
            'course_module_track_id' => null,
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => fake()->paragraph(),
            'type' => 'video',
            'thumbnail_url' => fake()->imageUrl(),
            'duration_seconds' => fake()->numberBetween(300, 3600),
            'sort_order' => fake()->numberBetween(1, 30),
            'status' => 'published',
            'panda_video_id' => null,
            'panda_embed_url' => null,
            'panda_player_url' => null,
            'panda_status' => null,
            'google_doc_url' => null,
            'digital_book_path' => null,
            'source_status' => 'media_ready',
            'metadata' => null,
        ];
    }
}
