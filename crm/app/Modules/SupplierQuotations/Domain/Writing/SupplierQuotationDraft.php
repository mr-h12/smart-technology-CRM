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
 * `pdf_file` is absent for a different reason: it is not a column of this table
 * at all (`D-71`'s pivot).
 *
 * ── `Line items` is carried, and separately from the header ────────────────
 *
 * Point 2.1. §7.2's `Line items` row — "Product · **price** · quantity (+ to
 * add more)" — is one submitted payload with the header, so it is one draft;
 * but it is a different **table**, so it is a different property. `attributes`
 * is what `fill()` may be handed and `items` is what the second insert takes,
 * and keeping them apart is what stops a stray `items` key reaching a column
 * that does not exist.
 *
 * ── `total_price` stays writable: the owner's ruling of 2026-09-02 ─────────
 *
 * **It is entered by the user, not summed from the lines.** §7.2 lists it as a
 * field of its own, which permits it to differ from their sum, and the owner
 * chose that over "always computed" and over "typed with a warning". So nothing
 * in this module computes it, and `CreateSupplierQuotationTest` submits a total
 * that contradicts its lines to keep it that way. Recorded in `CHECKLIST.md`
 * awaiting a `D-xx`; `docs/` is untouched.
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

    /** §7.2's three facts about a line, and nothing else a caller may send. */
    public const ITEM_WRITABLE = ['catalog_item_id', 'unit_price', 'quantity'];

    /**
     * @param  array<string, mixed>  $attributes  already validated at the boundary
     * @param  list<array<string, mixed>>  $items
     */
    private function __construct(public array $attributes, public array $items) {}

    /** @param  array<string, mixed>  $validated */
    public static function forCreate(array $validated): self
    {
        return new self(
            self::only($validated, self::WRITABLE_ON_CREATE),
            self::lines($validated),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private static function lines(array $validated): array
    {
        $submitted = $validated['items'] ?? [];

        if (! is_array($submitted)) {
            // Unreachable once Point 2.2's Form Request runs in front of this;
            // an empty list rather than a guess is what a caller that sent
            // nothing usable asked for.
            return [];
        }

        $items = [];

        foreach ($submitted as $line) {
            if (! is_array($line)) {
                continue;
            }

            $keyed = [];

            // A key off a `mixed` payload is `array-key`, not `string`, and
            // PHPStan runs at level 10 where that is an error rather than a
            // narrowing. Re-keying is the narrowing; a cast would be the
            // escape hatch `CLAUDE.md` forbids.
            foreach ($line as $key => $value) {
                $keyed[(string) $key] = $value;
            }

            $items[] = self::only($keyed, self::ITEM_WRITABLE);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private static function only(array $validated, array $allowed): array
    {
        $kept = [];

        foreach ($allowed as $key) {
            // array_key_exists and not `??`: `notes: null` is an erasure the
            // caller asked for, and `??` would silently drop it — `DealDraft`
            // made the same choice for the same reason.
            if (array_key_exists($key, $validated)) {
                $kept[$key] = $validated[$key];
            }
        }

        return $kept;
    }
}
