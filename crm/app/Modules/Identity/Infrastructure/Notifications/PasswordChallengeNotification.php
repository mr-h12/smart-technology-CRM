<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use App\Modules\Identity\Domain\Authentication\PasswordChallengeIssued;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * `SEC-04`'s mail — the code §9 Flow 0 sends before a password may change.
 *
 * Every line is a lang key (§14.2, Coding Standards §11): this reaches a human
 * inbox, and the company reads Arabic.
 *
 * **Not queued, on purpose.** `Notification` here implements no `ShouldQueue`,
 * so the code never reaches a serialised job payload sitting in Redis — which
 * is the one place a fifteen-minute secret has no business being. It also means
 * a dead mail server surfaces as a failed request rather than as a code that
 * silently never arrives.
 */
final class PasswordChallengeNotification extends Notification
{
    public function __construct(private readonly PasswordChallengeIssued $event) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('identity.challenge_mail.subject'))
            ->greeting(__('identity.challenge_mail.greeting', ['name' => $this->event->userName]))
            ->line(__('identity.challenge_mail.intro'))
            ->line('**'.$this->event->code.'**')
            ->line(__('identity.challenge_mail.expires', [
                // DB-08: stored and sent as UTC. Rendering it in the reader's
                // timezone needs a reader, and a mail has none.
                'until' => $this->event->expiresAt->format('Y-m-d H:i').' UTC',
            ]))
            ->line(__('identity.challenge_mail.ignore'));
    }
}
