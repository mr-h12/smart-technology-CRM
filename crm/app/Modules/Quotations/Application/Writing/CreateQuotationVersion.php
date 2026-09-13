<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;
use App\Modules\Quotations\Domain\Status\QuotationStatusTransition;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * `POST /quotations/{id}/new-version` — Module 7 Point 4.3. §6.3 / `D-08`:
 * "the system saves a full copy and the employee edits a new version".
 *
 * Scope first (`SEC-08`, 404, through {@see QuotationWriteAccess::reach()} —
 * no `If-Match`, because nothing is written to the row it would name), then
 * the status: a copy opens from the owner's Q4 statuses only, anything else
 * is `409 state_transition_invalid`. The copy itself is
 * {@see QuotationDirectoryInterface::copy()}, and the database refuses a
 * second copy of one parent (`DB-03`). One transaction for the copy and its
 * audit row (`DB-11`); the source row is not touched, so it needs no lock.
 *
 * `QUOTATION_VERSION_CREATED` is recorded on the **copy** — the row that came
 * into being — naming its parent, so the chain reads from either end.
 */
final readonly class CreateQuotationVersion
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private QuotationWriteAccess $access,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.edit` — whoever may edit the next draft may open it
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     */
    public function create(string $parentId, array $heldScopes, string $actorId): QuotationSummary
    {
        return $this->connection->transaction(function () use ($parentId, $heldScopes, $actorId): QuotationSummary {
            $parent = $this->access->reach($parentId, $heldScopes, $actorId);

            if (! QuotationStatusTransition::opensNewVersionFrom($parent->status)) {
                throw QuotationWriteRefused::invalidTransition($parent->status, 'draft');
            }

            $copy = $this->quotations->copy($parentId, $actorId);

            $this->audit->record(
                AuditEvent::of('QUOTATION_VERSION_CREATED'),
                'quotation',
                $copy->id,
                null,
                ['parent_id' => $parentId, 'version' => $copy->version, 'code' => $copy->code],
            );

            return $copy;
        });
    }
}
