<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Contracts;

use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\CustomerStatus\DealActivitySnapshot;
use App\Modules\Deals\Domain\Listing\DealListCriteria;
use App\Modules\Deals\Domain\Listing\DealPage;
use App\Modules\Deals\Domain\Listing\DealSummary;
use App\Modules\Deals\Domain\Writing\DealDraft;

/**
 * The `deals` table as §10's screens need to read it — `CustomerDirectoryInterface`'s
 * shape (Module 3 Point 3.1), on the same reasoning.
 *
 * Both methods take a {@see DealRowScope}, and neither has an overload that
 * omits it. `SEC-08` is row-level security, and a reader callable without a
 * scope is a reader somebody will one day call without one — which returns
 * every deal in the company to whoever asked.
 *
 * `find()` answers null for a row outside the scope, exactly as it does for a
 * row that is not there. `OpenAPI §5.1` requires that: 404 covers "does not
 * exist **or** is not visible to the caller. Do not reveal which case applies."
 */
interface DealDirectoryInterface
{
    public function list(DealListCriteria $criteria, DealRowScope $scope): DealPage;

    /** Null when the row is absent **or** outside the scope — the caller cannot tell, by design. */
    public function find(string $dealId, DealRowScope $scope): ?DealSummary;

    /**
     * Point 2.3.
     *
     * No scope parameter, on `CustomerDirectoryInterface::create()`'s precedent:
     * a row that does not exist yet cannot be selected by a `WHERE`. §3.4's
     * create scope constrains the **owner** the deal may be filed under, and
     * that is decided in the use case before this is called.
     *
     * Allocates the `DL-YYYY-NNNN` code internally (§4.7, via
     * `document_sequences`) — the caller supplies a draft, not a code, so it
     * cannot collide with another writer's allocation.
     *
     * `$approvalStatus` is not part of `$draft`: `DealDraft` is what a caller
     * may write, and approval status is derived from *who* is creating the
     * deal (Flow 1 vs Flow 3), never typed by them. `SaveDeal` decides it and
     * hands it across this one explicit seam rather than through the set of
     * writable keys.
     */
    public function create(DealDraft $draft, string $actorId, ?string $approvalStatus): DealSummary;

    /** Null on the same two indistinguishable cases as {@see find()} — absent, or out of reach. */
    public function update(string $dealId, DealDraft $draft, DealRowScope $scope, string $actorId): ?DealSummary;

    /**
     * Point 2.5 — Flow 3's decision, recorded directly rather than through
     * `DealDraft`: `approval_status` and `rejection_reason` are never
     * caller-writable fields, only ever set by a use case that has already
     * decided the value (`ReviewDealApproval` checks the current state
     * before this is called).
     *
     * Null on the same two indistinguishable cases as {@see find()}.
     */
    public function reviewApproval(
        string $dealId,
        string $newApprovalStatus,
        ?string $rejectionReason,
        DealRowScope $scope,
        string $actorId,
    ): ?DealSummary;

    /**
     * Point 2.6 — §4.4's transition, recorded directly rather than through
     * `DealDraft`, on {@see reviewApproval()}'s precedent: `status` is never
     * a caller-writable field, only ever set by a use case
     * (`ChangeDealStatus`) that has already validated the edge against
     * `DealStatusTransition`.
     *
     * `$lostReason` is null on every transition except into `lost`, where
     * the boundary already refused a blank one before this is called.
     *
     * Null on the same two indistinguishable cases as {@see find()}.
     */
    public function changeStatus(
        string $dealId,
        string $newStatus,
        ?string $lostReason,
        DealRowScope $scope,
        string $actorId,
    ): ?DealSummary;

    /**
     * Point 3.1 — §4.5's inputs, every deal belonging to one customer.
     *
     * No scope parameter, unlike every read above: `RecomputeCustomerStatus`
     * runs as a system-level consequence of a write that already passed its
     * own authorisation, not on behalf of a caller whose row-level reach
     * needs narrowing. §4.5 rule 1 also requires *every* deal the customer
     * has, regardless of who owns it — a scoped read would silently derive
     * the wrong status for a customer whose deals are split across owners.
     *
     * @return list<DealActivitySnapshot>
     */
    public function activityForCustomer(string $customerId): array;
}
