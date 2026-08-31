<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Writing;

/**
 * The fields a caller may write on a deal, and the ones it may not —
 * `CustomerDraft`'s shape (Module 3 Point 3.3), on the same reasoning.
 *
 * ── Five of §4.3's columns are absent, each for a documented reason ────────
 *
 * | column | why it is not here |
 * |---|---|
 * | `code` | Automatic (§4.3) — `EloquentDealDirectory` allocates it, and a setter would let a caller collide with `document_sequences`. |
 * | `status` | §4.4's state machine. A generic write is not a transition; `/status` (a later point) is. |
 * | `approval_status` | Derived from *who* creates the deal (§3.4's create scope), not typed by the caller — see `SaveDeal`. |
 * | `rejection_reason` | Belongs to the `/reject` action (a later point), the same way `is_archived` belongs to Customers' `/archive`. |
 * | `last_activity_at` | System-maintained (`D-17`, `J-03`); a caller backdating it would defeat the stale-deal calculation it drives. |
 *
 * ── `customer_id` and `owner_id` are writable on create and never on update ─
 *
 * `customer_id`: §4.1 draws one line into a deal — `Customer └── Deal` — and no
 * source describes moving a deal to a different customer; that is not a
 * documented operation, so there is no field for it.
 *
 * `owner_id`: on `sales_owner_id`'s precedent — §3.4 lists `assign_owner` as a
 * permission of its own, granted to two roles where `edit` is granted to five,
 * and `OpenAPI §7.2` gives transfers their own route. Filing a *new* record
 * under an owner is not a transfer, so create keeps it, bounded by §3.4's
 * create scope.
 */
final readonly class DealDraft
{
    /** §4.3's user-entered fields, writable on both verbs. */
    public const WRITABLE = ['title', 'source', 'service_type'];

    /** @return list<string> */
    public static function writableOnCreate(): array
    {
        return [...self::WRITABLE, 'customer_id', 'owner_id'];
    }

    /** @param  array<string, mixed>  $attributes  already validated at the boundary */
    private function __construct(public array $attributes) {}

    /** @param  array<string, mixed>  $validated */
    public static function forCreate(array $validated): self
    {
        return new self(self::only($validated, self::writableOnCreate()));
    }

    /** @param  array<string, mixed>  $validated */
    public static function forUpdate(array $validated): self
    {
        return new self(self::only($validated, self::WRITABLE));
    }

    /**
     * Point 2.4 — the one field `forUpdate()` deliberately refuses.
     *
     * §3.4 makes `assign_owner` a permission of its own and `OpenAPI §7.2`
     * gives it its own route, so the transfer arrives here instead of through
     * the generic update — `CustomerDraft::forAssignment()`'s precedent
     * (Module 3 Point 3.5).
     */
    public static function forAssignment(string $ownerId): self
    {
        return new self(['owner_id' => $ownerId]);
    }

    /** A `PATCH` that names no writable field changes nothing, and must not be reported as a change. */
    public function isEmpty(): bool
    {
        return $this->attributes === [];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private static function only(array $validated, array $allowed): array
    {
        $attributes = [];

        foreach ($allowed as $key) {
            // array_key_exists and not `??`: `title: null` is an erasure the
            // caller asked for, and `??` would silently drop it.
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key];
            }
        }

        return $attributes;
    }
}
