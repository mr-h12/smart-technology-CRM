<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * `J-01 expire_quotations` (§15; §6.1 "Expired · Passed `valid_until` with no
 * reply · System (job J-01)"; Module 10 · 2.1, Q9).
 *
 * "Today" is the company's date (`CompanyToday`). `valid_until` is a date, so
 * `DB-08`'s UTC storage is not touched — only which calendar day it is.
 *
 * The audit carries no actor: a queued job has no request, so
 * `AuditContext::system()` records `user_id` NULL. Idempotent (§15.1): a second
 * run finds no `sent` row left to move. No deal move (Q2) and no customer
 * status (debt register).
 */
final readonly class ExpireQuotations
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
        private CompanyToday $today,
    ) {}

    /** @return int how many quotations expired */
    public function run(): int
    {
        $today = $this->today->date();

        return $this->connection->transaction(function () use ($today): int {
            $ids = $this->quotations->expireSentBefore($today);

            foreach ($ids as $id) {
                $this->audit->record(AuditEvent::of('QUOTATION_EXPIRED'), 'quotation', $id, ['status' => 'sent'], ['status' => 'expired']);
            }

            return count($ids);
        });
    }
}
