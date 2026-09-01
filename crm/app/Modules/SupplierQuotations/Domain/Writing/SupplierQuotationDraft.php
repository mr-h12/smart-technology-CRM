<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Domain\Writing;

/**
 * The fields a caller may write on a supplier quotation — `DealDraft`'s shape
 * (Module 5 Point 2.3), on the same reasoning.
 *
 * ── Two of §7.2's rows are absent, each for a documented reason ────────────
 *
 * | row | why it is not here |
 * |---|---|
 * | `code` | "Automatic" (§7.2, §4.7) — {@see \App\Modules\SupplierQuotations\Infrastructure\EloquentSupplierQuotationDirectory} allocates it, and a setter would let a caller collide with `document_sequences`. |
 * | `entered_by` | `DB-02`'s `created_by`, set from the actor the use case authenticated, never from the request body. |
 *
 * `pdf_file` and `Line items` are absent for a different reason: they are not
 * columns of this table at all (`D-71`'s pivot, and Point 1.2's table). Writing
 * the lines alongside the header is Step 2 Point 2.1's transaction, not this
 * draft's job — a caller today creates the header and nothing else.
 *
 * ── There is no `forUpdate()`, and that is not an oversight ────────────────
 *
 * `PATCH` is Point 2.3. Which of these fields survive an edit — whether an
 * offer may change supplier, above all — is a question that point asks with
 * §7.2 open. A `forUpdate()` written now would be answering it here, in the
 * class least able to explain itself.
 */
final readonly class SupplierQuotationDraft
{
    /**
     * §7.2's fields, minus the two the table above explains.
     *
     * `currency_id` rather than §7.2's literal `currency`: the column is an id,
     * because `currencies.code` is unique only among live rows and PostgreSQL
     * cannot point a foreign key at a partial unique index (Point 1.1).
     */
    public const WRITABLE_ON_CREATE = [
        'supplier_id',
        'deal_id',
        'total_price',
        'currency_id',
        'offer_date',
        'valid_until',
        'notes',
    ];

    /** @param  array<string, mixed>  $attributes  already validated at the boundary */
    private function __construct(public array $attributes) {}

    /** @param  array<string, mixed>  $validated */
    public static function forCreate(array $validated): self
    {
        $attributes = [];

        foreach (self::WRITABLE_ON_CREATE as $key) {
            // array_key_exists and not `??`: `notes: null` is an erasure the
            // caller asked for, and `??` would silently drop it — `DealDraft`
            // made the same choice for the same reason.
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key];
            }
        }

        return new self($attributes);
    }
}
