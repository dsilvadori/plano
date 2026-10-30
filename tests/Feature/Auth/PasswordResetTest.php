<?php

namespace Tests\Feature\Auth;

use App\Models\Course;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response
            ->assertStatus(200)
            ->assertSee('Primeiro acesso')
            ->assertSee('Receba o link para criar sua senha.')
            ->assertSee('Enviar link de acesso');
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->from('/forgot-password')
            ->followingRedirects()
            ->post('/forgot-password', ['email' => $user->email]);

        $response
            ->assertSee('Enviamos o link para o seu e-mail. Confira sua caixa de entrada para criar ou redefinir sua senha.')
            ->assertDontSee('id="email"', false)
            ->assertDontSee('Enviar link de acesso');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response
                ->assertStatus(200)
                ->assertSee('Primeiro acesso')
                ->assertSee('Crie sua senha para entrar na área do aluno.');

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_first_access_password_redirects_to_main_course_and_stores_cookie(): void
    {
        $user = User::factory()->create([
            'email' => 'aluno@example.com',
            'role' => 'student',
        ]);
        $course = Course::factory()->create([
            'name' => 'Curso Principal',
            'slug' => 'curso-principal',
        ]);
        $user->courses()->attach($course);

        $token = Password::broker('first_access')->createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'first_access' => '1',
            'password' => 'nova-senha-segura',
            'password_confirmation' => 'nova-senha-segura',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('courses.show', ['course' => $course->slug]))
            ->assertCookie('vc_first_access_completed');

        $this->assertAuthenticatedAs($user);
    }

    public function test_used_first_access_link_redirects_to_login_when_completion_cookie_exists(): void
    {
        $user = User::factory()->create([
            'email' => 'aluno@example.com',
            'role' => 'student',
        ]);

        $token = Password::broker('first_access')->createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'first_access' => '1',
            'password' => 'nova-senha-segura',
            'password_confirmation' => 'nova-senha-segura',
        ]);

        $completionCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'vc_first_access_completed');

        $this->assertNotNull($completionCookie);

        Auth::logout();
        $this->flushSession();

        $this
            ->withUnencryptedCookie($completionCookie->getName(), $completionCookie->getValue())
            ->get('/reset-password/'.$token.'?email='.urlencode($user->email).'&first_access=1')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Sua senha já foi criada. Entre para acessar seu curso.')
            ->assertSessionHas('url.intended', route('courses.mine'));
    }

    public function test_reset_password_email_is_fully_in_portuguese(): void
    {
        $user = User::factory()->create([
            'email' => 'aluno@example.com',
        ]);

        $notification = new ResetPasswordNotification('token-teste');
        $mail = $notification->toMail($user);
        $html = (string) $mail->render();

        $this->assertSame('Redefina sua senha | Plataforma Vencendo Concursos', $mail->subject);
        $this->assertSame('Redefinir senha', $mail->actionText);
        $this->assertStringContainsString('Olá!', $html);
        $this->assertStringContainsString('Recebemos uma solicitação para criar ou redefinir a senha da sua conta.', $html);
        $this->assertStringContainsString('Este link expira em 2 dias.', $html);
        $this->assertStringContainsString('Se você estiver com dificuldade para clicar no botão', $html);
        $this->assertStringContainsString('Todos os direitos reservados.', $html);
        $this->assertStringNotContainsString('Hello', $html);
        $this->assertStringNotContainsString('Reset Password', $html);
        $this->assertStringNotContainsString('Regards', $html);
        $this->assertStringNotContainsString("If you're having trouble", $html);
    }
}
