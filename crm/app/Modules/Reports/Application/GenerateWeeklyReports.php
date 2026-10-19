<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * `J-05` (§15; `D-136`): from the Sunday after each Sunday → Thursday week
 * (`D-125`), one RPT-WK draft per active user holding `report.create` (the
 * hidden Super Admin is never listed by `holdersOf`). A week that ends before
 * the go-live date gets none, and while the go-live date is empty nothing is
 * created (`D-127`). Nor does a user get a week that ended before the day their
 * account was created, in company time (`D-137`). Idempotent (§15.1): an author who already has the week is
 * skipped, so the startup catch-up fills every missed week once (`D-55`). The
 * audit carries no actor — a queued job has no request, as `J-04` (`D-135`).
 * ponytail: the check-then-insert is not locked; two runs at the same instant
 * (the catch-up beside the schedule) could draft a week twice. A unique index on
 * (author_id, period_start) for weekly reports if that ever happens.
 */
final readonly class GenerateWeeklyReports
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private CompanyToday $today,
        private UserFactsInterface $users,
        private CreateReport $create,
        private ConnectionInterface $connection,
    ) {}

    /** @return int how many drafts were created */
    public function run(): int
    {
        $goLive = $this->today->goLiveDate();

        if ($goLive === null) {
            return 0;
        }

        $holders = $this->users->holdersOf('report', 'create');
        $authors = array_keys($holders);
        $zone = new DateTimeZone($this->today->zone());
        $created = 0;

        foreach (self::weeksSince($goLive, $this->today->date()) as [$start, $end]) {
            $has = $this->reports->authorsOf('weekly', $start);

            foreach (array_diff($authors, $has) as $authorId) {
                // ponytail: a reactivated user, or one newly granted `report.create`, still gets every week
                // since the account's creation — the grant date is not recorded (`D-137`).
                $joined = (new DateTimeImmutable($holders[$authorId]['created_at'], new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');

                if ($end < $joined) {
                    continue;
                }

                $this->connection->transaction(fn (): array => $this->create->draft('weekly', $authorId, $start, $end));
                $created++;
            }
        }

        return $created;
    }

    /**
     * Every Sunday → Thursday week that has ended by `$today` (the Sunday after
     * it has come) and does not end before `$goLive`, oldest first.
     *
     * @return list<array{0: string, 1: string}> [Sunday, Thursday], `Y-m-d`
     */
    private static function weeksSince(string $goLive, string $today): array
    {
        $day = new DateTimeImmutable($today);
        $start = $day->modify('-'.$day->format('w').' days')->modify('-7 days');
        $weeks = [];

        while ($start->modify('+4 days')->format('Y-m-d') >= $goLive) {
            array_unshift($weeks, [$start->format('Y-m-d'), $start->modify('+4 days')->format('Y-m-d')]);
            $start = $start->modify('-7 days');
        }

        return $weeks;
    }
}
