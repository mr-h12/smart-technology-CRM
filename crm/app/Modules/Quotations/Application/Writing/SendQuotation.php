<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Deals\Domain\Contracts\DealNotReadyToSend;
use App\Modules\Deals\Domain\Contracts\DealOutcomeInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Status\QuotationStatusTransition;
use App\Modules\Quotations\Domain\Writing\QuotationEtag;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * Module 10 · 1.3 — `approved → sent` (`D-90`, `OpenAPI §7.2`), on
 * {@see SubmitQuotation}'s shape, without waiting for a PDF. The deal moves
 * in the same transaction; its id comes only from the quotation
 * {@see QuotationWriteAccess::open()} just authorised, never from the request.
 */
final readonly class SendQuotation
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private QuotationWriteAccess $access,
        private DealOutcomeInterface $deals,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.send_to_customer`
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     * @throws DealNotReadyToSend
     */
    public function send(string $quotationId, ?string $ifMatch, array $heldScopes, string $actorId): QuotationDetail
    {
        return $this->connection->transaction(function () use ($quotationId, $ifMatch, $heldScopes, $actorId): QuotationDetail {
            $before = $this->access->open($quotationId, $ifMatch, $heldScopes, $actorId);

            if (! QuotationStatusTransition::isAllowed($before->status, 'sent')) {
                throw QuotationWriteRefused::invalidTransition($before->status, 'sent');
            }

            if (! $this->quotations->moveStatus($quotationId, 'sent', $before->versionToken, $actorId, ['sent_at' => now()])) {
                throw QuotationWriteRefused::staleVersion(QuotationEtag::of($before));
            }

            $this->deals->quotationSent($before->dealId, $actorId);

            $after = $this->access->reread($quotationId);

            $this->audit->record(
                AuditEvent::of('QUOTATION_SENT'),
                'quotation',
                $quotationId,
                ['status' => $before->status, 'sent_at' => $before->sentAt],
                ['status' => $after->status, 'sent_at' => $after->sentAt],
            );

            return $after;
        });
    }
}
