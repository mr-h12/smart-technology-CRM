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
 *   a scope that covers the author: `All` covers everyone, `Out` the outdoor
 *   staff. `Team` covers nobody until teams exist; the author is never one.
 * - The default is the Manager the `reports.default_recipient` setting names,
 *   when that user is still an active Manager — never on a Manager's report.
 */
final readonly class RecipientCandidates
{
    private const OUTDOOR = [Role::OutdoorSales->value, Role::OutdoorSupervisor->value];

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
                || (in_array(Scope::Out->value, $holder['scopes'], true) && in_array($authorRole, self::OUTDOOR, true));

            if ($id !== $report->authorId && $covers) {
                $candidates[] = ['id' => $id, 'name' => $holder['name'], 'is_default' => $id === $default];
            }
        }

        return $candidates;
    }

    /** @return list<string> */
    public function idsFor(Report $report): array
    {
        return array_column($this->for($report), 'id');
    }

    /** `D-136`, J-06: the default recipient when that user is a candidate for this report, else null. */
    public function defaultFor(Report $report): ?string
    {
        foreach ($this->for($report) as $candidate) {
            if ($candidate['is_default']) {
                return $candidate['id'];
            }
        }

        return null;
    }

    private function defaultRecipient(): ?string
    {
        $named = $this->settings->all()[SystemSetting::ReportsDefaultRecipient->value] ?? null;

        return is_string($named) && ($this->users->activeRolesOf([$named])[$named] ?? null) === Role::Manager->value
            ? $named
            : null;
    }
}
