<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use DateTimeImmutable;

/**
 * One row of `SEC-05`'s active device list, as the owner of the account may
 * see it.
 *
 * ── What is deliberately absent ────────────────────────────────────────────
 *
 * There is no `session_id` here and there must never be. `D-74` stores the
 * SHA-256 digest of the bearer token in that column, and Coding Standards §9
 * forbids exposing session tokens — a digest is not the token, but it is the
 * exact value the server compares against, so a screen that displayed it would
 * be publishing the one field that identifies a live credential.
 *
 * There is no `impersonator_id` either, and no flag standing in for one.
 * §3.1 makes the Super Admin "completely hidden from all users", and a row
 * saying *somebody else is driving your account* names them by implication.
 * {@see \App\Modules\Identity\Domain\Contracts\SessionStoreInterface::devicesFor()}
 * answers that by not returning impersonation rows at all: a Login As session
 * is the Super Admin's device, not this account's.
 *
 * ── `is_current` is told, not guessed ──────────────────────────────────────
 *
 * The caller's own session is decided by the guard — `SessionAttribute::NAME`
 * — and passed in. A client that inferred it from "the most recent activity"
 * would mark the wrong row the moment two tabs are open, and the row it marks
 * is the one the screen refuses to let you revoke.
 */
final readonly class DeviceSession
{
    public function __construct(
        public string $id,
        public ?string $ipAddress,
        /** The raw `User-Agent`, never parsed here. See {@see self::class} notes in the payload. */
        public ?string $userAgent,
        public DateTimeImmutable $lastActivityAt,
        public DateTimeImmutable $signedInAt,
        public bool $isCurrent,
    ) {}
}
