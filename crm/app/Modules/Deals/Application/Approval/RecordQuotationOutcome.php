<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\Approval;

use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Access\DealStatusTransition;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\Contracts\DealNotReadyToSend;
use App\Modules\Deals\Domain\Contracts\DealOutcomeInterface;
use App\Modules\Deals\Domain\Listing\DealNotFound;

/**
 * `D-90`'s two deal moves, through {@see ChangeDealStatus} so the audit row
 * and the customer-status recompute (§4.5) happen exactly as for a person's
 * move. Unrestricted scope: the caller already authorised the quotation
 * action, and §3.5's send / record-response grants are a subset of §3.4's
 * `change_status`.
 */
final readonly class RecordQuotationOutcome implements DealOutcomeInterface
{
    /** §4.4's chain before `supplier_quotation`, listed rather than walked back from `DealStatusTransition`'s edges: rule a names these four. */
    private const NOT_READY = ['lead', 'contacted', 'waiting_customer_request', 'supplier_rfq'];

    private const ALL = ['all'];

    public function __construct(
        private DealDirectoryInterface $deals,
        private ChangeDealStatus $changeStatus,
    ) {}

    public function quotationSent(string $dealId, string $actorId): void
    {
        $status = $this->statusOf($dealId, $actorId);

        if (in_array($status, self::NOT_READY, true)) {
            throw DealNotReadyToSend::of($dealId, $status);
        }

        if ($status === 'supplier_quotation') {
            $this->changeStatus->handle($dealId, 'quotation_sent', null, self::ALL, $actorId);
        }
    }

    public function quotationRejected(string $dealId, string $reason, string $actorId): bool
    {
        if (! DealStatusTransition::isAllowed($this->statusOf($dealId, $actorId), 'lost')) {
            return false;
        }

        $this->changeStatus->handle($dealId, 'lost', $reason, self::ALL, $actorId);

        return true;
    }

    private function statusOf(string $dealId, string $actorId): string
    {
        return ($this->deals->find($dealId, DealRowScope::resolve(self::ALL, $actorId)) ?? throw DealNotFound::of($dealId))->status;
    }
}
