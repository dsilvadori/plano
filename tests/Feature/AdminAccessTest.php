<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Course;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\SetPasswordNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_admin_panel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Visualizar área do aluno');
    }

    public function test_student_cannot_access_admin_panel(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_can_access_courses_page_and_see_import_action(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/courses')
            ->assertOk()
            ->assertSee('Importar planilha');
    }

    public function test_admin_can_access_users_page_and_see_import_students_action(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Importar alunos');
    }

    public function test_admin_can_access_student_dashboard_and_see_all_active_courses(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@vencendoconcursos.com.br',
        ]);

        $activeCourse = Course::factory()->create([
            'name' => 'Curso Ativo',
            'is_active' => true,
        ]);

        Course::factory()->create([
            'name' => 'Curso Inativo',
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Área de estudos do admin')
            ->assertSee($activeCourse->name)
            ->assertDontSee('Curso Inativo');
    }

    public function test_subscriber_can_access_student_dashboard_and_see_all_active_courses(): void
    {
        $subscriber = User::factory()->create([
            'role' => 'subscriber',
        ]);

        $activeCourse = Course::factory()->create([
            'name' => 'Curso Liberado Para Assinante',
            'is_active' => true,
        ]);

        Course::factory()->create([
            'name' => 'Curso Inativo',
            'is_active' => false,
        ]);

        $this->actingAs($subscriber)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($activeCourse->name)
            ->assertDontSee('Curso Inativo');
    }

    public function test_subscriber_can_access_study_plan_builder_with_all_active_courses(): void
    {
        $subscriber = User::factory()->create([
            'role' => 'subscriber',
        ]);

        Course::factory()->create([
            'name' => 'Curso Assinante',
            'is_active' => true,
        ]);

        $this->actingAs($subscriber)
            ->get(route('study-plans.create'))
            ->assertOk()
            ->assertSee('Criador de plano')
            ->assertSee('Curso Assinante');
    }

    public function test_admin_can_access_study_plan_builder(): void
    {
        $admin = User::factory()->admin()->create();

        Course::factory()->create([
            'name' => 'Curso Admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('study-plans.create'))
            ->assertOk()
            ->assertSee('Criador de plano')
            ->assertSee('Curso Admin');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_admin_login_page_is_translated_to_portuguese(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Faça login')
            ->assertSee('E-mail')
            ->assertSee('Senha');
    }

    public function test_admin_users_page_shows_portuguese_labels(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Administrador Master',
        ]);

        User::factory()->create([
            'name' => 'Aluno Teste',
            'role' => 'student',
        ]);

        User::factory()->create([
            'name' => 'Assinante Teste',
            'role' => 'subscriber',
        ]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Usuários')
            ->assertSee('Visualizar área do aluno')
            ->assertSee('Administrador')
            ->assertSee('Aluno')
            ->assertSee('Assinante');
    }

    public function test_admin_webhooks_page_is_read_only(): void
    {
        $admin = User::factory()->admin()->create();

        WebhookEvent::factory()->create([
            'provider' => 'tutory',
            'event_id' => 'evt_read_only',
            'event_type' => 'pagamento_aprovado',
            'status' => 'processed',
            'processed_at' => CarbonImmutable::parse('2026-06-07 21:00:00', 'UTC'),
            'created_at' => CarbonImmutable::parse('2026-06-07 21:00:00', 'UTC'),
        ]);

        $this->actingAs($admin)
            ->get('/admin/webhook-events')
            ->assertOk()
            ->assertSee('Webhooks')
            ->assertSee('evt_read_only')
            ->assertSee('07/06/2026 18:00')
            ->assertDontSee('Criar webhook')
            ->assertDontSee('Novo webhook');
    }

    public function test_admin_can_create_student_without_password_and_send_first_access_email(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Aluno Primeiro Acesso',
                'email' => 'primeiro-acesso@example.com',
                'role' => 'student',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $student = User::where('email', 'primeiro-acesso@example.com')->first();

        $this->assertNotNull($student);
        $this->assertSame('student', $student->role);
        Notification::assertSentTo($student, SetPasswordNotification::class);
    }

    public function test_admin_can_create_subscriber_without_password_and_send_first_access_email(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Assinante Primeiro Acesso',
                'email' => 'assinante-acesso@example.com',
                'role' => 'subscriber',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $subscriber = User::where('email', 'assinante-acesso@example.com')->first();

        $this->assertNotNull($subscriber);
        $this->assertSame('subscriber', $subscriber->role);
        Notification::assertSentTo($subscriber, SetPasswordNotification::class);
    }

    public function test_first_access_email_uses_no_reply_sender_and_reset_password_link(): void
    {
        $student = User::factory()->create([
            'name' => 'Aluno Teste',
            'email' => 'aluno@example.com',
            'role' => 'student',
        ]);

        $mail = (new SetPasswordNotification('token-teste'))->toMail($student);
        $html = (string) $mail->render();

        $this->assertSame(['nao-responda@vencendoconcursos.com.br', 'Vencendo Concursos'], $mail->from);
        $this->assertSame('Primeiro acesso ao Plano de Estudos | Vencendo Concursos', $mail->subject);
        $this->assertSame('Criar senha de primeiro acesso', $mail->actionText);
        $this->assertStringContainsString(route('password.reset', ['token' => 'token-teste'], false), $mail->actionUrl);
        $this->assertStringContainsString('email=aluno%40example.com', $mail->actionUrl);
        $this->assertStringContainsString('Olá, Aluno Teste!', $html);
        $this->assertStringContainsString('Este link expira em 2 dias.', $html);
        $this->assertStringContainsString('Se você estiver com dificuldade para clicar no botão', $html);
        $this->assertStringContainsString('Todos os direitos reservados.', $html);
        $this->assertStringNotContainsString('Regards', $html);
        $this->assertStringNotContainsString("If you're having trouble", $html);
    }
}
