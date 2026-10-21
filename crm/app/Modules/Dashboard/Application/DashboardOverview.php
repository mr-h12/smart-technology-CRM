<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Application;

use App\Modules\Dashboard\Domain\Contracts\DashboardAggregatesInterface;
use App\Modules\Dashboard\Domain\DealProgress;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Support\Database\UtcAtom;

/**
 * `GET /dashboard/overview` (`D-144`, `D-145`): the Overview's counts for the
 * days `[$from, $to]`, read only from J-09's tables. No money: slice 3.
 *
 * Scope (`D-138`): `dashboard.view` at All counts every row, unowned ones
 * included; at Team, only the rows the leader or a member owns (or self-approved).
 */
final readonly class DashboardOverview
{
    public function __construct(
        private AuthorizeAction $authorize,
        private UserFactsInterface $users,
        private DashboardAggregatesInterface $aggregates,
    ) {}

    /**
     * @return array{customers: int, deals: array{total: int, open: int, closed: int}, self_approvals: int, now: array{pending_approvals: int, returned: int}, last_updated_at: ?string}
     */
    public function for(string $actorId, string $from, string $to): array
    {
        $sums = $this->aggregates->overviewBetween($from, $to, $this->ownerIds($actorId));
        $finished = $this->aggregates->lastFinishedAt();

        return [
            'customers' => $sums['customers'],
            'deals' => DealProgress::split($sums['deals_by_status']),
            'self_approvals' => $sums['self_approvals'],
            'now' => ['pending_approvals' => $sums['pending_approvals'], 'returned' => $sums['returned']],
            'last_updated_at' => $finished === null ? null : UtcAtom::of($finished),
        ];
    }

    /**
     * Null at All; the team's ids at Team.
     *
     * @return list<string>|null
     */
    public function ownerIds(string $actorId): ?array
    {
        return $this->authorize->decide($actorId, 'dashboard', 'view')->allows(Scope::All)
            ? null
            : $this->users->teamMemberIdsOf($actorId);
    }
}
