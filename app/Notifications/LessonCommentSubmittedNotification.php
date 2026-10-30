<?php

namespace App\Notifications;

use App\Models\LessonComment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LessonCommentSubmittedNotification extends Notification
{
    public function __construct(protected LessonComment $comment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->from('nao-responda@vencendoconcursos.com.br', 'Vencendo Concursos')
            ->subject('Nova dúvida de aluno na plataforma')
            ->greeting('Nova dúvida recebida')
            ->line('Aluno: '.$this->comment->user->name.' <'.$this->comment->user->email.'>')
            ->line('Curso: '.$this->comment->course->name)
            ->line('Aula: '.$this->comment->lesson->title)
            ->line('Mensagem:')
            ->line($this->comment->body)
            ->action('Responder no painel admin', url('/admin/lesson-comments/'.$this->comment->id.'/edit'))
            ->line('Qualquer administrador pode responder ou excluir este comentário.');
    }
}
