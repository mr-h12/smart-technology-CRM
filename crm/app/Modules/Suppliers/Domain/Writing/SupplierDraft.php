<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Domain\Writing;

/**
 * The fields a caller may write on a supplier.
 *
 * ── One list, not two, because §3.7 is one cell ────────────────────────────
 *
 * `CustomerDraft` splits create from update because §3.3 makes `assign` a
 * permission of its own with its own route, so accepting `sales_owner_id` on a
 * generic update would hand every `edit` holder a permission the matrix does
 * not grant. §3.7 has no such split: its write row is a single cell reading
 * "create · edit · deactivate · set colour ✅", one permission covering all
 * four. So `color_rating` and `is_active` are ordinary fields here rather than
 * action routes, and the writable set is the same on both verbs. Only whether
 * `name` is required differs, and that is the Form Request's business.
 *
 * ── `linked_quotations` is absent, as it is from the table ─────────────────
 *
 * §7.1 marks it "Automatic" and Module 6 derives it from
 * `supplier_quotations.supplier_id`. A caller cannot write a derived fact.
 *
 * Nothing here validates a **value**: that is the Form Request's job at the
 * boundary. This class fixes the *set of keys*, in one place both the boundary
 * and the persistence adapter read, so the two cannot drift.
 */
final readonly class SupplierDraft
{
    /** §7.1's user-entered fields. */
    public const WRITABLE = [
        'name', 'type', 'color_rating', 'phone', 'contact_person',
        'has_open_account', 'is_active',
    ];

    /**
     * §7.1's colour meanings, declared once.
     *
     * `DB-05` requires enum *tables* for four named lists and this is not one
     * of them, so the closed set is code — the same reading Point 1.1 applied
     * when it wrote the CHECK constraint. This constant is what the boundary
     * validates against; the CHECK is what the database enforces; and
     * `SupplierWriteEndpointTest` round-trips **every** value here through the
     * API, so the two cannot drift apart silently.
     */
    public const RATINGS = ['green', 'yellow', 'red', 'white'];

    /** §7.1: "name · type | supplier / distributor". */
    public const TYPES = ['supplier', 'distributor'];

    /** @param  array<string, mixed>  $attributes  already validated at the boundary */
    private function __construct(public array $attributes) {}

    /** @param  array<string, mixed>  $validated */
    public static function of(array $validated): self
    {
        $attributes = [];

        foreach (self::WRITABLE as $key) {
            // array_key_exists and not `??`: `phone: null` is an erasure the
            // caller asked for, and `??` would silently drop it.
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key];
            }
        }

        return new self($attributes);
    }

    /** A `PATCH` that names no writable field changes nothing, and must not be reported as a change. */
    public function isEmpty(): bool
    {
        return $this->attributes === [];
    }
}
