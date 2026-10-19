<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * `J-07` (§15; `D-140`): one RPT-MN draft per calendar month that has ended in
 * company time, from the go-live date — a partial first month starts on it, and
 * while it is empty nothing is created (`D-127`). The author is the Manager
 * `reports.default_recipient` names; while that is nobody, or not an active
 * Manager, nothing is created and the next run tries again. Idempotent (§15.1):
 * a month that already has its RPT-MN is skipped, so the startup catch-up fills
 * every missed month once (`D-55`). The audit carries no actor, as `J-05`.
 * ponytail: the check-then-insert is not locked; two runs at the same instant
 * (the catch-up beside the schedule) could draft a month twice. A unique index on
 * (type, period_start) for monthly reports if that ever happens.
 */
final readonly class GenerateMonthlyReports
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private CompanyToday $today,
        private RecipientCandidates $candidates,
        private CreateReport $create,
        private ConnectionInterface $connection,
    ) {}

    /** @return int how many drafts were created */
    public function run(): int
    {
        $goLive = $this->today->goLiveDate();
        $author = $this->candidates->defaultRecipient();

        if ($goLive === null || $author === null) {
            return 0;
        }

        $created = 0;

        foreach (self::monthsSince($goLive, $this->today->date()) as [$start, $end]) {
            if ($this->reports->authorsOf('monthly', $start) === []) {
                $this->connection->transaction(fn (): array => $this->create->draft('monthly', $author, $start, $end));
                $created++;
            }
        }

        return $created;
    }

    /**
     * Every calendar month that has ended by `$today`, from the one `$goLive` falls in,
     * oldest first; the first starts on `$goLive`.
     *
     * @return list<array{0: string, 1: string}> [first day, last day], `Y-m-d`
     */
    private static function monthsSince(string $goLive, string $today): array
    {
        $current = (new DateTimeImmutable($today))->modify('first day of this month');
        $months = [];

        for ($first = (new DateTimeImmutable($goLive))->modify('first day of this month'); $first < $current; $first = $first->modify('+1 month')) {
            $months[] = [max($first->format('Y-m-d'), $goLive), $first->modify('last day of this month')->format('Y-m-d')];
        }

        return $months;
    }
}
