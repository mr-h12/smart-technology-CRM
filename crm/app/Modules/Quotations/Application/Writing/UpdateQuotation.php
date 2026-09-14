<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationAdditionalLine;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
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
 * 3. **Draft only** (422): §3.5's `edit` cell reads "(Draft)"; Pending is
 *    Module 8's "edit & approve".
 * 4. **`edit margin` / `edit tax`** (403): §3.5's two checkmarks, asked only
 *    when the body actually moves the margin or the tax — re-saving a draft
 *    is not an edit of its margin. A line margin counts as a margin.
 * 5. **§5.6** (422 `business_rule_blocked`): the same block-or-warn the create
 *    applies, from the same {@see PriceQuotation}.
 *
 * The write itself is guarded again in SQL ({@see QuotationDirectoryInterface::update()}),
 * so two edits that both passed step 2 cannot both commit.
 *
 * ── A Draft is re-priced at the time of the edit ───────────────────────────
 *
 * `D-09` captures the FX rate "at creation" and forbids later FX edits from
 * changing an existing quotation. A Draft being re-saved is not yet the
 * quotation the customer will see, so its lines are converted at the rate
 * effective **now** — the same call the create makes, one timestamp later.
 * The rate on the issued document is therefore the one at its last Draft
 * save, and it is frozen from then on because nothing after Draft comes
 * through here. Stated as an assumption: the document does not say which
 * moment a Draft's edit prices at, and "now" is the reading that keeps one
 * pricing path.
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
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.edit`
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     * @throws AuthorizationRefused
     */
    public function update(string $quotationId, array $validated, ?string $ifMatch, array $heldScopes, string $actorId): QuotationUpdated
    {
        return $this->connection->transaction(function () use ($quotationId, $validated, $ifMatch, $heldScopes, $actorId): QuotationUpdated {
            $before = $this->access->open($quotationId, $ifMatch, $heldScopes, $actorId);

            if ($before->status !== 'draft') {
                throw QuotationWriteRefused::notDraft();
            }

            $priced = $this->pricer->price($validated, $before->customerId, now()->toDateTimeImmutable());

            $this->guardMarginAndTax($before, $validated, $priced, $actorId);

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

            return new QuotationUpdated($this->access->reread($quotationId), $priced->quantityWarnings);
        });
    }

    /**
     * §3.5's `edit margin` and `edit tax`, each asked only when the body
     * moves the thing it guards. Margin: the header's `default_margin`, or any
     * submitted line margin (a line margin overrides the header's, §5.1, so
     * setting one is editing the margin). Tax: the **derived** `tax_percent`
     * against the stored one, so a rate sent for an exempt customer — which
     * `D-63` discards — is not counted as an edit of the tax.
     *
     * @param  array<string, mixed>  $validated
     */
    private function guardMarginAndTax(QuotationDetail $before, array $validated, PricedQuotation $priced, string $actorId): void
    {
        $submittedMargin = $validated['default_margin'] ?? null;
        $marginMoved = ! (is_string($submittedMargin) && is_numeric($submittedMargin) && is_numeric($before->defaultMargin))
            || bccomp($submittedMargin, $before->defaultMargin, 3) !== 0
            || array_filter($priced->items, static fn (array $row): bool => $row['margin_percent'] !== null) !== [];

        if ($marginMoved && ! $this->authorize->decide($actorId, 'quotation', 'edit_margin')->granted) {
            throw AuthorizationRefused::of('quotation', 'edit_margin');
        }

        $newTax = $priced->computed['tax_percent'] ?? null;
        $taxMoved = ($newTax === null) !== ($before->taxPercent === null)
            || (is_string($newTax) && is_numeric($newTax) && is_numeric($before->taxPercent) && bccomp($newTax, $before->taxPercent, 3) !== 0);

        if ($taxMoved && ! $this->authorize->decide($actorId, 'quotation', 'edit_tax')->granted) {
            throw AuthorizationRefused::of('quotation', 'edit_tax');
        }
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
            'default_margin' => $before->defaultMargin,
            'discount_percent' => $before->discountPercent,
            'tax_percent' => $before->taxPercent,
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
            'payment_terms' => $before->paymentTerms,
            'warranty' => $before->warranty,
            'delivery_terms' => $before->deliveryTerms,
            'show_delivery_terms' => $before->showDeliveryTerms,
            'items' => array_map(static fn (QuotationLine $line): array => $line->asRow(), $before->items),
            'additional_items' => array_map(static fn (QuotationAdditionalLine $line): array => [
                'description' => $line->description,
                'amount' => $line->amount,
            ], $before->additionalItems),
        ];
    }
}
