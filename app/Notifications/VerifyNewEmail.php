<?php

namespace App\Notifications;

use App\Services\Auth\EmailChange;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the NEW address: the signed link that switches the login email (EmailChange). Rooted at APP_URL. */
class VerifyNewEmail extends Notification
{
    public function __construct(public readonly int $userId, public readonly string $hash) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        $root = rtrim((string) config('app.url'), '/');
        $url = clone app('url');
        $url->forceRootUrl($root);
        $url->forceScheme((string) (parse_url($root, PHP_URL_SCHEME) ?: 'https'));

        return $url->temporarySignedRoute('account.email.verify', now()->addMinutes(EmailChange::LINK_MINUTES), [
            'id' => $this->userId,
            'hash' => $this->hash,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('account.mail.verify.subject'))
            ->line(__('account.mail.verify.intro'))
            ->action(__('account.mail.verify.action'), $this->url())
            ->line(__('account.mail.verify.expires', ['minutes' => EmailChange::LINK_MINUTES]))
            ->line(__('account.mail.verify.ignore'));
    }
}
