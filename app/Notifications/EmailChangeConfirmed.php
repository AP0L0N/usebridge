<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the OLD address after the swap: the login email is now the (masked) new one. */
class EmailChangeConfirmed extends Notification
{
    public function __construct(public readonly string $maskedNew) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('account.mail.confirmed.subject'))
            ->line(__('account.mail.confirmed.intro', ['new' => $this->maskedNew]))
            ->line(__('account.mail.confirmed.sessions'))
            ->line(__('account.mail.confirmed.not_you'));
    }
}
