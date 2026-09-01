<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\Documents;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Deals\Domain\Access\DealRowScope;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\Documents\DealDocument;
use App\Modules\Deals\Domain\Listing\DealNotFound;
use App\Modules\Storage\Application\ScanStoredFile;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\Contracts\UploadValidatorInterface;
use App\Modules\Storage\Domain\Exceptions\ScannerUnavailable;
use App\Modules\Storage\Domain\ScanStatus;
use Illuminate\Database\ConnectionInterface;

/**
 * `POST /deals/{id}/documents` — §17's upload flow, with the parent already
 * chosen: `AttachmentParent::Deal`.
 *
 * ── Permission is `SaveDeal`'s pattern, not a new mechanism ─────────────────
 *
 * §3.4 names no separate "attach document" row — the closest is `edit`, whose
 * scope (`All · Team · Out · Own · Own · Asgn · —`) is what the route's
 * `permission:deal.edit` middleware already resolves. D-38 says "attachment
 * permission = permission on the parent entity", and for a deal that
 * permission already exists and is already enforced the way every other deal
 * write enforces it: resolve the scope, look the deal up through it, and let
 * `find()` returning null mean "absent or out of reach" — `SEC-08`'s
 * indistinguishable 404, on `DealNotFound`'s own precedent.
 *
 * ── The virus scan runs after the transaction commits, not inside it ───────
 *
 * `DB-11` covers the business write — the `files` row, the D-71 pivot, the
 * audit entry — because those three are one fact and must commit or not
 * together. The scan is not that fact: `ScanStoredFile`'s own docblock says a
 * `ScannerUnavailable` must leave the row `pending` rather than write
 * anything, and a scan run *inside* this transaction would instead roll the
 * whole upload back on a scanner outage — turning "not yet checked" into
 * "never happened", which is a worse failure than an uploaded file nobody can
 * download until the scanner comes back.
 *
 * ⚠️ **A `ScannerUnavailable` here is caught, not rethrown.** The upload
 * itself already succeeded — the bytes are stored, the row exists, the pivot
 * and the audit entry are committed — and `isServable()` already treats
 * `pending` as "not yet", the same as a scan that simply has not run yet.
 * Failing the whole request over a scanner outage would misreport a write
 * that worked as one that did not. **Not covered:** nothing here retries a
 * scan that failed this way; the file waits at `pending` until something
 * re-scans it, and no such trigger exists yet.
 */
final readonly class AttachDealDocument
{
    public function __construct(
        private DealDirectoryInterface $deals,
        private UploadValidatorInterface $validator,
        private StorageServiceInterface $storage,
        private FileWriterInterface $files,
        private ScanStoredFile $scan,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     *
     * @throws DealNotFound when the deal is absent **or** outside the caller's reach
     * @throws \App\Modules\Storage\Domain\Exceptions\UploadRejected when §17's bytes-level checks refuse it
     */
    public function handle(
        string $dealId,
        string $sourcePath,
        string $originalName,
        array $heldScopes,
        string $actorId,
    ): DealDocument {
        $scope = DealRowScope::resolve($heldScopes, $actorId);

        // Read before anything is validated or stored: an upload against a
        // deal the caller cannot reach is a 404, not a 422 about the file —
        // OpenAPI's ordering for every other deal write.
        if ($this->deals->find($dealId, $scope) === null) {
            throw DealNotFound::of($dealId);
        }

        $type = $this->validator->validate($sourcePath);
        $sizeBytes = $this->storage->sourceSizeBytes($sourcePath);

        $path = $this->storage->store(AttachmentParent::Deal, $dealId, $type, $sourcePath);

        $fileId = $this->connection->transaction(
            function () use ($path, $originalName, $type, $sizeBytes, $dealId, $actorId): string {
                $fileId = $this->files->create($path, $originalName, $type->mimeType(), $sizeBytes, $actorId);

                $this->files->attach(AttachmentParent::Deal, $dealId, $fileId);

                $this->audit->record(
                    AuditEvent::of('DEAL_DOCUMENT_ATTACHED'),
                    'deal',
                    $dealId,
                    null,
                    ['file_id' => $fileId, 'original_name' => $originalName],
                );

                return $fileId;
            },
        );

        try {
            $scanStatus = $this->scan->scan($fileId);
        } catch (ScannerUnavailable) {
            // See the class docblock: the upload already committed, and
            // `pending` is the row's own default — nothing here writes a
            // status the scanner never gave.
            $scanStatus = ScanStatus::Pending;
        }

        return new DealDocument(
            id: $fileId,
            originalName: $originalName,
            mimeType: $type->mimeType(),
            sizeBytes: $sizeBytes,
            scanStatus: $scanStatus->value,
            createdAt: now()->toDateTimeImmutable(),
        );
    }
}
