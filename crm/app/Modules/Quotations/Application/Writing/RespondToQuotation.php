<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Deals\Domain\Contracts\DealOutcomeInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationListCriteria;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Status\QuotationStatusTransition;
use App\Modules\Quotations\Domain\Writing\QuotationEtag;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemQuantityInterface;
use App\Support\Database\Precision;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Module 10 · 1.4 — `sent → partial|counter` (`OpenAPI §7.2`) and the new
 * `draft` version in the same transaction (§6.3, `D-08`, Q7), through
 * {@see CreateQuotationVersion::copyOf()} — the copy `new-version` makes.
 * The deal does not move (Q2).
 *
 * Module 10 · 1.5 — `sent|expired → rejected` (Q8), no copy. The deal goes
 * `lost` with the reason (Q2) only when none of its quotations is live
 * afterwards (Q12), and not at all when it has no `lost` edge (`D-90` rule
 * b); {@see RecordedResponse::$dealLost} says which. The deal's quotations
 * are locked before the write, so two last rejections serialise.
 *
 * Module 10 · 1.6 — `sent → accepted`: every line drawn from its supplier
 * line once, keyed by the line's id (`D-81`, F-05 · 1.5), and the purchase
 * order written (§4.6, Q5), all in the one transaction. The deal does not
 * move (Q2).
 *
 * `counter` and `rejected` need a reason (§6.3), stored on the answered row
 * only. The deal's id comes only from the quotation
 * {@see QuotationWriteAccess::open()} authorised, never from the request.
 *
 * {@see SendQuotation}'s shape: the owner kept the skeleton as debt (2026-09-23, b).
 */
final readonly class RespondToQuotation
{
    private const EVENTS = [
        'accepted' => 'QUOTATION_ACCEPTED',
        'partial' => 'QUOTATION_PARTIAL',
        'counter' => 'QUOTATION_COUNTERED',
        'rejected' => 'QUOTATION_REJECTED',
    ];

    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private QuotationWriteAccess $access,
        private CreateQuotationVersion $versions,
        private DealOutcomeInterface $deals,
        private SupplierItemQuantityInterface $supplierQuantities,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.record_customer_response`
     * @param  ?string  $customerPoReference  `accepted` only, with `$poDate` (the boundary requires both)
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     */
    public function respond(
        string $quotationId,
        string $response,
        ?string $reason,
        ?string $customerPoReference,
        ?string $poDate,
        ?string $ifMatch,
        array $heldScopes,
        string $actorId,
    ): RecordedResponse {
        $event = self::EVENTS[$response] ?? throw new InvalidArgumentException("Unsupported customer response: {$response}.");
        $rejected = $response === 'rejected';
        $accepted = $response === 'accepted';

        if ($accepted && ($customerPoReference === null || $poDate === null)) {
            throw new InvalidArgumentException('An acceptance needs the customer\'s reference and date.');
        }

        return $this->connection->transaction(function () use ($quotationId, $response, $reason, $customerPoReference, $poDate, $ifMatch, $heldScopes, $actorId, $event, $rejected, $accepted): RecordedResponse {
            $before = $this->access->open($quotationId, $ifMatch, $heldScopes, $actorId);

            $needsReason = $response === 'counter' || $rejected;
            if ($needsReason && ($reason === null || preg_match('/\S/u', $reason) !== 1)) {
                throw QuotationWriteRefused::reasonRequired();
            }

            if (! QuotationStatusTransition::isAllowed($before->status, $response)) {
                throw QuotationWriteRefused::invalidTransition($before->status, $response);
            }

            $statuses = $rejected ? $this->quotations->lockStatusesOfDeal($before->dealId) : [];

            if (! $this->quotations->moveStatus($quotationId, $response, $before->versionToken, $actorId, $needsReason ? ['rejection_reason' => $reason] : [])) {
                throw QuotationWriteRefused::staleVersion(QuotationEtag::of($before));
            }

            $copy = $accepted || $rejected ? null : $this->versions->copyOf($quotationId, $actorId);

            $after = $this->access->reread($quotationId);

            $old = ['status' => $before->status, 'rejection_reason' => $before->rejectionReason];
            $new = ['status' => $after->status, 'rejection_reason' => $after->rejectionReason];

            if ($accepted) {
                [$old['consumed_quantity'], $new['consumed_quantity']] = $this->consumeLines($before);
            }

            $this->audit->record(AuditEvent::of($event), 'quotation', $quotationId, $old, $new);

            $order = null;
            if ($accepted) {
                $order = $this->quotations->createPurchaseOrder($quotationId, $customerPoReference, $poDate, $actorId);

                $this->audit->record(AuditEvent::of('PURCHASE_ORDER_CREATED'), 'purchase_order', $order->id, null, [
                    'quotation_id' => $order->quotationId,
                    'po_number' => $order->poNumber,
                    'customer_po_reference' => $order->customerPoReference,
                    'po_date' => $order->poDate,
                ]);
            }

            $dealLost = null;
            if ($rejected && is_string($reason)) {
                unset($statuses[$quotationId]);
                $othersLive = array_intersect($statuses, QuotationListCriteria::BUCKETS['active']);
                $dealLost = $othersLive === [] && $this->deals->quotationRejected($before->dealId, $reason, $actorId);
            }

            return new RecordedResponse($after, $copy, $dealLost, $order);
        });
    }

    /**
     * `D-81`: one `consume()` per line, keyed by the line's id. The balance
     * before is the one after minus the line's quantity — exact, because an
     * accepted quotation is never accepted again (`If-Match` refuses the
     * replay before this runs), so no call here is an idempotent no-op.
     *
     * @return array{array<string, string>, array<string, string>} before and after, by quotation line id
     */
    private function consumeLines(QuotationDetail $quotation): array
    {
        $before = [];
        $after = [];

        foreach ($quotation->items as $line) {
            $quantity = $line->quantity;
            $balance = $this->supplierQuantities->consume($line->supplierQuotationItemId, $quantity, $line->id);

            if (! is_numeric($balance) || ! is_numeric($quantity)) {
                throw new RuntimeException("Line {$line->id}: a non-numeric quantity or balance reached the consumption.");
            }

            $after[$line->id] = $balance;
            $before[$line->id] = bcsub($balance, $quantity, Precision::QUANTITY_SCALE);
        }

        return [$before, $after];
    }
}
