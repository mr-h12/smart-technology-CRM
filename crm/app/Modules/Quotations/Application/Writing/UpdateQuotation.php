<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Admin\Domain\Money\Decimal;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationAdditionalLine;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Pricing\PricingMode;
use App\Modules\Quotations\Domain\Writing\QuotationDraft;
use App\Modules\Quotations\Domain\Writing\QuotationEtag;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * `PATCH /quotations/{id}` — Module 7 Point 3.6. A Draft is re-priced from a
 * full editable body and written under `DB-12`'s optimistic lock.
 *
 * ── The order of refusals, and why ─────────────────────────────────────────
 *
 * 1. **Scope** (`SEC-08`, 404): the deal's owner through `DealFactsInterface`,
 *    as `ShowQuotation` reads it — a caller who may not see the row learns
 *    nothing from an edit either (§5.1).
 * 2. **`If-Match`** (400 → 409): a header that is missing or not
 *    `quotation:<id>:<n>` is an invalid header; one that names a token other
 *    than the stored one is `409 concurrency_conflict` (`API-12`), answered
 *    before the status so a client working from a stale copy is told to
 *    reload rather than told about a status it may not have seen change.
 *    Steps 1–2 are {@see QuotationWriteAccess}, shared with every action on
 *    one quotation (Point 4.2).
 * 3. **Draft only** (422): §3.5's `edit` cell reads "(Draft)". Module 8's
 *    "edit & approve" ({@see EditAndApproveQuotation}) is the one caller that
 *    opens this path to `pending` — by naming it in `$editable`, having
 *    already checked §6.4's arrow — and nothing else does.
 * 4. **`edit margin` / `edit tax`** (403): §3.5's two checkmarks, asked only
 *    when the body actually moves a line's margin or tax — re-saving a draft
 *    is not an edit of its margin.
 *
 * The write itself is guarded again in SQL ({@see QuotationDirectoryInterface::update()}),
 * so two edits that both passed step 2 cannot both commit.
 *
 * ── A Draft is re-priced from what was typed ─────────────────────────────────
 *
 * A line is typed in the quotation's currency (`D-164`), so there is no FX rate
 * to capture: the edit re-prices the body it is given, nothing else.
 *
 * ── §6.4: every edit is written to the audit log ───────────────────────────
 *
 * `QUOTATION_UPDATED` with `AUD-02`'s old values read inside the transaction
 * (the header as stored, both child tables) and the new values as submitted
 * and priced. A margin or tax change is inside that pair — the rule that they
 * are "always audited" is met by auditing every edit in full.
 */
final readonly class UpdateQuotation
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private PriceQuotation $pricer,
        private QuotationWriteAccess $access,
        private AuthorizeAction $authorize,
        private AuditRecorderInterface $audit,
        private TermSuggestions $terms,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  `SaveQuotationRequest::validated()`
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.edit` — or on `quotation.approve` when `$editable` is `pending`
     * @param  'draft'|'pending'  $editable  the one status this edit may touch
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     * @throws AuthorizationRefused
     */
    public function update(string $quotationId, array $validated, ?string $ifMatch, array $heldScopes, string $actorId, string $editable = 'draft'): QuotationUpdated
    {
        return $this->connection->transaction(function () use ($quotationId, $validated, $ifMatch, $heldScopes, $actorId, $editable): QuotationUpdated {
            $before = $this->access->open($quotationId, $ifMatch, $heldScopes, $actorId);

            if ($before->status !== $editable) {
                throw QuotationWriteRefused::notDraft();
            }

            $priced = $this->pricer->price($validated, now()->toDateTimeImmutable());

            $this->guardMarginAndTax($before, $priced, $actorId);

            $draft = QuotationDraft::forUpdate($validated)
                ->withComputed($priced->computed)
                ->withLines($priced->items, $priced->additionalItems);

            if (! $this->quotations->update($quotationId, $draft, $before->versionToken, $actorId)) {
                // Passed the check above and lost the race to another writer
                // inside the window — the SQL guard is the one that counts.
                throw QuotationWriteRefused::staleVersion(QuotationEtag::of($before));
            }

            // Point 6.8 — the saved terms become the actor's suggestions.
            $this->terms->remember($validated, $actorId);

            $this->audit->record(
                AuditEvent::of('QUOTATION_UPDATED'),
                'quotation',
                $quotationId,
                self::stored($before),
                [...$draft->attributes, 'items' => $priced->items, 'additional_items' => $priced->additionalItems],
            );

            return new QuotationUpdated($this->access->reread($quotationId));
        });
    }

    /**
     * §3.5's `edit margin` and `edit tax`, each asked only when the body moves
     * the thing it guards — and, since `D-155`, every line carries both, so
     * "moves" is a comparison with the stored line at the same position (the
     * edit replaces the whole list, and `line_no` is positional). A line with
     * no stored counterpart is an added line: any margin or tax it carries is an edit.
     * A `legacy` quotation's stored margin is `D-106`'s whole margin (the
     * quotation's plus the line's) and its tax the quotation's rate, so
     * converting it at its next save (`D-165`) is not an edit of either.
     *
     * ponytail: positional — a reorder of lines with different margins reads
     * as an edit of the margin. Match by catalog item and supplier if that bites.
     */
    private function guardMarginAndTax(QuotationDetail $before, PricedQuotation $priced, string $actorId): void
    {
        $legacy = $before->pricingMode === PricingMode::Legacy;
        $marginMoved = false;
        $taxMoved = false;

        foreach ($priced->items as $index => $row) {
            $stored = $before->items[$index] ?? null;

            // An appended line has nothing to compare with: its first margin or tax is the edit.
            if ($stored === null) {
                $marginMoved = $marginMoved || self::differs($row['margin_percent'] ?? null, '0');
                $taxMoved = $taxMoved || ($row['tax_percent'] ?? null) !== null;

                continue;
            }

            $storedMargin = $legacy ? bcadd(Decimal::of($before->defaultMargin ?? '0', 'default_margin'), Decimal::of($stored->marginPercent ?? '0', 'margin_percent'), 3) : $stored->marginPercent;
            $storedTax = $legacy ? $before->taxPercent : $stored->taxPercent;

            $marginMoved = $marginMoved || self::differs($row['margin_percent'] ?? null, $storedMargin);
            $taxMoved = $taxMoved || self::differs($row['tax_percent'] ?? null, $storedTax);
        }

        if ($marginMoved && ! $this->authorize->decide($actorId, 'quotation', 'edit_margin')->granted) {
            throw AuthorizationRefused::of('quotation', 'edit_margin');
        }

        if ($taxMoved && ! $this->authorize->decide($actorId, 'quotation', 'edit_tax')->granted) {
            throw AuthorizationRefused::of('quotation', 'edit_tax');
        }
    }

    /** Null against a value differs; two values differ when they are not equal as numbers at 3 places. */
    private static function differs(mixed $new, ?string $stored): bool
    {
        if ($new === null || $stored === null) {
            return $new !== $stored;
        }

        return ! is_numeric($new) || ! is_numeric($stored) || bccomp((string) $new, $stored, 3) !== 0;
    }

    /**
     * `AUD-02`'s old values: the stored header on the draft's own allow-list,
     * so old and new are keyed alike, plus the lines being replaced.
     *
     * @return array<string, mixed>
     */
    private static function stored(QuotationDetail $before): array
    {
        return [
            'quotation_date' => $before->quotationDate,
            'valid_until' => $before->validUntil,
            'currency_id' => $before->currencyId,
            'pricing_mode' => $before->pricingMode->value,
            'default_margin' => $before->defaultMargin,
            'discount_percent' => $before->discountPercent,
            'tax_percent' => $before->taxPercent,
            'tax_percents' => $before->taxPercents,
            'rounding_unit' => $before->roundingUnit,
            'rounding_enabled' => $before->roundingEnabled,
            'subtotal' => $before->subtotal,
            'additional_total' => $before->additionalTotal,
            'discount_amount' => $before->discountAmount,
            'tax_base' => $before->taxBase,
            'tax_amount' => $before->taxAmount,
            'net_amount' => $before->netAmount,
            'total_before_round' => $before->totalBeforeRound,
            'final_total' => $before->finalTotal,
            'rounding_diff' => $before->roundingDiff,
            'terms' => $before->terms,
            'items' => array_map(static fn (QuotationLine $line): array => $line->asRow(), $before->items),
            'additional_items' => array_map(static fn (QuotationAdditionalLine $line): array => [
                'description' => $line->description,
                'amount' => $line->amount,
            ], $before->additionalItems),
        ];
    }
}
