<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use App\Modules\Identity\Domain\Authentication\AccountLocked;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The mail `SEC-03` and §9 Flow 0 require — "account locked + Super Admin
 * notified".
 *
 * Every line is a lang key (§14.2, Coding Standards §11): this reaches a human
 * inbox, and a hard-coded English sentence is the one kind of string the
 * Arabic-from-day-one requirement cannot tolerate.
 *
 * It carries the locked account's name, address and unlock time and **nothing
 * about the attempt** — no presented password, no token. Coding Standards §9
 * keeps credentials out of anything a mail server touches.
 */
final class AccountLockedNotification extends Notification
{
    public function __construct(private readonly AccountLocked $event) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('identity.lockout_mail.subject'))
            ->line(__('identity.lockout_mail.intro', [
                'name' => $this->event->userName,
                'email' => $this->event->userEmail,
            ]))
            ->line(__('identity.lockout_mail.until', [
                // DB-08: stored and sent as UTC. Rendering it in a reader's
                // timezone needs a reader, and a mail has none.
                'until' => $this->event->lockedUntil->format('Y-m-d H:i').' UTC',
            ]))
            ->line(__('identity.lockout_mail.origin', [
                'ip' => $this->event->ip ?? __('identity.lockout_mail.unknown_ip'),
            ]));
    }
}
