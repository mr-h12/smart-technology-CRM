<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Importing;

use App\Modules\Admin\Domain\Contracts\ManagedListRepositoryInterface;
use App\Modules\Admin\Domain\Reference\ListEntry;
use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Catalog\Domain\Contracts\CatalogItemDirectoryInterface;
use App\Modules\Catalog\Domain\Importing\ImportSummary;
use App\Modules\Catalog\Domain\Writing\CatalogItemDraft;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Suppliers\Domain\Contracts\SupplierLookupInterface;
use App\Support\Csv\RowRejected;
use Illuminate\Database\ConnectionInterface;

/**
 * `D-86` (F-10 · 1.5) — one CSV of catalog items, in one transaction.
 *
 * A row is **rejected and counted** when it cannot be saved as written: a
 * missing or unknown `kind`, a product with no `name`, a `unit`,
 * `service_type` or `company` with no list entry, a value longer than its
 * column, an unknown `is_active` word, or a `supplier` naming no supplier or
 * more than one. The import never adds a list value (ruling 3), unlike the
 * form's company. A row is **saved and flagged** when it lacks what the form
 * requires (`D-31`): a product's `unit`, a service's `service_type`, anyone's
 * `company`. A row is **skipped** when its `product_code`, or its name when it
 * has none, is already on file or on an earlier row of the file (`D-94`,
 * F-20 · 1.4): that replaces ruling 6's "every row is a new item".
 *
 * A list value is stored as its entry's code, matched by code first and then
 * by either label, trimmed and case-folded (owner, 2026-09-22: the code wins).
 */
final readonly class ImportCatalogItems
{
    /** The columns' own lengths (`create_catalog_items`). Longer is a rejected row, never a truncation. */
    private const LENGTHS = ['product_code' => 64, 'name' => 255, 'category' => 128];

    /** The supplier import's words (owner, 2026-09-22), any case. Empty is active, handled before this. */
    private const IS_ACTIVE = ['1' => true, 'true' => true, 'yes' => true, '0' => false, 'false' => false, 'no' => false];

    private const LISTED = ['unit' => ManagedList::Units, 'service_type' => ManagedList::ServiceTypes, 'company' => ManagedList::Companies];

    public function __construct(
        private CatalogItemDirectoryInterface $items,
        private AuditRecorderInterface $audit,
        private StorageServiceInterface $storage,
        private ManagedListRepositoryInterface $lists,
        private SupplierLookupInterface $suppliers,
        private ConnectionInterface $connection,
    ) {}

    public function handle(string $path, string $originalFilename, string $actorId): ImportSummary
    {
        $handle = $this->storage->readUploadStream($path);

        try {
            $rows = CatalogCsv::rows($handle);
        } finally {
            fclose($handle);
        }

        $entries = array_map(fn (ManagedList $list): array => $this->lists->entriesFor($list), self::LISTED);

        return $this->connection->transaction(function () use ($rows, $entries, $originalFilename, $actorId): ImportSummary {
            $imported = 0;
            $incomplete = 0;

            $rejected = [];
            $skipped = [];

            foreach ($rows as $number => $row) {
                try {
                    $attributes = self::attributes($row, $entries);
                    $supplierId = $this->supplierFor($row['supplier'] ?? '');
                } catch (RowRejected $rejection) {
                    $rejected[] = $rejection->at($number);

                    continue;
                }

                // `D-94`: neither imported, linked nor merged, only named. The
                // cells as `attributes()` read them (`CsvReader` has trimmed;
                // '' is empty). Earlier rows were created in this transaction,
                // so the directory sees them too.
                if ($this->items->duplicateExists($row['product_code'] ?? '', $row['name'] ?? '')) {
                    $skipped[] = $number;

                    continue;
                }

                $flagged = self::isIncomplete($attributes);
                $draft = CatalogItemDraft::forImport($attributes, $flagged);
                $item = $this->items->create($draft, $actorId);

                $this->audit->record(AuditEvent::of('CATALOG_ITEM_CREATED'), 'catalog_item', $item->id, null, $draft->attributes);

                if ($supplierId !== null) {
                    $linkId = $this->items->link($item->id, $supplierId, $actorId);

                    $this->audit->record(
                        AuditEvent::of('CATALOG_ITEM_SUPPLIER_LINKED'),
                        'catalog_item_supplier',
                        $linkId,
                        null,
                        ['catalog_item_id' => $item->id, 'supplier_id' => $supplierId],
                    );
                }

                $imported++;

                if ($flagged) {
                    $incomplete++;
                }
            }

            $batch = $this->items->recordImportBatch($originalFilename, count($rows), $imported, $incomplete, $actorId);

            return new ImportSummary($batch->id, $batch->originalFilename, $batch->rowCount, $batch->importedCount, $batch->incompleteCount, $rejected, $skipped);
        });
    }

    /**
     * The row as columns worth writing. Empty cells are dropped, not written
     * as `''`, so the flag agrees with the row.
     *
     * @param  array<string, string>  $row
     * @param  array<string, list<ListEntry>>  $entries
     * @return array<string, string|bool>
     *
     * @throws RowRejected when it is rejected
     */
    private static function attributes(array $row, array $entries): array
    {
        $kind = strtolower($row['kind'] ?? '');

        if (! in_array($kind, CatalogItemDraft::KINDS, true)) {
            throw $kind === '' ? RowRejected::required('kind') : RowRejected::notAllowed('kind');
        }

        $attributes = ['kind' => $kind];

        foreach (CatalogItemDraft::WRITABLE as $column) {
            $value = $row[$column] ?? '';

            if ($column === 'kind' || $value === '') {
                continue;
            }

            if (isset(self::LENGTHS[$column]) && mb_strlen($value) > self::LENGTHS[$column]) {
                throw RowRejected::tooLong($column, self::LENGTHS[$column]);
            }

            if ($column === 'is_active') {
                $word = strtolower($value);

                if (! array_key_exists($word, self::IS_ACTIVE)) {
                    throw RowRejected::notAllowed($column);
                }

                $attributes[$column] = self::IS_ACTIVE[$word];

                continue;
            }

            if (isset($entries[$column])) {
                $code = self::codeFor($value, $entries[$column]);

                if ($code === null) {
                    throw RowRejected::notAllowed($column);
                }

                $value = $code;
            }

            $attributes[$column] = $value;
        }

        if ($kind === 'product' && ! isset($attributes['name'])) {
            throw RowRejected::required('name');
        }

        return $attributes;
    }

    /**
     * The entry's code: a code match first, then either label (owner,
     * 2026-09-22). `CsvReader` has trimmed the cell.
     *
     * @param  list<ListEntry>  $entries
     */
    private static function codeFor(string $value, array $entries): ?string
    {
        $needle = mb_strtolower($value);

        foreach ($entries as $entry) {
            if (mb_strtolower($entry->code()) === $needle) {
                return $entry->code();
            }
        }

        foreach ($entries as $entry) {
            if (mb_strtolower($entry->labelEn()) === $needle || mb_strtolower($entry->labelAr()) === $needle) {
                return $entry->code();
            }
        }

        return null;
    }

    /**
     * The one supplier the cell names; null for a blank cell (no link).
     *
     * @throws RowRejected when it names none or several
     */
    private function supplierFor(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        $ids = $this->suppliers->idsNamed($name);

        if (count($ids) === 1) {
            return $ids[0];
        }

        throw $ids === [] ? RowRejected::notFound('supplier') : RowRejected::ambiguous('supplier', 'catalog.import.supplier_ambiguous');
    }

    /** @param  array<string, string|bool>  $attributes */
    private static function isIncomplete(array $attributes): bool
    {
        $required = $attributes['kind'] === 'product' ? 'unit' : 'service_type';

        return ! isset($attributes[$required], $attributes['company']);
    }
}
