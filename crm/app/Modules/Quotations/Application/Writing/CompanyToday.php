<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use DateTimeZone;
use Illuminate\Config\Repository as ConfigRepository;

/**
 * The company's calendar day, not UTC's: `locale.timezone` (§13 screen 4), and
 * `app.timezone` when it is unset or not a zone PHP knows (owner, 2026-09-23,
 * Q-A). Read by `J-01`'s expiry and by the PO date's limit (`D-108`).
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
        $stored = $this->settings->all()[SystemSetting::Timezone->value] ?? null;
        $zone = is_string($stored) && in_array($stored, DateTimeZone::listIdentifiers(), true)
            ? $stored
            : $this->config->string('app.timezone');

        return now($zone)->toDateString();
    }
}
