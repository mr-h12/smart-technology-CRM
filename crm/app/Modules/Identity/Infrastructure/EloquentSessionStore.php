<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Authentication\DeviceSession;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Modules\Identity\Domain\RoleAdministration\ReferencePage;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

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

    public function openAs(
        string $accountId,
        string $impersonatorId,
        string $fingerprint,
        ?string $ip,
        ?string $userAgent,
        DateTimeImmutable $at,
    ): string {
        $session = new UserSession;
        $session->fill([
            // The session belongs to the person being impersonated: their id,
            // so their role and their permissions are what resolves (SEC-10).
            'user_id' => $accountId,
            'impersonator_id' => $impersonatorId,
            'session_id' => $fingerprint,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'last_activity_at' => $at,
        ]);
        $session->save();

        return (string) $session->id;
    }

    public function impersonationsBy(string $impersonatorId): int
    {
        // Live rows only — SoftDeletes excludes the revoked ones, so a Super
        // Admin who left an impersonation is not counted as still inside it.
        return UserSession::query()->where('impersonator_id', $impersonatorId)->count();
    }

    /** @return ReferencePage<DeviceSession> */
    public function devicesFor(
        string $accountId,
        ?string $currentSessionId,
        ReferenceListCriteria $criteria,
    ): ReferencePage {
        $query = self::devices($accountId);

        // OpenAPI §6.1: "Pagination always happens after authorization
        // scoping" — the count comes off the same builder the page does, so a
        // total can never describe rows the page filter would have removed.
        $total = $query->count();

        $rows = $query
            ->orderBy($criteria->sortField, $criteria->sortDescending ? 'desc' : 'asc')
            // A deterministic tiebreak: two devices last active in the same
            // millisecond would otherwise page in whatever order PostgreSQL
            // felt like, and a row can then appear on two pages or on none.
            ->orderBy('id')
            ->offset($criteria->offset())
            ->limit($criteria->perPage)
            ->get();

        $items = [];

        foreach ($rows as $row) {
            $items[] = new DeviceSession(
                id: (string) $row->id,
                ipAddress: $row->ip_address,
                userAgent: $row->user_agent,
                // DB-08: stored UTC, handed over as UTC. The display timezone
                // is the SPA's problem and nobody else's.
                lastActivityAt: $row->last_activity_at->toDateTimeImmutable(),
                signedInAt: ($row->created_at ?? $row->last_activity_at)->toDateTimeImmutable(),
                isCurrent: $currentSessionId !== null && (string) $row->id === $currentSessionId,
            );
        }

        return new ReferencePage($items, $total, $criteria->page, $criteria->perPage);
    }

    public function revokeDevice(string $sessionId, string $accountId): bool
    {
        $session = self::devices($accountId)->whereKey($sessionId)->first();

        if (! $session instanceof UserSession) {
            return false;
        }

        // Soft delete, like every other revocation here. DB-01 forbids the
        // physical one, and the row is what a later "who was signed in on the
        // 3rd" question reads.
        $session->delete();

        return true;
    }

    public function revokeOtherDevices(string $accountId, string $currentSessionId): int
    {
        $sessions = self::devices($accountId)
            ->whereKeyNot($currentSessionId)
            ->get();

        foreach ($sessions as $session) {
            // One at a time rather than a mass delete on the builder, so each
            // row goes through the model and gets its `deleted_at` and its
            // `updated_at` written the way a single revocation does.
            $session->delete();
        }

        return $sessions->count();
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

    /**
     * The rows that are **this account's own devices** — `SEC-05`'s list.
     *
     * `whereNull('impersonator_id')` is the rule, not an optimisation. A Login
     * As row is the Super Admin's session driving this account, and §3.1 makes
     * that account "completely hidden from all users": listing it, or letting
     * its id be revoked by guess, names the hidden administrator by
     * implication. Every self-service read and write goes through here so the
     * three of them cannot drift apart.
     *
     * @return Builder<UserSession>
     */
    private static function devices(string $accountId): Builder
    {
        return UserSession::query()
            ->where('user_id', $accountId)
            ->whereNull('impersonator_id');
    }
}
