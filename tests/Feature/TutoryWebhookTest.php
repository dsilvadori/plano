<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\SetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TutoryWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'event_id' => 'evt_123',
            'event_type' => 'purchase.approved',
            'purchase' => [
                'id' => 'purchase_123',
                'status' => 'approved',
                'product_id' => 'gabaritando-santos-inspetor-de-alunos',
                'product_name' => 'Gabaritando Santos - Inspetor de Alunos',
                'student' => [
                    'name' => 'João da Silva',
                    'email' => 'joao@email.com',
                    'phone' => '11999999999',
                ],
            ],
        ], $overrides);
    }

    public function test_webhook_with_invalid_secret_returns_401(): void
    {
        $this->postJson('/webhooks/tutory', $this->payload(), [
            'Authorization' => 'Bearer invalid',
        ])->assertUnauthorized();
    }

    public function test_valid_webhook_creates_student_user(): void
    {
        Notification::fake();

        $this->postJson('/webhooks/tutory', $this->payload(), [
            'Authorization' => 'Bearer secret-local',
        ])->assertOk();

        $user = User::where('email', 'joao@email.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('student', $user->role);
        Notification::assertSentTo($user, SetPasswordNotification::class);
    }

    public function test_duplicate_webhook_does_not_create_duplicate_user(): void
    {
        Notification::fake();

        $headers = ['Authorization' => 'Bearer secret-local'];

        $this->postJson('/webhooks/tutory', $this->payload(), $headers)->assertOk();
        $this->postJson('/webhooks/tutory', $this->payload(), $headers)->assertOk();

        $this->assertSame(1, User::where('email', 'joao@email.com')->count());
        $this->assertSame(1, WebhookEvent::where('event_id', 'evt_123')->count());
    }

    public function test_approved_webhook_links_student_to_course_when_product_exists(): void
    {
        Notification::fake();

        Course::factory()->create([
            'name' => 'Gabaritando Santos - Inspetor de Alunos',
            'slug' => 'gabaritando-santos-inspetor-de-alunos',
            'tutory_product_id' => 'gabaritando-santos-inspetor-de-alunos',
        ]);

        $this->postJson('/webhooks/tutory', $this->payload(), [
            'Authorization' => 'Bearer secret-local',
        ])->assertOk();

        $user = User::where('email', 'joao@email.com')->firstOrFail();

        $this->assertTrue($user->courses()->exists());
    }
}
