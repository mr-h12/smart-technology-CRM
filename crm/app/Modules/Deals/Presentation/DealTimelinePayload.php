<?php

declare(strict_types=1);

namespace App\Modules\Deals\Presentation;

use App\Modules\Audit\Domain\Reading\AuditRecord;
use App\Modules\Audit\Domain\Reading\AuditRecordPage;

/**
 * How one timeline entry appears on the wire — §4.4's four fields, in
 * `OpenAPI §8`'s shapes.
 *
 * ── `old_status` and `new_status` are lifted out of the payloads ───────────
 *
 * The stored row keeps whole `old_values`/`new_values` objects, and a deal's
 * `DEAL_UPDATED` row carries whatever changed — a title, a source. The
 * criterion asks for *status*, so status is what this names, and a row that
 * changed something else answers null for both rather than being hidden: the
 * entry still happened, and a timeline that silently dropped it would be a
 * history with holes in it.
 *
 * ── `actor_id`, not an actor name ──────────────────────────────────────────
 *
 * The name belongs to Identity, and `CLAUDE.md` forbids this module reading
 * another module's rows. Module 6 Point 6.2 took the same answer for a
 * currency it could not resolve: publish the identifier honestly rather than
 * inventing a join. Owed to a later point, on the debt register.
 *
 * `impersonated_user_id` is published because `SEC-10`'s reason for storing it
 * is exactly this — a history naming only the actor would read identically
 * whether or not the change was made through somebody else's account.
 * `ip_address`, `user_agent`, `request_id` and `correlation_id` are not on the
 * read model at all, and so cannot appear here.
 *
 * Timestamps in ISO-8601 UTC (`DB-08`).
 */
final class DealTimelinePayload
{
    /** @return array<string, mixed> */
    public static function of(AuditRecord $record): array
    {
        return [
            'id' => $record->id,
            'event' => $record->event,
            'old_status' => $record->oldValue('status'),
            'new_status' => $record->newValue('status'),
            'actor_id' => $record->actorId,
            'impersonated_user_id' => $record->impersonatedUserId,
            'occurred_at' => $record->recordedAt->format(DATE_ATOM),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function many(AuditRecordPage $page): array
    {
        return array_map(static fn (AuditRecord $r): array => self::of($r), $page->items);
    }

    /** @return array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool} */
    public static function pagination(AuditRecordPage $page): array
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
