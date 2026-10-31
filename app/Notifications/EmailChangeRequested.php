<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the OLD (current) address when a change is requested: the new address masked, no link. */
class EmailChangeRequested extends Notification
{
    public function __construct(public readonly string $maskedNew) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('account.mail.requested.subject'))
            ->line(__('account.mail.requested.intro', ['new' => $this->maskedNew]))
            ->line(__('account.mail.requested.until'))
            ->line(__('account.mail.requested.not_you'));
    }
}
