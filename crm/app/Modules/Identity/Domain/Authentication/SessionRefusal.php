<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

/**
 * Why a device revocation was refused, and the `OpenAPI §5.1` row it maps to.
 *
 * `SEC-05` asks for an active device list and a force logout. There is no
 * permission to check — the right to end your own session is having it — so
 * these are the only two ways the command can fail.
 */
enum SessionRefusal: string
{
    /**
     * No live session with that id **belongs to this account**.
     *
     * §5.1: "Resource does not exist **or is not visible to the caller**", and
     * both halves are load-bearing. Somebody else's session id and a session id
     * that never existed answer identically, because telling them apart turns
     * this endpoint into a way to test whether a given id is a live session.
     *
     * An impersonation row answers this way too: §3.1 hides the Super Admin,
     * so from this endpoint's side a Login As session is not one of this
     * account's devices.
     *
     * ⚠️ The message says "that device is not signed in" and deliberately does
     * **not** say whose account it belongs to. Point 5.5 reaches this same
     * refusal from `DELETE /users/{id}/sessions/{session}`, where the caller is
     * an administrator and "your account" would name the wrong person — caught
     * by a live probe, not by a test, because both wordings are grammatical.
     */
    case SessionNotFound = 'session_not_found';

    /**
     * The caller asked to revoke the session they are calling with.
     *
     * A `422 business_rule_blocked` and not a silent success, because the two
     * actions are genuinely different: `POST /auth/logout` writes a `LOGOUT`
     * audit row and tells the SPA to clear the token it is holding, while this
     * endpoint returns 200 to a client that would carry on using a credential
     * the server had just killed. §9 Flow 0 ends the session by signing out;
     * this refusal points there rather than half-doing it.
     */
    case SessionIsCurrent = 'session_is_current';

    public function status(): int
    {
        return match ($this) {
            self::SessionNotFound => 404,
            self::SessionIsCurrent => 422,
        };
    }

    /** The `OpenAPI §5.1` top-level code for that status. */
    public function errorCode(): string
    {
        return match ($this) {
            self::SessionNotFound => 'resource_not_found',
            self::SessionIsCurrent => 'business_rule_blocked',
        };
    }

    public function messageKey(): string
    {
        return 'identity.session.'.$this->value;
    }
}
