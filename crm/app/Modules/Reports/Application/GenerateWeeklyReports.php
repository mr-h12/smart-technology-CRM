<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * `J-05` (§15; `D-136`): from the Sunday after each Sunday → Thursday week
 * (`D-125`), one RPT-WK draft per active user holding `report.create` (the
 * hidden Super Admin is never listed by `holdersOf`). A week that ends before
 * the go-live date gets none, and while the go-live date is empty nothing is
 * created (`D-127`). Idempotent (§15.1): an author who already has the week is
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
        private SettingsRepositoryInterface $settings,
        private CompanyToday $today,
        private UserFactsInterface $users,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @return int how many drafts were created */
    public function run(): int
    {
        $goLive = $this->settings->all()[SystemSetting::ReportsGoLiveDate->value] ?? null;

        if (! is_string($goLive) || $goLive === '') {
            return 0;
        }

        $authors = array_keys($this->users->holdersOf('report', 'create'));
        $created = 0;

        foreach (self::weeksSince($goLive, $this->today->date()) as [$start, $end]) {
            $has = $this->reports->weeklyAuthorsOf($start);

            foreach (array_diff($authors, $has) as $authorId) {
                $this->connection->transaction(function () use ($authorId, $start, $end): void {
                    $draft = $this->reports->createDraft('weekly', 'RPT-WK', $authorId, $start, $end);

                    $this->audit->record(AuditEvent::of('REPORT_CREATED'), 'report', $draft['id'], null, [
                        'code' => $draft['code'], 'type' => 'weekly', 'period_start' => $start, 'period_end' => $end,
                    ]);
                });
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
