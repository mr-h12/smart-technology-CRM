<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;
use App\Modules\Quotations\Domain\Status\QuotationStatusTransition;
use App\Modules\Quotations\Domain\Writing\QuotationEtag;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Module 10 · 1.4 — `sent → partial|counter` (`OpenAPI §7.2`) and the new
 * `draft` version in the same transaction (§6.3, `D-08`, Q7), through
 * {@see CreateQuotationVersion::copyOf()} — the copy `new-version` makes.
 * `counter` needs a reason (§6.3), stored on the answered row only. The deal
 * does not move (Q2). `accepted` / `rejected` join in 1.6 / 1.5.
 *
 * {@see SendQuotation}'s shape: the owner kept the skeleton as debt (2026-09-23, b).
 */
final readonly class RespondToQuotation
{
    private const EVENTS = ['partial' => 'QUOTATION_PARTIAL', 'counter' => 'QUOTATION_COUNTERED'];

    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private QuotationWriteAccess $access,
        private CreateQuotationVersion $versions,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.record_customer_response`
     * @return array{QuotationDetail, QuotationSummary} the answered quotation and its new draft
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     */
    public function respond(string $quotationId, string $response, ?string $reason, ?string $ifMatch, array $heldScopes, string $actorId): array
    {
        $event = self::EVENTS[$response] ?? throw new InvalidArgumentException("Unsupported customer response: {$response}.");

        return $this->connection->transaction(function () use ($quotationId, $response, $reason, $ifMatch, $heldScopes, $actorId, $event): array {
            $before = $this->access->open($quotationId, $ifMatch, $heldScopes, $actorId);

            if ($response === 'counter' && ($reason === null || preg_match('/\S/u', $reason) !== 1)) {
                throw QuotationWriteRefused::reasonRequired();
            }

            if (! QuotationStatusTransition::isAllowed($before->status, $response)) {
                throw QuotationWriteRefused::invalidTransition($before->status, $response);
            }

            $columns = $response === 'counter' ? ['rejection_reason' => $reason] : [];

            if (! $this->quotations->moveStatus($quotationId, $response, $before->versionToken, $actorId, $columns)) {
                throw QuotationWriteRefused::staleVersion(QuotationEtag::of($before));
            }

            $copy = $this->versions->copyOf($quotationId, $actorId);

            $after = $this->access->reread($quotationId);

            $this->audit->record(
                AuditEvent::of($event),
                'quotation',
                $quotationId,
                ['status' => $before->status, 'rejection_reason' => $before->rejectionReason],
                ['status' => $after->status, 'rejection_reason' => $after->rejectionReason],
            );

            return [$after, $copy];
        });
    }
}
