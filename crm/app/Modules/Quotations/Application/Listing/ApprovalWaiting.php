<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Listing;

use App\Support\Settings\SettingReader;
use DateTimeImmutable;

/**
 * `days_waiting` and `sla_exceeded` — Module 8 Point 2.1. `D-11`: no
 * automatic escalation; the quotation waits, and the screen shows "a red
 * badge plus a 'days waiting' column". Both are computed **here**, on the
 * server, from `submitted_at` against `limits.quotation_approval_sla_hours`
 * (§13 screen 6), so the SPA displays and never decides.
 *
 * Null unless the row is `pending` — a draft, a returned draft (1.2 clears
 * `submitted_at`) and an approved quotation (1.1 keeps it) are not waiting.
 * `sla_exceeded` is also null while the limit is unconfigured: `SystemLimit`
 * leaves it unseeded, and {@see SettingReader::nullableInteger()} says the
 * caller does not guess — the badge simply has nothing to say. Calendar days,
 * floored; the SLA is compared in hours, as the setting is stored.
 *
 * The key is a local constant, not an import — `RecomputeCustomerStatus`
 * gives the reason: `App\Support\Settings` exists so Admin implements it and
 * another module consumes it without learning Admin's name.
 */
final readonly class ApprovalWaiting
{
    /** `SystemLimit::QuotationApprovalSlaHours->value`. */
    private const SLA_HOURS_KEY = 'limits.quotation_approval_sla_hours';

    public function __construct(private SettingReader $settings) {}

    /** @return array{days_waiting: ?int, sla_exceeded: ?bool} */
    public function of(string $status, ?string $submittedAt): array
    {
        if ($status !== 'pending' || $submittedAt === null) {
            return ['days_waiting' => null, 'sla_exceeded' => null];
        }

        $waitedSeconds = now()->getTimestamp() - (new DateTimeImmutable($submittedAt))->getTimestamp();
        $slaHours = $this->settings->nullableInteger(self::SLA_HOURS_KEY);

        return [
            'days_waiting' => intdiv($waitedSeconds, 86400),
            'sla_exceeded' => $slaHours === null ? null : $waitedSeconds > $slaHours * 3600,
        ];
    }
}
