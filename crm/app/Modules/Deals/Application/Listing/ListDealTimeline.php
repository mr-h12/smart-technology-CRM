<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\Listing;

use App\Modules\Audit\Domain\Contracts\AuditEntryReaderInterface;
use App\Modules\Audit\Domain\Reading\AuditRecordPage;
use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\Listing\DealNotFound;
use App\Modules\Deals\Domain\Listing\DealSummary;
use App\Modules\Deals\Domain\Listing\DealTimelineCriteria;

/**
 * §4.4's deal timeline — "every status change is written to the deal timeline:
 * old status · new status · who · when" — read back out of `audit_log`.
 *
 * ── The row is checked before the history is read, never after ─────────────
 *
 * `AuditEntryReaderInterface` performs no authorization by design: it answers
 * with the rows of whatever entity it is handed. So the reach is settled here,
 * first, by finding the deal under the caller's scope — and a caller who cannot
 * see the deal gets `DealNotFound` before the reader is ever asked. Reading the
 * history and filtering afterwards would be the same defect in a different
 * order: the count alone tells a caller how busy a deal they may not see has
 * been.
 *
 * ── The scope comes from `deal.view_timeline`, not from `deal.view` ────────
 *
 * §3.4 gives them separate rows, and the controller hands over the scopes the
 * middleware resolved for **this** route's permission. They happen to hold the
 * same seven grants today; a matrix where they diverge would find this code
 * already correct rather than quietly reusing the wrong column.
 */
final readonly class ListDealTimeline
{
    /**
     * The `entity_type` every `DEAL_*` audit row is written under — see
     * `SaveDeal`, `ChangeDealStatus`, `ReviewDealApproval`, `AssignDeal` and
     * `AttachDealDocument`, all of which pass this same literal.
     */
    private const ENTITY_TYPE = 'deal';

    public function __construct(
        private DealDirectoryInterface $deals,
        private AuditEntryReaderInterface $audit,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     *
     * @throws DealNotFound when the row is absent **or** outside the caller's reach
     */
    public function handle(
        string $dealId,
        DealTimelineCriteria $criteria,
        array $heldScopes,
        string $actorId,
    ): AuditRecordPage {
        $deal = $this->deals->find($dealId, DealRowScope::resolve($heldScopes, $actorId));

        if (! $deal instanceof DealSummary) {
            // §5.1: 404 for "does not exist **or** is not visible to the
            // caller. Do not reveal which case applies."
            throw DealNotFound::of($dealId);
        }

        return $this->audit->forEntity(self::ENTITY_TYPE, $dealId, $criteria->page, $criteria->perPage);
    }
}
