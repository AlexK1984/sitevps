<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'email' => $notifiable->getEmailForPasswordReset(),
        ]).'#'.rawurlencode($this->token);

        return (new MailMessage)
            ->subject('Сброс пароля')
            ->greeting('Здравствуйте!')
            ->line('Мы получили запрос на сброс пароля для вашего аккаунта.')
            ->action('Задать новый пароль', $url)
            ->line('Ссылка действует 60 минут и может быть использована только один раз.')
            ->line('Если вы не запрашивали сброс, просто проигнорируйте это письмо.');
    }
}
