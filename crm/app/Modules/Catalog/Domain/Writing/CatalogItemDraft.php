<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Writing;

/**
 * The fields a caller may write on a catalog item.
 *
 * ── One list, not two, because §3.7 is one cell ────────────────────────────
 *
 * The same reading {@see \App\Modules\Suppliers\Domain\Writing\SupplierDraft}
 * applied: §3.7's write row is "create · edit · deactivate · set colour ✅",
 * one permission covering all of it. So `is_active` is an ordinary field here
 * rather than an action route, and the writable set is the same on both verbs.
 * What differs between the verbs — whether `kind` is required, and what a
 * product owes that a service does not — is the Form Request's business.
 *
 * ── One list, not two, because the *table* is one too ──────────────────────
 *
 * §7.3's Product and Service are two tabs of one table (Point 1.2), so both
 * tabs' fields appear here and a row leaves the other tab's columns null.
 *
 * ── No price of any kind, and that is the requirement ──────────────────────
 *
 * §7.3 opens "Descriptive data only — **no prices**" and `D-21` puts every
 * price on the supplier quotation. A field absent from this list cannot be
 * written even if the boundary let it through, which is why the refusal is
 * stated twice — here by omission, and in the Form Request by `prohibited`.
 *
 * Nothing here validates a **value**: that is the Form Request's job at the
 * boundary. This class fixes the *set of keys*, in one place both the boundary
 * and the persistence adapter read, so the two cannot drift.
 */
final readonly class CatalogItemDraft
{
    /** §7.3's user-entered fields, across both tabs. */
    public const WRITABLE = [
        'kind', 'name', 'product_code', 'category', 'unit', 'service_type',
        'company', 'description', 'notes', 'is_active',
    ];

    /**
     * §7.3's two tabs, declared once.
     *
     * `DB-05` requires enum *tables* for four named lists — sectors, units,
     * service types and delivery terms — and this is not one of them, so the
     * closed set is code. That is the same reading Point 1.2 applied when it
     * wrote `catalog_items_known_kind`. This constant is what the boundary
     * validates against; the CHECK is what the database enforces; and
     * `CatalogItemWriteEndpointTest` round-trips **every** value here through
     * the API, so the two cannot drift apart silently.
     */
    public const KINDS = ['product', 'service'];

    /** @param  array<string, mixed>  $attributes  already validated at the boundary */
    private function __construct(public array $attributes) {}

    /** @param  array<string, mixed>  $validated */
    public static function of(array $validated): self
    {
        $attributes = [];

        foreach (self::WRITABLE as $key) {
            // array_key_exists and not `??`: `notes: null` is an erasure the
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
