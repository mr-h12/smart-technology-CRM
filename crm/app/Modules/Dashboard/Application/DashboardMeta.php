<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Dashboard\Domain\Contracts\DashboardAggregatesInterface;
use App\Modules\Dashboard\Domain\DashboardAccess;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Support\Database\UtcAtom;

/**
 * `GET /dashboard/meta` — what the caller's dashboard carries (`D-145`) and the
 * company's day (`CompanyToday`), from which the screen's period is drawn: the
 * browser does not know `locale.timezone`.
 */
final readonly class DashboardMeta
{
    public function __construct(
        private AuthorizeAction $authorize,
        private CompanyToday $today,
        private DashboardAggregatesInterface $aggregates,
    ) {}

    /** @return array{scope: string, tabs: list<string>, drill_down: bool, money: bool, today: string, last_updated_at: ?string} */
    public function for(string $actorId): array
    {
        $access = DashboardAccess::of(
            viewsAll: $this->authorize->decide($actorId, 'dashboard', 'view')->allows(Scope::All),
            drillDown: $this->authorize->decide($actorId, 'dashboard', 'drill_down')->granted,
            costAndMargin: $this->authorize->decide($actorId, 'quotation', 'view_cost_and_margin')->granted,
        );
        $finished = $this->aggregates->lastFinishedAt();

        return [
            'scope' => $access->scope(),
            'tabs' => $access->tabs(),
            'drill_down' => $access->drillDown,
            'money' => $access->money,
            'today' => $this->today->date(),
            // `D-145` "Last updated": J-09's newest finished run.
            'last_updated_at' => $finished === null ? null : UtcAtom::of($finished),
        ];
    }
}
