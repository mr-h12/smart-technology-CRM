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
 * | `customer_status` | §4.5 and `D-49` derive it. A setter is the first place somebody edits it by hand. |
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
    /** §4.2's user-entered fields. */
    public const WRITABLE = [
        'name', 'sector', 'region', 'contact_person',
        'phone', 'phone2', 'whatsapp', 'email', 'start_date', 'notes',
    ];

    /** @return list<string> */
    public static function writableOnCreate(): array
    {
        return [...self::WRITABLE, 'sales_owner_id'];
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
