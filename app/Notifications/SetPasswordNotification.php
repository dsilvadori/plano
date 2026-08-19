<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(protected string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return (new MailMessage)
            ->from('nao-responda@vencendoconcursos.com.br', 'Vencendo Concursos')
            ->subject('Primeiro acesso à Plataforma Vencendo Concursos')
            ->greeting('Olá, ' . $notifiable->name . '!')
            ->line('Seu acesso à nova Plataforma Vencendo Concursos foi criado.')
            ->line('Convidamos você a testar a plataforma e conhecer uma forma mais organizada de estudar.')
            ->line('Na área do aluno, você poderá criar seu plano de estudos, assistir às aulas do seu curso e acompanhar sua evolução.')
            ->line('Você também terá suporte de IA para criar resumos, mapas mentais, questões para treino e conversar no chat para tirar dúvidas.')
            ->line('Para começar, crie sua senha de primeiro acesso no link abaixo.')
            ->action('Criar senha de primeiro acesso', $url)
            ->line('Este link expira em 2 dias.')
            ->line('Se você não solicitou este acesso, ignore este e-mail.')
            ->line('Bons estudos!')
            ->salutation('Equipe Vencendo Concursos');
    }
}
