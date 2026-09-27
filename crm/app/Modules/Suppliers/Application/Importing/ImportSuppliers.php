<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application\Importing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Suppliers\Domain\Contracts\SupplierDirectoryInterface;
use App\Modules\Suppliers\Domain\Importing\ImportSummary;
use App\Modules\Suppliers\Domain\Writing\SupplierDraft;
use App\Support\Csv\RowRejected;
use Illuminate\Database\ConnectionInterface;

/**
 * `D-85` (F-09 · 1.4) — the suppliers' import, on `ImportCustomers`' terms:
 * the file is read through the storage abstraction and dropped, the whole file
 * is one transaction (`DB-11`), every saved row is audited like a hand-typed
 * create (`AUD-01`), and one `supplier_import_batches` row records the counts.
 *
 * What is `D-85`'s own:
 *
 * - **Incomplete** (`D-31`): an empty `type`, `phone` or `contact_person`
 *   saves the row flagged. An empty `has_open_account` is *no*, not a gap.
 * - **Not saved, counted** (owner's ruling (a), 2026-09-21): no name, a type
 *   §7.1 does not name, an open-account word that is not a yes or a no, or a
 *   value longer than its column. Each would be a CHECK violation or a guess.
 * - **`color_rating`** is never written, so the column's default — White,
 *   "new / not yet rated" — is what every imported supplier gets (`D-19`).
 *
 * ponytail: the whole file in one transaction, in one request — the customers'
 * ceiling, moved to the `maintenance` queue when a real import is slow.
 */
final readonly class ImportSuppliers
{
    /** The columns' own lengths (the suppliers migration). Longer is a failed row, never a truncation. */
    private const LENGTHS = ['name' => 255, 'phone' => 32, 'contact_person' => 255];

    /** English only (owner, 2026-09-21), any case. Empty is handled before this. */
    private const OPEN_ACCOUNT = ['1' => true, 'true' => true, 'yes' => true, '0' => false, 'false' => false, 'no' => false];

    public function __construct(
        private SupplierDirectoryInterface $suppliers,
        private AuditRecorderInterface $audit,
        private StorageServiceInterface $storage,
        private ConnectionInterface $connection,
    ) {}

    public function handle(string $path, string $originalFilename, string $actorId): ImportSummary
    {
        $handle = $this->storage->readUploadStream($path);

        try {
            $rows = SupplierCsv::rows($handle);
        } finally {
            fclose($handle);
        }

        return $this->connection->transaction(function () use ($rows, $originalFilename, $actorId): ImportSummary {
            $imported = 0;
            $incomplete = 0;
            $rejected = [];

            foreach ($rows as $number => $row) {
                try {
                    $attributes = self::attributes($row);
                } catch (RowRejected $rejection) {
                    $rejected[] = $rejection->at($number);

                    continue;
                }

                $flagged = array_diff(SupplierDraft::EXPECTED, array_keys($attributes)) !== [];
                $draft = SupplierDraft::forImport($attributes, $flagged);

                $supplier = $this->suppliers->create($draft, $actorId);

                $this->audit->record(
                    AuditEvent::of('SUPPLIER_CREATED'),
                    'supplier',
                    $supplier->id,
                    null,
                    $draft->attributes,
                );

                $imported++;

                if ($flagged) {
                    $incomplete++;
                }
            }

            $batch = $this->suppliers->recordImportBatch($originalFilename, count($rows), $imported, $incomplete, $actorId);

            return new ImportSummary($batch->id, $batch->originalFilename, $batch->rowCount, $batch->importedCount, $batch->incompleteCount, $rejected);
        });
    }

    /**
     * The row as columns worth writing. Empty cells are dropped, not written
     * as `''`, so `is_incomplete` agrees with what the row holds.
     *
     * @param  array<string, string>  $row
     * @return array<string, string|bool>
     *
     * @throws RowRejected when it cannot be saved at all
     */
    private static function attributes(array $row): array
    {
        $attributes = [];

        foreach (SupplierCsv::COLUMNS as $column) {
            $value = $row[$column] ?? '';

            if ($value === '') {
                continue;
            }

            if (isset(self::LENGTHS[$column]) && mb_strlen($value) > self::LENGTHS[$column]) {
                throw RowRejected::tooLong($column, self::LENGTHS[$column]);
            }

            if ($column === 'type') {
                $value = strtolower($value);

                if (! in_array($value, SupplierDraft::TYPES, true)) {
                    throw RowRejected::notAllowed($column);
                }
            }

            if ($column === 'has_open_account') {
                $word = strtolower($value);

                if (! array_key_exists($word, self::OPEN_ACCOUNT)) {
                    throw RowRejected::notAllowed($column);
                }

                $attributes[$column] = self::OPEN_ACCOUNT[$word];

                continue;
            }

            $attributes[$column] = $value;
        }

        // `suppliers_name_not_blank` is a CHECK; `CsvReader` has trimmed, so a
        // name of spaces arrives here as ''.
        if (! isset($attributes['name'])) {
            throw RowRejected::required('name');
        }

        return $attributes;
    }
}
