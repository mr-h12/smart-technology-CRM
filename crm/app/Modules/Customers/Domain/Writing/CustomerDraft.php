<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Writing;

/**
 * The fields a caller may write on a customer, and the ones it may not.
 *
 * ── Three of §4.2's columns are absent, each for a documented reason ───────
 *
 * | column | why it is not here |
 * |---|---|
 * | `customer_status` | §4.5 and `D-49` derive it; `D-123`'s hand-set status has its own permission, route and {@see self::forStatusByHand()}. |
 * | `is_archived` | §3.3 makes `archive` its own permission and `OpenAPI §7.2` its own route. Point 3.4. |
 * | `is_incomplete` | `D-31` flags a row the **importer** could not complete. A hand-typed record is not incomplete by choice. Point 3.6. |
 *
 * ── `sales_owner_id` is writable on create and never on update ─────────────
 *
 * §3.3 lists `assign` as a permission of its own, granted to two roles where
 * `edit` is granted to five, and `OpenAPI §7.2` gives it the route
 * `PATCH /customers/{id}/assign`. Accepting the column on a generic update
 * would hand every `edit` holder a permission the matrix does not grant them.
 * Filing a *new* record under an owner is not a transfer, so create keeps it —
 * bounded by §3.3's create scope, which is the whole reason that row has
 * scopes at all.
 *
 * Nothing here validates a **value**: that is the Form Request's job at the
 * boundary. This class fixes the *set of keys*, in one place both the boundary
 * and the persistence adapter read, so the two cannot drift.
 */
final readonly class CustomerDraft
{
    /** §4.2's user-entered fields. `notes` left with `D-122`: notes are their own entries now. */
    public const WRITABLE = [
        'name', 'sector', 'region', 'contact_person', 'contact_title',
        'phone', 'phone2', 'whatsapp', 'email', 'start_date',
    ];

    /**
     * `D-121`'s five links. Kept out of `WRITABLE` because `CustomerCsv` takes
     * its columns from there, and `D-121` keeps the links out of the import.
     */
    public const LINKS = ['location_url', 'website_url', 'social_url_1', 'social_url_2', 'social_url_3'];

    /**
     * The fields whose absence makes a saved row incomplete (`D-87` ruling 1:
     * the core five, not §4.2's ten), read by the importer that sets the flag
     * and by the edit that clears it, so the two cannot disagree on what
     * "complete" means.
     */
    public const EXPECTED = ['name', 'sector', 'region', 'contact_person', 'phone'];

    /** `D-123`: the statuses a holder of `customer.change_status` may set; «عميل» is never one (`D-49`). */
    public const SET_BY_HAND = ['prospect', 'no_response', 'deal_not_completed'];

    /** @return list<string> */
    public static function writableOnCreate(): array
    {
        return [...self::WRITABLE, ...self::LINKS, 'sales_owner_id'];
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
        return new self(self::only($validated, [...self::WRITABLE, ...self::LINKS]));
    }

    /**
     * Point 3.5 — the one field `forUpdate()` deliberately refuses.
     *
     * §3.3 makes `assign` a permission of its own and `OpenAPI §7.2` gives it
     * its own route, so the transfer arrives here instead of through the
     * generic update. It is a named factory rather than a second key list
     * because the point of this class is that the set of writable keys lives in
     * one place: a caller assembling `['sales_owner_id' => ...]` by hand would
     * be that second place.
     */
    public static function forAssignment(string $ownerId): self
    {
        return new self(['sales_owner_id' => $ownerId]);
    }

    /** `D-123`: a status set by hand, and the reason that marks it so. */
    public static function forStatusByHand(string $status, string $reason): self
    {
        return new self(['customer_status' => $status, 'status_reason' => $reason]);
    }

    /**
     * Point 3.6 — the importer's row, and the one caller allowed to set
     * `is_incomplete`.
     *
     * `D-31` makes the flag *"an imported record with missing fields"*, so it
     * belongs to the importer and to nobody else: a hand-typed record is not
     * incomplete by choice, which is why `SaveCustomerRequest` prohibits the
     * field on both verbs.
     *
     * @param  array<string, string>  $attributes  already reduced to WRITABLE keys
     */
    public static function forImport(array $attributes, bool $isIncomplete): self
    {
        return new self([...self::only($attributes, self::WRITABLE), 'is_incomplete' => $isIncomplete]);
    }

    /**
     * `D-87` (F-11 · 1.3) — this draft plus the clear. Clear only: `D-31`
     * makes the flag the importer's, so nothing here ever sets it. The caller
     * decides whether the row is complete; this only carries the answer.
     */
    public function completed(): self
    {
        return new self([...$this->attributes, 'is_incomplete' => false]);
    }

    /** A `PATCH` that names no writable field changes nothing, and must not be reported as a change. */
    public function isEmpty(): bool
    {
        return $this->attributes === [];
    }

    /** The name the duplicate probe compares, or null when this write does not touch it. */
    public function name(): ?string
    {
        $name = $this->attributes['name'] ?? null;

        return is_string($name) ? $name : null;
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
            // array_key_exists and not `??`: `notes: null` is an erasure the
            // caller asked for, and `??` would silently drop it.
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key];
            }
        }

        return $attributes;
    }
}
