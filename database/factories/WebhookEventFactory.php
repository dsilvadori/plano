<?php

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'tutory',
            'event_id' => fake()->uuid(),
            'event_type' => 'purchase.approved',
            'status' => 'received',
            'payload' => ['ok' => true],
            'processed_at' => null,
        ];
    }
}
