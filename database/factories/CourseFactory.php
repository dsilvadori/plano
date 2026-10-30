<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->sentence(3),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->paragraph(),
            'short_description' => fake()->sentence(),
            'thumbnail_url' => fake()->imageUrl(),
            'thumbnail_path' => null,
            'checkout_url' => fake()->url(),
            'tutory_product_id' => Str::slug(fake()->unique()->sentence(2)),
            'combo_name' => null,
            'status' => 'published',
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 0,
        ];
    }
}
