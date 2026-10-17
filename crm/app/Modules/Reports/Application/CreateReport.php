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
 * A daily report's draft for the company day it is written on (`D-126`
 * RPT-DY), numbered `RPT-DY-YYYY-NNNN` (§11.2's prefix) and audited (`AUD-01`). A second one
 * for a day the author already has a daily is a warning (`ReportWriteRefused::duplicatePeriod`)
 * until `confirm_duplicate` overrules it.
 */
final readonly class CreateReport
{
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

            $created = $this->reports->createDraft('daily', 'RPT-DY', $actorId, $day, $day);

            $this->audit->record(AuditEvent::of('REPORT_CREATED'), 'report', $created['id'], null, [
                'code' => $created['code'], 'type' => 'daily', 'period_start' => $day, 'period_end' => $day,
            ]);

            return $created;
        });
    }
}
