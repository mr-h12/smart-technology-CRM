<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use App\Modules\Identity\Domain\Authentication\AccountLocked;
use App\Modules\Identity\Domain\Contracts\AccountDirectoryInterface;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Support\Facades\Notification;

/**
 * `SEC-03`'s "+ Super Admin notification", and §9 Flow 0's "an email is sent".
 *
 * In Infrastructure because sending mail is infrastructure, and because it maps
 * account ids back to notifiable models — which Application may not do.
 */
final class NotifySuperAdminOfLockout
{
    public function __construct(private readonly AccountDirectoryInterface $accounts) {}

    public function handle(AccountLocked $event): void
    {
        $ids = $this->accounts->superAdminIds();

        if ($ids === []) {
            // Nothing to send and nothing to fail. A deployment with no Super
            // Admin is a seeding problem, and throwing here would turn it into
            // a failed login for somebody else.
            return;
        }

        $recipients = User::query()->whereKey($ids)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new AccountLockedNotification($event));
    }
}
