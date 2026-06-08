<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\SetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

    protected function subscriptionPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'evento' => 'pagamento_aprovado',
            'id' => 'pedido_assinatura_123',
            'sessao' => 'sessao_assinatura_123',
            'nome' => 'Maria Assinante',
            'email' => 'maria@example.com',
            'telefone' => '5511999999999',
            'produto' => [
                'id' => 'assinatura-anual',
                'nome' => 'Assinatura Anual',
            ],
            'status' => 'paid',
            'metadados' => [
                'assinatura_id' => 'sub_123',
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

    public function test_generic_santos_combo_product_links_student_to_all_active_santos_courses(): void
    {
        Notification::fake();

        $genericCombo = Course::factory()->create([
            'name' => 'Gabaritando Prefeitura de Santos',
            'slug' => 'gabaritando-prefeitura-de-santos',
            'tutory_product_id' => 'gabaritando-prefeitura-de-santos',
            'is_active' => true,
        ]);

        $firstCourse = Course::factory()->create([
            'name' => 'Gabaritando Santos - Oficial de Administração',
            'slug' => 'gabaritando-santos-oficial-de-administracao',
            'combo_name' => 'Combo Extra, Gabaritando Prefeitura de Santos',
            'is_active' => true,
        ]);

        $secondCourse = Course::factory()->create([
            'name' => 'Gabaritando Santos - Inspetor de Alunos',
            'slug' => 'gabaritando-santos-inspetor-de-alunos',
            'combo_name' => 'Gabaritando Prefeitura de Santos',
            'is_active' => true,
        ]);

        $inactiveCourse = Course::factory()->create([
            'name' => 'Gabaritando Santos - Curso Inativo',
            'slug' => 'gabaritando-santos-curso-inativo',
            'combo_name' => 'Gabaritando Prefeitura de Santos',
            'is_active' => false,
        ]);

        $this->postJson('/webhooks/tutory', $this->payload([
            'event_id' => 'evt_combo_santos',
            'purchase' => [
                'id' => 'purchase_combo_santos',
                'product_id' => 'gabaritando-prefeitura-de-santos',
                'product_name' => 'Gabaritando Prefeitura de Santos',
            ],
        ]), [
            'Authorization' => 'Bearer secret-local',
        ])->assertOk();

        $user = User::where('email', 'joao@email.com')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$firstCourse->id, $secondCourse->id],
            $user->courses()->pluck('courses.id')->all(),
        );
        $this->assertFalse($user->courses()->whereKey($genericCombo)->exists());
        $this->assertFalse($user->courses()->whereKey($inactiveCourse)->exists());
    }

    public function test_command_expands_existing_santos_combo_links(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $genericCombo = Course::factory()->create([
            'name' => 'Gabaritando Prefeitura de Santos',
            'slug' => 'gabaritando-prefeitura-de-santos',
            'is_active' => true,
        ]);

        $firstCourse = Course::factory()->create([
            'name' => 'Gabaritando Santos - Oficial de Administração',
            'slug' => 'gabaritando-santos-oficial-de-administracao',
            'combo_name' => 'Gabaritando Prefeitura de Santos',
            'is_active' => true,
        ]);

        $secondCourse = Course::factory()->create([
            'name' => 'Gabaritando Santos - Inspetor de Alunos',
            'slug' => 'gabaritando-santos-inspetor-de-alunos',
            'combo_name' => 'Gabaritando Prefeitura de Santos',
            'is_active' => true,
        ]);

        Course::factory()->create([
            'name' => 'Gabaritando Santos - Curso Inativo',
            'slug' => 'gabaritando-santos-curso-inativo',
            'combo_name' => 'Gabaritando Prefeitura de Santos',
            'is_active' => false,
        ]);

        $student->courses()->syncWithoutDetaching([
            $genericCombo->id => [
                'source' => 'tutory',
                'external_purchase_id' => 'purchase_combo_santos',
            ],
        ]);

        $this->assertSame(0, Artisan::call('courses:expand-santos-combo'));

        $this->assertEqualsCanonicalizing(
            [$genericCombo->id, $firstCourse->id, $secondCourse->id],
            $student->fresh()->courses()->pluck('courses.id')->all(),
        );
    }

    public function test_approved_webhook_creates_inactive_course_when_product_does_not_exist(): void
    {
        Notification::fake();

        $this->postJson('/webhooks/tutory', $this->payload([
            'event_id' => 'evt_unknown_product',
            'purchase' => [
                'id' => 'purchase_unknown_product',
                'product_id' => 'curso-novo-tutory',
                'product_name' => 'Curso Novo Tutory',
            ],
        ]), [
            'Authorization' => 'Bearer secret-local',
        ])->assertOk();

        $user = User::where('email', 'joao@email.com')->firstOrFail();
        $course = Course::where('tutory_product_id', 'curso-novo-tutory')->firstOrFail();

        $this->assertSame('Curso Novo Tutory', $course->name);
        $this->assertSame('curso-novo-tutory', $course->slug);
        $this->assertFalse($course->is_active);
        $this->assertTrue($user->courses()->whereKey($course)->exists());
        $this->assertFalse($user->availableCoursesQuery()->whereKey($course)->exists());
    }

    public function test_new_payload_with_regular_product_creates_student_even_with_subscription_metadata(): void
    {
        Notification::fake();

        $this->postJson('/webhooks/tutory', $this->subscriptionPayload([
            'id' => 'pedido_curso_regular',
            'produto' => [
                'id' => 'curso-regular',
                'nome' => 'Curso Regular',
            ],
            'oferta' => [
                'nome' => 'Oferta do Curso Regular',
            ],
        ]), [
            'Authorization' => 'Bearer secret-local',
        ])->assertOk();

        $user = User::where('email', 'maria@example.com')->firstOrFail();
        $course = Course::where('tutory_product_id', 'curso-regular')->firstOrFail();

        $this->assertSame('student', $user->role);
        $this->assertFalse($course->is_active);
        $this->assertTrue($user->courses()->whereKey($course)->exists());
    }

    public function test_subscription_webhook_creates_subscriber_with_access_to_all_active_courses(): void
    {
        Notification::fake();

        $firstCourse = Course::factory()->create([
            'name' => 'Curso Ativo 1',
            'is_active' => true,
        ]);

        $secondCourse = Course::factory()->create([
            'name' => 'Curso Ativo 2',
            'is_active' => true,
        ]);

        Course::factory()->create([
            'name' => 'Curso Inativo',
            'is_active' => false,
        ]);

        $this->postJson('/webhooks/tutory', $this->subscriptionPayload(), [
            'Authorization' => 'Bearer secret-local',
        ])->assertOk();

        $user = User::where('email', 'maria@example.com')->firstOrFail();

        $this->assertSame('subscriber', $user->role);
        $this->assertSame('sub_123', $user->tutory_customer_id);
        $this->assertTrue($user->canAccessStudentArea());
        $this->assertEqualsCanonicalizing(
            [$firstCourse->id, $secondCourse->id],
            $user->availableCoursesQuery()->pluck('id')->all(),
        );
        Notification::assertSentTo($user, SetPasswordNotification::class);
    }

    public function test_subscription_webhook_upgrades_existing_student_to_subscriber(): void
    {
        Notification::fake();

        $student = User::factory()->create([
            'email' => 'maria@example.com',
            'role' => 'student',
        ]);

        $this->postJson('/webhooks/tutory', $this->subscriptionPayload(), [
            'Authorization' => 'Bearer secret-local',
        ])->assertOk();

        $this->assertSame('subscriber', $student->fresh()->role);
    }
}
