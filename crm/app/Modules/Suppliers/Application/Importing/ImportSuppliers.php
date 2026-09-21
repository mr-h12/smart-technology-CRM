<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application\Importing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Suppliers\Domain\Contracts\SupplierDirectoryInterface;
use App\Modules\Suppliers\Domain\Importing\ImportSummary;
use App\Modules\Suppliers\Domain\Writing\SupplierDraft;
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

    /** The fields whose absence makes a saved row incomplete (`D-85`). */
    private const EXPECTED = ['type', 'phone', 'contact_person'];

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

            foreach ($rows as $row) {
                $attributes = self::attributes($row);

                if ($attributes === null) {
                    continue;
                }

                $flagged = array_diff(self::EXPECTED, array_keys($attributes)) !== [];
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

            return $this->suppliers->recordImportBatch($originalFilename, count($rows), $imported, $incomplete, $actorId);
        });
    }

    /**
     * The row as columns worth writing, or null when it cannot be saved at all.
     * Empty cells are dropped, not written as `''`, so `is_incomplete` agrees
     * with what the row holds.
     *
     * @param  array<string, string>  $row
     * @return array<string, string|bool>|null
     */
    private static function attributes(array $row): ?array
    {
        $attributes = [];

        foreach (SupplierCsv::COLUMNS as $column) {
            $value = $row[$column] ?? '';

            if ($value === '') {
                continue;
            }

            if (isset(self::LENGTHS[$column]) && mb_strlen($value) > self::LENGTHS[$column]) {
                return null;
            }

            if ($column === 'type') {
                $value = strtolower($value);

                if (! in_array($value, SupplierDraft::TYPES, true)) {
                    return null;
                }
            }

            if ($column === 'has_open_account') {
                $word = strtolower($value);

                if (! array_key_exists($word, self::OPEN_ACCOUNT)) {
                    return null;
                }

                $attributes[$column] = self::OPEN_ACCOUNT[$word];

                continue;
            }

            $attributes[$column] = $value;
        }

        // `suppliers_name_not_blank` is a CHECK; `CsvReader` has trimmed, so a
        // name of spaces arrives here as ''.
        return isset($attributes['name']) ? $attributes : null;
    }
}
