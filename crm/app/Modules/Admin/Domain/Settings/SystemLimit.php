<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Settings;

/**
 * §13 screen 6 — *"Limits & SLAs: stale-deal threshold · daily report deadline
 * · quotation approval SLA · weekly review window · maximum file size"* — plus
 * `D-75`'s lockout, which already has a row.
 *
 * **Why an enum over the bare key/value table**, for the reason
 * {@see SystemSetting} gives: the table accepts any key and §13 names five. The
 * membership of *this* list is fixed by the document; it is the **values** that
 * are configuration.
 *
 * **`identity.lockout_minutes` is the sixth, and leaving it out would have been
 * the mistake.** It is the only limit the documentation values, it is already
 * in `system_limits`, and `SystemSettingsSeeder` says plainly that *"the first
 * thing that will happen to this row is somebody changing it"*. An endpoint
 * listing §13's five and omitting this one would leave the only live limit in
 * the system uneditable through the screen built to edit limits.
 *
 * **Five of the six are declared and deliberately unvalued.** `D-17` says the
 * stale-deal threshold is *"configurable in settings"* and stops; §11.5 says
 * the daily deadline comes *"from settings"* and stops. Nothing is seeded here,
 * for the reason `SystemSettingsSeeder` records: a default nobody wrote would
 * arrive as configuration and be read as fact.
 *
 * ⚠️ **Two of the key names carry an inference, and it is named rather than
 * hidden.** §13 gives labels, not keys. `weekly_review_window_hours` reads
 * §11.5's *"if not approved within 24 hours"* as the window §13 means, and
 * `max_file_size_mb` reads `D-39`'s *"Maximum file size 10 MB (configurable)"*
 * as the unit. Neither number is seeded, so an inference about the **unit**
 * cannot become an invented **value**; both are owed an owner's confirmation
 * and are listed in `CHECKLIST.md`.
 */
enum SystemLimit: string
{
    /** `D-17` — "the stale deal threshold is configurable in settings". `J-03` reads it daily. */
    case StaleDealDays = 'limits.stale_deal_days';

    /** §11.5 — "Daily: deadline from settings · if missed → Missed". A time of day, not a count. */
    case DailyReportDeadline = 'limits.daily_report_deadline';

    /** §13/6 "quotation approval SLA". §6 gives the states; no document gives the hours. */
    case QuotationApprovalSlaHours = 'limits.quotation_approval_sla_hours';

    /** §13/6 "weekly review window" — §11.5's 24 hours is the rule it governs. */
    case WeeklyReviewWindowHours = 'limits.weekly_review_window_hours';

    /** §13/6 "maximum file size" · `D-39` — "10 MB (configurable)". */
    case MaxFileSizeMb = 'limits.max_file_size_mb';

    /** `D-75` — the one limit with a row, seeded at 30 as an interim. */
    case LockoutMinutes = 'identity.lockout_minutes';

    /**
     * One of the five `system_limits.value_type` accepts (Point 1.1).
     *
     * A deadline is a time of day and the rest are counts. `decimal` appears
     * nowhere: none of §13's limits is a fraction, and `DB-07` is why the day
     * one of them is, it becomes a decimal string rather than a float column.
     */
    public function valueType(): string
    {
        return match ($this) {
            self::DailyReportDeadline => 'string',
            default => 'integer',
        };
    }

    /**
     * The unit the screen renders beside the number.
     *
     * §13 screen 6 mixes days, hours and megabytes on one form, which is the
     * whole reason `system_limits` has a `unit` column and `settings` does not:
     * a threshold in days cannot be shown beside an SLA in hours without saying
     * which is which. A time of day has no unit — the value *is* the reading.
     */
    public function unit(): ?string
    {
        return match ($this) {
            self::StaleDealDays => 'days',
            self::DailyReportDeadline => null,
            self::QuotationApprovalSlaHours, self::WeeklyReviewWindowHours => 'hours',
            self::MaxFileSizeMb => 'megabytes',
            self::LockoutMinutes => 'minutes',
        };
    }

    /**
     * The validation rule beyond "is a string".
     *
     * **A whole number above zero, matched as a pattern rather than by
     * `integer`.** The value travels and is stored as a string (`DB-07`), so
     * what is checked is that it *reads* as a count — and `integer` would
     * accept `0` and `-5`. That matters most on the one limit with a live
     * reader: **a zero-minute lockout never locks**, which is the same defect
     * Point 2.3 chose `is_numeric` over a cast to avoid.
     */
    public function rule(): string
    {
        return match ($this->valueType()) {
            'integer' => 'regex:/^[1-9][0-9]*$/',
            default => 'string',
        };
    }
}
