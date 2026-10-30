<?php

namespace App\Notifications;

use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class CourseAccessGrantedNotification extends Notification
{
    use Queueable;

    /**
     * @param  Collection<int, Course>  $courses
     */
    public function __construct(protected Collection $courses)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $courseNames = $this->courses
            ->map(fn (Course $course): string => $course->name)
            ->filter()
            ->values();

        $mail = (new MailMessage)
            ->from('nao-responda@vencendoconcursos.com.br', 'Vencendo Concursos')
            ->subject($courseNames->count() === 1
                ? 'Novo curso liberado na Plataforma Vencendo Concursos'
                : 'Novos cursos liberados na Plataforma Vencendo Concursos')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line($courseNames->count() === 1
                ? 'Seu acesso ao novo curso foi liberado na Plataforma Vencendo Concursos.'
                : 'Seus acessos aos novos cursos foram liberados na Plataforma Vencendo Concursos.');

        $courseNames->each(fn (string $courseName) => $mail->line('- '.$courseName));

        return $mail
            ->line('Você pode entrar com o e-mail e a senha que já usa na plataforma.')
            ->action('Acessar meus cursos', route('courses.mine'))
            ->line('Se você não reconhece esta compra, entre em contato com nossa equipe.')
            ->line('Bons estudos!')
            ->salutation('Equipe Vencendo Concursos');
    }
}
