<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Application\Documents;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Storage\Application\ScanStoredFile;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\Contracts\UploadValidatorInterface;
use App\Modules\Storage\Domain\Exceptions\ScannerUnavailable;
use App\Modules\Storage\Domain\ScanStatus;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierQuotationDirectoryInterface;
use App\Modules\SupplierQuotations\Domain\Documents\SupplierQuotationDocument;
use App\Modules\SupplierQuotations\Domain\Listing\SupplierQuotationNotFound;
use Illuminate\Database\ConnectionInterface;

/**
 * §17's upload flow with the parent fixed to `AttachmentParent::SupplierQuotation`
 * — `AttachDealDocument`'s shape (Module 5 Point 4.1), with one parameter fewer
 * and one reason for it.
 *
 * ── No `$heldScopes`, and that is the whole difference ────────────────────
 *
 * `AttachDealDocument` takes the caller's scopes and resolves a `DealRowScope`,
 * because §3.4 gives deals five reaches and `SEC-08` makes a deal invisible to
 * a caller outside its own. §3.6 gives this resource one reach, `All`, under "a
 * shared screen — not restricted by ownership", so there is no scope to resolve
 * and a parameter for it would be the "parameter every caller passes the same
 * value for" that this module's directory interface already refuses.
 *
 * Authorisation is therefore entirely the route's `permission:
 * supplier_quotation.upload_attachment` middleware (Point 5.3) on the way in,
 * and `SupplierQuotationAttachmentPermission` (Point 5.1) on the way back out.
 * Nothing is decided here, and nothing should be: a second authorisation model
 * in a use case is what `SEC-07` exists to prevent.
 *
 * ── The parent is read before the file is looked at ───────────────────────
 *
 * An upload against an offer that is not there is a 404, not a 422 about the
 * bytes. Validating first would run §17's work for a request that was never
 * going to be written, and would tell a caller their file was wrong about an
 * offer they may not learn exists (`OpenAPI §5.1`).
 *
 * ── The virus scan runs after the transaction commits ─────────────────────
 *
 * `DB-11` covers the business write — the `files` row, `D-71`'s pivot row and
 * the audit entry — because those three are one fact. The scan is not that
 * fact, and running it inside the transaction would roll a successful upload
 * back on a scanner outage, turning "not yet checked" into "never happened".
 *
 * ⚠️ **A `ScannerUnavailable` is caught, not rethrown**, for the reason
 * `AttachDealDocument` gives verbatim: the bytes are stored and the rows are
 * committed, and `pending` already means "not servable" to
 * `ScanStatus::isServable()`. **Not covered:** nothing retries a scan that
 * failed this way. The file waits at `pending`, and no re-scan trigger exists
 * anywhere in this codebase — the same gap Module 5 recorded.
 */
final readonly class AttachSupplierQuotationDocument
{
    public function __construct(
        private SupplierQuotationDirectoryInterface $quotations,
        private UploadValidatorInterface $validator,
        private StorageServiceInterface $storage,
        private FileWriterInterface $files,
        private ScanStoredFile $scan,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws SupplierQuotationNotFound when the offer is absent or `DB-01` soft-deleted
     * @throws \App\Modules\Storage\Domain\Exceptions\UploadRejected when §17's bytes-level checks refuse it
     */
    public function handle(
        string $quotationId,
        string $sourcePath,
        string $originalName,
        string $actorId,
    ): SupplierQuotationDocument {
        if ($this->quotations->find($quotationId) === null) {
            throw SupplierQuotationNotFound::of($quotationId);
        }

        $type = $this->validator->validate($sourcePath);
        $sizeBytes = $this->storage->sourceSizeBytes($sourcePath);

        $path = $this->storage->store(AttachmentParent::SupplierQuotation, $quotationId, $type, $sourcePath);

        $fileId = $this->connection->transaction(
            function () use ($path, $originalName, $type, $sizeBytes, $quotationId, $actorId): string {
                $fileId = $this->files->create($path, $originalName, $type->mimeType(), $sizeBytes, $actorId);

                $this->files->attach(AttachmentParent::SupplierQuotation, $quotationId, $fileId);

                $this->audit->record(
                    AuditEvent::of('SUPPLIER_QUOTATION_DOCUMENT_ATTACHED'),
                    'supplier_quotation',
                    $quotationId,
                    null,
                    ['file_id' => $fileId, 'original_name' => $originalName],
                );

                return $fileId;
            },
        );

        try {
            $scanStatus = $this->scan->scan($fileId);
        } catch (ScannerUnavailable) {
            // The upload already committed; `pending` is the row's own default
            // and nothing here writes a status the scanner never gave.
            $scanStatus = ScanStatus::Pending;
        }

        return new SupplierQuotationDocument(
            id: $fileId,
            originalName: $originalName,
            mimeType: $type->mimeType(),
            sizeBytes: $sizeBytes,
            scanStatus: $scanStatus->value,
            createdAt: now()->toDateTimeImmutable(),
        );
    }
}
