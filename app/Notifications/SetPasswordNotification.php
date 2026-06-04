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
            ->subject('Acesse seu Plano de Estudos | Vencendo Concursos')
            ->greeting('Olá, ' . $notifiable->name . '!')
            ->line('Seu acesso ao Plano de Estudos da Vencendo Concursos foi criado.')
            ->line('Agora falta só criar sua senha para começar a organizar sua preparação.')
            ->action('Criar minha senha', $url)
            ->line('Bons estudos,')
            ->line('Equipe Vencendo Concursos');
    }
}
