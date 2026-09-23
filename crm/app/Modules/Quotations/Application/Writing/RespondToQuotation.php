<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Deals\Domain\Contracts\DealOutcomeInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationListCriteria;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Status\QuotationStatusTransition;
use App\Modules\Quotations\Domain\Writing\QuotationEtag;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

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
 * `counter` and `rejected` need a reason (§6.3), stored on the answered row
 * only. `accepted` joins in 1.6. The deal's id comes only from the quotation
 * {@see QuotationWriteAccess::open()} authorised, never from the request.
 *
 * {@see SendQuotation}'s shape: the owner kept the skeleton as debt (2026-09-23, b).
 */
final readonly class RespondToQuotation
{
    private const EVENTS = [
        'partial' => 'QUOTATION_PARTIAL',
        'counter' => 'QUOTATION_COUNTERED',
        'rejected' => 'QUOTATION_REJECTED',
    ];

    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private QuotationWriteAccess $access,
        private CreateQuotationVersion $versions,
        private DealOutcomeInterface $deals,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.record_customer_response`
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     */
    public function respond(string $quotationId, string $response, ?string $reason, ?string $ifMatch, array $heldScopes, string $actorId): RecordedResponse
    {
        $event = self::EVENTS[$response] ?? throw new InvalidArgumentException("Unsupported customer response: {$response}.");
        $rejected = $response === 'rejected';

        return $this->connection->transaction(function () use ($quotationId, $response, $reason, $ifMatch, $heldScopes, $actorId, $event, $rejected): RecordedResponse {
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

            $copy = $rejected ? null : $this->versions->copyOf($quotationId, $actorId);

            $after = $this->access->reread($quotationId);

            $this->audit->record(
                AuditEvent::of($event),
                'quotation',
                $quotationId,
                ['status' => $before->status, 'rejection_reason' => $before->rejectionReason],
                ['status' => $after->status, 'rejection_reason' => $after->rejectionReason],
            );

            $dealLost = null;
            if ($rejected && is_string($reason)) {
                unset($statuses[$quotationId]);
                $othersLive = array_intersect($statuses, QuotationListCriteria::BUCKETS['active']);
                $dealLost = $othersLive === [] && $this->deals->quotationRejected($before->dealId, $reason, $actorId);
            }

            return new RecordedResponse($after, $copy, $dealLost);
        });
    }
}
