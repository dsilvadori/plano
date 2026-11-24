<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\StudentCoursesRelationManager;
use App\Models\Course;
use App\Models\StudentCourse;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\CourseAccessGrantedNotification;
use App\Services\ManualStudentCourseLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class CourseAccessExpirationTest extends TestCase
{
    use RefreshDatabase;

    protected array $headers = ['Authorization' => 'Bearer secret-local'];

    protected function purchase(string $productId, string $productName, string $email = 'aluno@example.com', string $orderId = 'or_1'): array
    {
        return [
            'evento' => 'pagamento_aprovado',
            'id' => $orderId,
            'nome' => 'Aluno Exemplo',
            'email' => $email,
            'produto' => ['id' => $productId, 'nome' => $productName],
            'status' => 'paid',
        ];
    }

    protected function enrollment(User $user, Course $course): StudentCourse
    {
        return StudentCourse::where('user_id', $user->id)->where('course_id', $course->id)->firstOrFail();
    }

    public function test_webhook_purchase_grants_one_year_of_access(): void
    {
        Notification::fake();
        $this->freezeSecond();
        $course = Course::factory()->create(['name' => 'Gabaritando Praia Grande - Agente Administrativo', 'tutory_product_id' => 'pg']);

        $this->postJson('/webhooks/tutory', $this->purchase('pg', 'Gabaritando Praia Grande - Agente Administrativo'), $this->headers)->assertOk();

        $user = User::findByEmail('aluno@example.com');
        $this->assertTrue($this->enrollment($user, $course)->expires_at->equalTo(now()->addYear()));
        $this->assertNull(WebhookEvent::first()->error_message);
    }

    public function test_combo_purchase_links_every_combo_course_with_one_year_of_access(): void
    {
        Notification::fake();
        $this->freezeSecond();
        $agente = Course::factory()->create(['name' => 'Gabaritando SETEC Campinas - Agente', 'combo_name' => 'Gabaritando SETEC Campinas']);
        $monitor = Course::factory()->create(['name' => 'Gabaritando SETEC Campinas - Monitor', 'combo_name' => 'Combo Campinas, Gabaritando SETEC Campinas']);
        $outro = Course::factory()->create(['name' => 'Gabaritando Contagem - Assistente', 'combo_name' => 'Gabaritando Contagem']);

        $this->postJson('/webhooks/tutory', $this->purchase('setec', 'Gabaritando SETEC Campinas'), $this->headers)->assertOk();

        $user = User::findByEmail('aluno@example.com');
        $this->assertEqualsCanonicalizing([$agente->id, $monitor->id], $user->courses()->pluck('courses.id')->all());
        $this->assertNotContains($outro->id, $user->availableCoursesQuery()->pluck('courses.id'));

        foreach ([$agente, $monitor] as $course) {
            $this->assertTrue($this->enrollment($user, $course)->expires_at->equalTo(now()->addYear()));
        }
    }

    public function test_new_purchase_renews_access_for_one_year_without_shortening_it(): void
    {
        Notification::fake();
        $this->freezeSecond();
        $student = User::factory()->create(['email' => 'aluno@example.com', 'role' => 'student']);
        $soon = Course::factory()->create(['name' => 'Gabaritando Contagem - Assistente Administrativo', 'tutory_product_id' => 'cont']);
        $longer = Course::factory()->create(['name' => 'Gabaritando Itanhaem - Agente Administrativo', 'tutory_product_id' => 'itan']);
        $student->courses()->attach($soon->id, ['source' => 'tutory', 'expires_at' => now()->addMonths(2)]);
        $student->courses()->attach($longer->id, ['source' => 'manual', 'expires_at' => now()->addYears(3)]);

        $this->postJson('/webhooks/tutory', $this->purchase('cont', 'Gabaritando Contagem - Assistente Administrativo', orderId: 'or_a'), $this->headers)->assertOk();
        $this->postJson('/webhooks/tutory', $this->purchase('itan', 'Gabaritando Itanhaem - Agente Administrativo', orderId: 'or_b'), $this->headers)->assertOk();

        $this->assertTrue($this->enrollment($student, $soon)->expires_at->equalTo(now()->addYear()));
        $this->assertTrue($this->enrollment($student, $longer)->expires_at->equalTo(now()->addYears(3)));
    }

    public function test_repurchase_after_expiration_restores_access_and_notifies_student(): void
    {
        Notification::fake();
        $this->freezeSecond();
        $student = User::factory()->create(['email' => 'aluno@example.com', 'role' => 'student']);
        $course = Course::factory()->create(['name' => 'Gabaritando Contagem - Assistente Administrativo', 'tutory_product_id' => 'cont']);
        $student->courses()->attach($course->id, ['source' => 'tutory', 'expires_at' => now()->subDay()]);

        $this->assertFalse($student->availableCoursesQuery()->whereKey($course->id)->exists());

        $this->postJson('/webhooks/tutory', $this->purchase('cont', 'Gabaritando Contagem - Assistente Administrativo'), $this->headers)->assertOk();

        $this->assertTrue($this->enrollment($student, $course)->expires_at->equalTo(now()->addYear()));
        $this->assertTrue($student->availableCoursesQuery()->whereKey($course->id)->exists());
        Notification::assertSentTo($student, CourseAccessGrantedNotification::class);
    }

    public function test_expired_course_disappears_but_student_can_still_log_in_and_sees_notice(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $expired = Course::factory()->create(['name' => 'Curso Vencido Exemplo']);
        $active = Course::factory()->create(['name' => 'Curso Em Dia Exemplo']);
        $student->courses()->attach($expired->id, ['source' => 'tutory', 'expires_at' => now()->subDay()]);
        $student->courses()->attach($active->id, ['source' => 'tutory']);

        $available = $student->availableCoursesQuery()->pluck('courses.id');
        $this->assertNotContains($expired->id, $available);
        $this->assertContains($active->id, $available);

        $this->actingAs($student)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Acesso expirado')
            ->assertSee('Curso Vencido Exemplo');

        $this->actingAs($student)
            ->get(route('courses.show', $expired))
            ->assertOk()
            ->assertViewHas('hasAccess', false);
    }

    public function test_expired_course_does_not_unlock_combo_siblings(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $agente = Course::factory()->create(['name' => 'SETEC Agente', 'combo_name' => 'Gabaritando SETEC Campinas', 'tutory_product_id' => 'setec']);
        $monitor = Course::factory()->create(['name' => 'SETEC Monitor', 'combo_name' => 'Gabaritando SETEC Campinas', 'tutory_product_id' => 'setec']);
        $student->courses()->attach($agente->id, ['source' => 'tutory', 'expires_at' => now()->subDay()]);

        $available = $student->availableCoursesQuery()->pluck('courses.id');

        $this->assertNotContains($agente->id, $available);
        $this->assertNotContains($monitor->id, $available);
    }

    public function test_subscriber_gets_one_year_and_loses_course_access_after_it(): void
    {
        Notification::fake();
        $this->freezeSecond();
        $course = Course::factory()->create();

        $this->postJson('/webhooks/tutory', $this->purchase('assinatura-anual', 'Assinatura Anual', 'assinante@example.com'), $this->headers)->assertOk();

        $subscriber = User::findByEmail('assinante@example.com');
        $this->assertSame('subscriber', $subscriber->role);
        $this->assertTrue($subscriber->access_expires_at->equalTo(now()->addYear()));
        $this->assertTrue($subscriber->availableCoursesQuery()->whereKey($course->id)->exists());

        $this->travel(366)->days();
        $subscriber->refresh();

        $this->assertTrue($subscriber->subscriptionExpired());
        $this->assertFalse($subscriber->availableCoursesQuery()->exists());
        $this->actingAs($subscriber)->get(route('dashboard'))->assertOk()->assertSee('Sua assinatura expirou');

        $this->postJson('/webhooks/tutory', $this->purchase('assinatura-anual', 'Assinatura Anual', 'assinante@example.com', 'or_2'), $this->headers)->assertOk();

        $this->assertTrue($subscriber->refresh()->access_expires_at->equalTo(now()->addYear()));
        $this->assertTrue($subscriber->hasActiveSubscription());
    }

    public function test_student_upgraded_to_subscriber_gets_one_year(): void
    {
        Notification::fake();
        $this->freezeSecond();
        User::factory()->create(['email' => 'assinante@example.com', 'role' => 'student']);

        $this->postJson('/webhooks/tutory', $this->purchase('assinatura-anual', 'Assinatura Anual', 'assinante@example.com'), $this->headers)->assertOk();

        $this->assertTrue(User::findByEmail('assinante@example.com')->access_expires_at->equalTo(now()->addYear()));
    }

    public function test_manual_and_admin_links_get_one_year_and_test_student_stays_unlimited(): void
    {
        $this->freezeSecond();
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create();

        app(ManualStudentCourseLinker::class)->link($student, [$course->id]);
        $this->assertTrue($this->enrollment($student, $course)->expires_at->equalTo(now()->addYear()));

        $testStudent = User::factory()->create(['email' => 'aluno@teste.com', 'role' => 'student']);
        $newCourse = Course::factory()->create();
        $this->assertNull($this->enrollment($testStudent, $newCourse)->expires_at);
    }

    public function test_webhook_flags_purchase_without_visible_course(): void
    {
        Notification::fake();

        $this->postJson('/webhooks/tutory', $this->purchase('desconhecido', 'Gabaritando Cidade Que Nao Existe'), $this->headers)->assertOk();

        $event = WebhookEvent::first();
        $this->assertSame('processed', $event->status);
        $this->assertStringContainsString('ATENÇÃO', (string) $event->error_message);
        $this->assertStringContainsString('Gabaritando Cidade Que Nao Existe', (string) $event->error_message);
    }

    public function test_admin_can_change_and_renew_course_access_deadline(): void
    {
        $this->freezeSecond();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create();
        $student->courses()->attach($course->id, ['source' => 'tutory', 'expires_at' => now()->subDay()]);
        $enrollment = $this->enrollment($student, $course);

        $this->actingAs($admin);

        Livewire::test(StudentCoursesRelationManager::class, ['ownerRecord' => $student, 'pageClass' => EditUser::class])
            ->assertCanSeeTableRecords([$enrollment])
            ->callTableAction('edit', $enrollment, data: ['expires_at' => '2030-05-10 23:59:00'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('2030-05-11 02:59:00', $enrollment->refresh()->expires_at->toDateTimeString());

        Livewire::test(StudentCoursesRelationManager::class, ['ownerRecord' => $student, 'pageClass' => EditUser::class])
            ->callTableAction('renew', $enrollment);

        $this->assertTrue($enrollment->refresh()->expires_at->equalTo(now()->addYear()));
    }

    public function test_admin_created_subscriber_defaults_to_one_year(): void
    {
        Notification::fake();
        $this->freezeSecond();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Assinante Manual', 'email' => 'assinante-manual@example.com', 'role' => 'subscriber'])
            ->call('create')
            ->assertHasNoFormErrors();

        $subscriber = User::findByEmail('assinante-manual@example.com');
        $this->assertSame(now()->addYear()->format('Y-m-d H:i'), $subscriber->access_expires_at->format('Y-m-d H:i'));
    }
}
