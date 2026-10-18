<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Identity\Domain\Rbac\Role;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Reports\Domain\Report;

/**
 * Who a report may go to (`D-127`) and who it goes to by default (`D-129`).
 *
 * - A candidate is an active user holding §3.10's `report.view_received` with
 *   a scope that covers the author: `All` covers everyone, `Out` Outdoor Sales
 *   authors, `Team` the holder's own team (`D-138`); the author is never one.
 * - The defaults are the Manager the `reports.default_recipient` setting names,
 *   when that user is still an active Manager — never on a Manager's report —
 *   and the author's own Team Leader, the holder `Team` covers them through.
 */
final readonly class RecipientCandidates
{
    public function __construct(
        private UserFactsInterface $users,
        private SettingsRepositoryInterface $settings,
    ) {}

    /** @return list<array{id: string, name: string, is_default: bool}> */
    public function for(Report $report): array
    {
        $authorRole = $this->users->activeRolesOf([$report->authorId])[$report->authorId] ?? null;
        $default = $authorRole === Role::Manager->value ? null : $this->defaultRecipient();

        $candidates = [];
        foreach ($this->users->holdersOf('report', 'view_received') as $id => $holder) {
            $covers = in_array(Scope::All->value, $holder['scopes'], true)
                || (in_array(Scope::Out->value, $holder['scopes'], true) && $authorRole === Role::OutdoorSales->value);
            // ponytail: one team query per `Team` holder; a `teamLeaderOf`
            // lookup if Team Leaders grow many.
            $leads = in_array(Scope::Team->value, $holder['scopes'], true)
                && in_array($report->authorId, $this->users->teamMemberIdsOf($id), true);

            if ($id !== $report->authorId && ($covers || $leads)) {
                $candidates[] = ['id' => $id, 'name' => $holder['name'], 'is_default' => $leads || $id === $default];
            }
        }

        return $candidates;
    }

    /** @return list<string> */
    public function idsFor(Report $report): array
    {
        return array_column($this->for($report), 'id');
    }

    /**
     * `D-136`, J-06: every default recipient that is a candidate for this report.
     *
     * @return list<string>
     */
    public function defaultsFor(Report $report): array
    {
        return array_column(array_filter($this->for($report), static fn (array $candidate): bool => $candidate['is_default']), 'id');
    }

    private function defaultRecipient(): ?string
    {
        $named = $this->settings->all()[SystemSetting::ReportsDefaultRecipient->value] ?? null;

        return is_string($named) && ($this->users->activeRolesOf([$named])[$named] ?? null) === Role::Manager->value
            ? $named
            : null;
    }
}
