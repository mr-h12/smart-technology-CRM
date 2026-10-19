<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Settings;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use DateTimeZone;
use Illuminate\Config\Repository as ConfigRepository;

/**
 * The company's calendar day, not UTC's: `locale.timezone` (§13 screen 4), and
 * `app.timezone` when it is unset or not a zone PHP knows (owner, 2026-09-23,
 * Q-A). Read by `J-01`'s expiry, by the PO date's limit (`D-108`), and by
 * Module 13's daily report for the day it covers; and the go-live date that
 * starts Module 13's calendar (J-04, J-05, J-07). Admin's, because the settings are.
 */
final readonly class CompanyToday
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private ConfigRepository $config,
    ) {}

    /** `Y-m-d` */
    public function date(): string
    {
        return now($this->zone())->toDateString();
    }

    /** `D-27`/`D-127`: the go-live date (`Y-m-d`), null while it is empty — J-04…J-07 then create and mark nothing. */
    public function goLiveDate(): ?string
    {
        $stored = $this->settings->all()[SystemSetting::ReportsGoLiveDate->value] ?? null;

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /** The company's zone: the stored `locale.timezone`, else `app.timezone`. */
    public function zone(): string
    {
        $stored = $this->settings->all()[SystemSetting::Timezone->value] ?? null;

        return is_string($stored) && in_array($stored, DateTimeZone::listIdentifiers(), true)
            ? $stored
            : $this->config->string('app.timezone');
    }
}
