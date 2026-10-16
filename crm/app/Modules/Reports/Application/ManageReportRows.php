<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use App\Modules\Reports\Domain\ReportNotFound;
use App\Modules\Reports\Domain\ReportWriteRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * `D-131` — a daily report's follow-ups (kind `follow_up`: `customer_id`,
 * `note`) and notes (kind `note`: `body`), added, edited and removed row by row
 * by the author while the report is editable (`ReportAccess::editable`).
 *
 * A follow-up's customer is one the author reaches under `customer.view` at its
 * row scope; any other is 422 on the field. Each write is one transaction and
 * is audited with old and new values (`REPORT_FOLLOW_UP_ADDED`, `REPORT_NOTE_UPDATED`, …).
 * Row writes carry no `If-Match`: the last write to a row wins, as for customer notes (`D-124`).
 */
final readonly class ManageReportRows
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private ReportAccess $access,
        private AuthorizeAction $authorize,
        private CustomerDirectoryInterface $customers,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string> the row, with its `id`
     */
    public function add(string $kind, string $reportId, array $fields, string $actorId): array
    {
        return $this->connection->transaction(function () use ($kind, $reportId, $fields, $actorId): array {
            $this->editable($kind, $reportId, $actorId);
            $this->checkCustomer($fields, $actorId);

            $id = $this->reports->addRow($kind, $reportId, $fields, $actorId);
            $this->audit->record(self::event($kind, 'ADDED'), 'report_'.$kind, $id, null, $fields);

            return ['id' => $id, ...$fields];
        });
    }

    /**
     * @param  array<string, string>  $changes  the fields sent; an absent one keeps the stored value
     * @return array<string, string> the row, with its `id`
     */
    public function update(string $kind, string $reportId, string $rowId, array $changes, string $actorId): array
    {
        return $this->connection->transaction(function () use ($kind, $reportId, $rowId, $changes, $actorId): array {
            $before = $this->row($kind, $reportId, $rowId, $actorId);
            $this->checkCustomer($changes, $actorId);

            $this->reports->updateRow($kind, $rowId, $changes, $actorId);
            $after = [...$before, ...$changes];
            unset($before['id']);
            $this->audit->record(self::event($kind, 'UPDATED'), 'report_'.$kind, $rowId, $before, array_diff_key($after, ['id' => true]));

            return $after;
        });
    }

    public function remove(string $kind, string $reportId, string $rowId, string $actorId): void
    {
        $this->connection->transaction(function () use ($kind, $reportId, $rowId, $actorId): void {
            $before = $this->row($kind, $reportId, $rowId, $actorId);

            $this->reports->removeRow($kind, $rowId, $actorId);
            unset($before['id']);
            $this->audit->record(self::event($kind, 'REMOVED'), 'report_'.$kind, $rowId, $before, null);
        });
    }

    /**
     * The live row of an editable report the author reaches; a row that is
     * gone or another report's is the report's own 404.
     *
     * @return array<string, string>
     */
    private function row(string $kind, string $reportId, string $rowId, string $actorId): array
    {
        $this->editable($kind, $reportId, $actorId);

        return $this->reports->findRow($kind, $reportId, $rowId) ?? throw ReportNotFound::of($reportId);
    }

    /** `D-137`: the one place every follow-up write passes — add, update and remove — refuses a report that is not RPT-DY; notes pass on any type. */
    private function editable(string $kind, string $reportId, string $actorId): void
    {
        $report = $this->access->editable($reportId, $actorId);

        if ($kind === 'follow_up' && $report->type !== 'daily') {
            throw ReportWriteRefused::followUpNotAllowed();
        }
    }

    /** @param  array<string, string>  $fields */
    private function checkCustomer(array $fields, string $actorId): void
    {
        if (! isset($fields['customer_id'])) {
            return;
        }

        $scope = CustomerRowScope::resolve($this->authorize->decide($actorId, 'customer', 'view')->scopeValues(), $actorId);

        // An archived customer is refused like one out of reach — same 422 (3.2's permission audit, gap 1).
        if ($this->customers->find($fields['customer_id'], $scope)->isArchived ?? true) {
            throw ReportWriteRefused::customerNotAllowed();
        }
    }

    private static function event(string $kind, string $verb): AuditEvent
    {
        return AuditEvent::of('REPORT_'.strtoupper($kind).'_'.$verb);
    }
}
