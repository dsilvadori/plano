<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->from('nao-responda@vencendoconcursos.com.br', 'Vencendo Concursos')
            ->subject('Redefina sua senha | Plataforma Vencendo Concursos')
            ->greeting('Olá!')
            ->line('Recebemos uma solicitação para criar ou redefinir a senha da sua conta.')
            ->line('Clique no botão abaixo para escolher uma nova senha de acesso à Plataforma Vencendo Concursos.')
            ->action('Redefinir senha', $url)
            ->line('Este link expira em 2 dias.')
            ->line('Se você não solicitou essa alteração, ignore este e-mail.')
            ->salutation('Equipe Vencendo Concursos');
    }
}
