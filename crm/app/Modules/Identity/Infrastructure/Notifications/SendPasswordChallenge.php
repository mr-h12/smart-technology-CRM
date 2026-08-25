<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use App\Modules\Identity\Domain\Authentication\PasswordChallengeIssued;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Support\Facades\Notification;

/**
 * Puts `SEC-04`'s code in the account owner's inbox.
 *
 * In Infrastructure because sending mail is infrastructure, and because it maps
 * an account id back to a notifiable model — which Application may not do.
 *
 * **Synchronous, and it must stay that way.** {@see PasswordChallengeIssued}
 * carries the plaintext code; queueing this listener would write that code into
 * a job payload. See that class's comment before changing how it is registered.
 */
final class SendPasswordChallenge
{
    public function handle(PasswordChallengeIssued $event): void
    {
        $recipient = User::query()->whereKey($event->userId)->first();

        if ($recipient === null) {
            // The row went between issuing and mailing. Nothing to send and
            // nothing to fail — throwing here would turn a vanished account
            // into a 500 for a request that already did its job.
            return;
        }

        Notification::send([$recipient], new PasswordChallengeNotification($event));
    }
}
