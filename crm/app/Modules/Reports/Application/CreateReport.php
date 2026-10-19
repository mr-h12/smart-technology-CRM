<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\ReportWriteRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * Every report draft is minted here, numbered with §11.2's prefix and audited (`AUD-01`):
 * the author's daily for the company day it is written on (`D-126` RPT-DY) — a second one
 * for a day the author already has is a warning (`ReportWriteRefused::duplicatePeriod`)
 * until `confirm_duplicate` overrules it — and through `draft()`, J-05's weekly (`D-136`
 * RPT-WK) and J-07's monthly (`D-140` RPT-MN).
 */
final readonly class CreateReport
{
    private const PREFIXES = ['daily' => 'RPT-DY', 'weekly' => 'RPT-WK', 'monthly' => 'RPT-MN'];

    public function __construct(
        private ReportDirectoryInterface $reports,
        private CompanyToday $today,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @return array{id: string, code: string} */
    public function daily(string $actorId, bool $confirmDuplicate = false): array
    {
        return $this->connection->transaction(function () use ($actorId, $confirmDuplicate): array {
            $day = $this->today->date();

            // ponytail: two simultaneous creates both pass this read; the unique key a lock would need
            // contradicts `confirm_duplicate`, so an advisory lock on (author, day) if it ever happens.
            $existing = $confirmDuplicate ? null : $this->reports->findDaily($actorId, $day);

            if ($existing !== null) {
                throw ReportWriteRefused::duplicatePeriod($existing);
            }

            return $this->draft('daily', $actorId, $day, $day);
        });
    }

    /**
     * A draft of `$type`, numbered with §11.2's prefix and audited (`AUD-01`) — the
     * author's daily, and J-05's weekly and J-07's monthly with no actor. The caller
     * owns the transaction.
     *
     * @param  key-of<self::PREFIXES>  $type
     * @return array{id: string, code: string}
     */
    public function draft(string $type, string $authorId, string $start, string $end): array
    {
        $created = $this->reports->createDraft($type, self::PREFIXES[$type], $authorId, $start, $end);

        $this->audit->record(AuditEvent::of('REPORT_CREATED'), 'report', $created['id'], null, [
            'code' => $created['code'], 'type' => $type, 'period_start' => $start, 'period_end' => $end,
        ]);

        return $created;
    }
}
