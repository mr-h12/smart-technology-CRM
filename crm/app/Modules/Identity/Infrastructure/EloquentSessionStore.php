<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use DateTimeImmutable;

/**
 * {@see SessionStoreInterface} over `user_sessions` — `SEC-05`'s device list.
 */
final class EloquentSessionStore implements SessionStoreInterface
{
    public function open(
        string $accountId,
        string $fingerprint,
        ?string $ip,
        ?string $userAgent,
        DateTimeImmutable $at,
    ): string {
        $session = new UserSession;
        $session->fill([
            'user_id' => $accountId,
            'session_id' => $fingerprint,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'last_activity_at' => $at,
        ]);
        $session->save();

        return (string) $session->id;
    }

    public function revoke(string $sessionId, string $accountId): void
    {
        $session = UserSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $accountId)   // never another person's device
            ->first();

        // Soft delete. DB-01 forbids the physical one, and the row is what a
        // later "who was signed in on the 3rd" question reads.
        $session?->delete();
    }

    public function revokeAllFor(string $accountId): int
    {
        $sessions = UserSession::query()->where('user_id', $accountId)->get();

        foreach ($sessions as $session) {
            // One at a time rather than a mass `->delete()` on the builder, so
            // each row goes through the model and gets its `deleted_at` and its
            // `updated_at` written the same way a single revocation does.
            $session->delete();
        }

        return $sessions->count();
    }
}
