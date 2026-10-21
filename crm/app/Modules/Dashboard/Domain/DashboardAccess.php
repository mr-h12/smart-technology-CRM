<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Domain;

/**
 * Which tabs and figures a caller's dashboard carries (`D-145`), from what the
 * caller holds — never from a role name (CLAUDE.md: the matrix lives in the
 * database). The owner's reading of `D-145`, 2026-10-21:
 *
 * | tab | needs |
 * |---|---|
 * | overview · sales · outdoor | `dashboard.view` |
 * | finance, and money anywhere | `quotation.view_cost_and_margin` and `dashboard.view` at All |
 * | procurement | `dashboard.view` at All and `dashboard.drill_down` |
 *
 * "Money only at All" keeps the Team Leader's dashboard to counts (§12.3)
 * although §3.5 grants them `view_cost_and_margin`.
 */
final readonly class DashboardAccess
{
    private function __construct(
        public bool $viewsAll,
        public bool $drillDown,
        public bool $money,
    ) {}

    public static function of(bool $viewsAll, bool $drillDown, bool $costAndMargin): self
    {
        return new self($viewsAll, $drillDown, $viewsAll && $costAndMargin);
    }

    public function scope(): string
    {
        return $this->viewsAll ? 'all' : 'team';
    }

    /** @return list<string> in the order the screen draws them */
    public function tabs(): array
    {
        return array_values(array_filter([
            'overview',
            'sales',
            $this->money ? 'finance' : null,
            $this->viewsAll && $this->drillDown ? 'procurement' : null,
            'outdoor',
        ]));
    }
}
