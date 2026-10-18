<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\Documents;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\Listing\DealNotFound;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * `DELETE /deals/{id}/documents/{file}` — D-117: whoever may upload may remove.
 *
 * `AttachDealDocument`'s permission and reach, unchanged: `deal.edit` on the
 * route, the deal looked up through the caller's scope. A file that does not
 * hang from this deal is the same `DealNotFound` 404 (`SEC-08`), so the answer
 * never says whether the file exists elsewhere.
 */
final readonly class RemoveDealDocument
{
    public function __construct(
        private DealDirectoryInterface $deals,
        private FileWriterInterface $files,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
        private UserFactsInterface $users,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     *
     * @throws DealNotFound when the deal is absent or out of reach, or the file does not hang from it
     */
    public function handle(string $dealId, string $fileId, array $heldScopes, string $actorId): void
    {
        if ($this->deals->find($dealId, DealRowScope::resolve($heldScopes, $actorId, $this->users->teamMemberIdsOf(...))) === null) {
            throw DealNotFound::of($dealId);
        }

        $this->connection->transaction(function () use ($dealId, $fileId, $actorId): void {
            $name = $this->files->remove(AttachmentParent::Deal, $dealId, $fileId, $actorId);

            if ($name === null) {
                throw DealNotFound::of($dealId);
            }

            $this->audit->record(
                AuditEvent::of('DEAL_DOCUMENT_REMOVED'),
                'deal',
                $dealId,
                ['file_id' => $fileId, 'original_name' => $name],
            );
        });
    }
}
