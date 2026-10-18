<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain\Contracts;

/**
 * Module 13 · 7.5d — what the sidebar's *Reports* counter (Design System §5.1)
 * reads of the reports: how many daily reports J-04 left Missed.
 */
interface MissedReportsInterface
{
    /**
     * Daily reports in status `missed` by these authors, archived ones excluded.
     *
     * @param  list<string>  $authorIds
     */
    public function missedDailyCountOf(array $authorIds): int;
}
