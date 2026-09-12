<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Writing\QuotationEtag;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * `DELETE /quotations/{id}` — Module 7 Point 4.4. `D-46` / §3.5's "delete
 * (Draft only)", recorded on #103 (Q5) as a contract addition to
 * `OpenAPI §7.1`.
 *
 * {@see QuotationWriteAccess} refuses first — scope (404), `If-Match` (400,
 * then 409 `concurrency_conflict`) — and only then the status: outside
 * `draft` it is 3.6's `422 quotation_not_draft`, the same rule the edit
 * enforces, not a transition (§6.4 draws no arrow for a delete). The write is
 * guarded again in SQL ({@see QuotationDirectoryInterface::delete()}), so a
 * concurrent edit cannot be deleted from under.
 *
 * `QUOTATION_DELETED` (`AUD-01`) records what was removed — status and code —
 * and nothing after: the row is gone from every read, `DB-01` keeps it in
 * the table.
 */
final readonly class DeleteQuotation
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private QuotationWriteAccess $access,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.delete`
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     */
    public function delete(string $quotationId, ?string $ifMatch, array $heldScopes, string $actorId): void
    {
        $this->connection->transaction(function () use ($quotationId, $ifMatch, $heldScopes, $actorId): void {
            $before = $this->access->open($quotationId, $ifMatch, $heldScopes, $actorId);

            if ($before->status !== 'draft') {
                throw QuotationWriteRefused::notDraft();
            }

            if (! $this->quotations->delete($quotationId, $before->versionToken, $actorId)) {
                throw QuotationWriteRefused::staleVersion(QuotationEtag::of($before));
            }

            $this->audit->record(
                AuditEvent::of('QUOTATION_DELETED'),
                'quotation',
                $quotationId,
                ['status' => $before->status, 'code' => $before->code],
            );
        });
    }
}
