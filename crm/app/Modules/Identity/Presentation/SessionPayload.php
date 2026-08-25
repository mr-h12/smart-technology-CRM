<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Domain\Authentication\DeviceSession;
use App\Modules\Identity\Domain\RoleAdministration\ReferencePage;

/**
 * `SEC-05`'s device list, serialised — `OpenAPI §4.2` and §8.
 *
 * ── The two fields that are not here ───────────────────────────────────────
 *
 * `session_id` — the SHA-256 digest `D-74` matches a live bearer token
 * against. Coding Standards §9 forbids exposing session tokens, and this is
 * the value the comparison is made on.
 *
 * `impersonator_id` — §3.1's Super Admin is "completely hidden from all
 * users". The store never returns an impersonation row to its own account, so
 * there is nothing here to omit; this note exists so that a later change which
 * starts returning them has to answer for the field it would then need.
 *
 * ── The user agent is sent raw ─────────────────────────────────────────────
 *
 * Not parsed into "Chrome on Windows". A user-agent parser is a dependency and
 * a table of strings that goes stale, and §13 screen 2 asks for "devices · IP ·
 * browser" without saying who does the reading. The honest value is what the
 * device actually sent; the screen truncates it for display and shows the whole
 * string on demand.
 */
final class SessionPayload
{
    /** @return array<string, mixed> */
    public static function of(DeviceSession $session): array
    {
        return [
            'id' => $session->id,
            'ip_address' => $session->ipAddress,
            'user_agent' => $session->userAgent,
            // DB-08: UTC on the wire, converted for display only. DATE_ATOM is
            // what every other payload in this module already sends.
            'last_activity_at' => $session->lastActivityAt->format(DATE_ATOM),
            'signed_in_at' => $session->signedInAt->format(DATE_ATOM),
            // Which row the caller is looking through. The screen refuses to
            // offer a revoke control on it, and the API refuses it too.
            'is_current' => $session->isCurrent,
        ];
    }

    /**
     * @param  ReferencePage<DeviceSession>  $page
     * @return list<array<string, mixed>>
     */
    public static function many(ReferencePage $page): array
    {
        return array_map(self::of(...), $page->items);
    }

    /**
     * `OpenAPI §4.2`'s six pagination keys, all of them written out.
     *
     * @param  ReferencePage<DeviceSession>  $page
     * @return array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}
     */
    public static function pagination(ReferencePage $page): array
    {
        return [
            'page' => $page->page,
            'per_page' => $page->perPage,
            'total' => $page->total,
            'total_pages' => $page->totalPages(),
            'has_next_page' => $page->hasNextPage(),
            'has_previous_page' => $page->hasPreviousPage(),
        ];
    }
}
