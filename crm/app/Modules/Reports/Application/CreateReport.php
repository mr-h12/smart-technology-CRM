<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * A daily report's draft for the company day it is written on (`D-126`
 * RPT-DY), numbered `RPT-DY-YYYY-NNNN` (§11.2's prefix) and audited (`AUD-01`).
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
    public function daily(string $actorId): array
    {
        return $this->connection->transaction(function () use ($actorId): array {
            $day = $this->today->date();
            $created = $this->reports->createDraft('daily', 'RPT-DY', $actorId, $day, $day);

            $this->audit->record(AuditEvent::of('REPORT_CREATED'), 'report', $created['id'], null, [
                'code' => $created['code'], 'type' => 'daily', 'period_start' => $day, 'period_end' => $day,
            ]);

            return $created;
        });
    }
}
