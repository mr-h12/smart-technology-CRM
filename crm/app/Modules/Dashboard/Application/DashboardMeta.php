<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Dashboard\Domain\DashboardAccess;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\Scope;

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
    ) {}

    /** @return array{scope: string, tabs: list<string>, drill_down: bool, money: bool, today: string, last_updated_at: null} */
    public function for(string $actorId): array
    {
        $access = DashboardAccess::of(
            viewsAll: $this->authorize->decide($actorId, 'dashboard', 'view')->allows(Scope::All),
            drillDown: $this->authorize->decide($actorId, 'dashboard', 'drill_down')->granted,
            costAndMargin: $this->authorize->decide($actorId, 'quotation', 'view_cost_and_margin')->granted,
        );

        return [
            'scope' => $access->scope(),
            'tabs' => $access->tabs(),
            'drill_down' => $access->drillDown,
            'money' => $access->money,
            'today' => $this->today->date(),
            // ponytail: J-09 does not exist until slice 2 (2.2), so nothing has refreshed yet.
            'last_updated_at' => null,
        ];
    }
}
