<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Importing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Contracts\ImportBatchesInterface;
use App\Modules\Customers\Domain\Importing\ImportSummary;
use App\Modules\Customers\Domain\Writing\CustomerDraft;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * §3.3's import, and `D-31`'s flag.
 *
 * ── `D-31`: incomplete data is accepted ────────────────────────────────────
 *
 * *"Excel import accepts incomplete data (records flagged incomplete)"*, and
 * §10.5 adds the filter and the exclusion from financial reports. So a row with
 * gaps **saves** and is flagged; only a row that cannot save at all is a
 * failure, and failures are `row_count - imported_count` because
 * `import_batches` deliberately has no fourth count.
 *
 * ── What "missing fields" means: `D-87` ruling 1 ───────────────────────────
 *
 * §4.2 marks only `name` required, so the literal reading flags nothing. Until
 * F-11 this class flagged a row when any of §4.2's ten fields was empty, which
 * in practice flagged nearly every imported row. `D-87` (F-11 · 1.3) names the
 * five core fields — `CustomerDraft::EXPECTED` — and the edit that clears the
 * flag reads the same list.
 *
 * ── One transaction for the whole file ─────────────────────────────────────
 *
 * `DB-11` puts each customer beside its own audit row, and a partial import
 * that half-succeeded would leave a batch record describing a state nobody can
 * reconstruct. Skipped rows are skipped, not thrown: nothing here rolls the
 * file back because one row had no name.
 *
 * ponytail: the whole file in one transaction, in one request. A 30 MB upload
 * is a long transaction; chunk it onto the `maintenance` queue when a real
 * import is slow, which is a change to this method and to nothing else.
 *
 * ── No owner column, and no `Idempotency-Key` ──────────────────────────────
 *
 * The format carries no `sales_owner_id`: §3.3 makes `assign` its own
 * permission and Point 3.5 its own route, and a spreadsheet full of UUIDs is
 * not a format anyone would fill in. Imported rows arrive unowned and the
 * Manager assigns them.
 *
 * `OpenAPI §9.1` requires an idempotency key for *"deals, quotations, supplier
 * quotations, purchase orders, reports, versions, and actions that change
 * irreversible-equivalent business state"*. A customer is on none of those
 * lists and is archivable rather than irreversible (`DB-01`) — Point 3.3 read
 * this first and this point follows it rather than re-deciding it.
 */
final readonly class ImportCustomers
{
    /** The columns' own lengths (Point 1.1). A longer value is a failed row, never a silent truncation. */
    private const LENGTHS = [
        'name' => 255, 'sector' => 64, 'region' => 128, 'contact_person' => 255,
        'phone' => 32, 'phone2' => 32, 'whatsapp' => 32, 'email' => 255,
    ];

    public function __construct(
        private CustomerDirectoryInterface $customers,
        private ImportBatchesInterface $batches,
        private AuditRecorderInterface $audit,
        private StorageServiceInterface $storage,
        private ConnectionInterface $connection,
    ) {}

    public function handle(string $path, string $originalFilename, string $actorId): ImportSummary
    {
        // §14.2's abstraction, not `fopen`: `StorageServiceTest` forbids
        // filesystem access everywhere outside `Modules/Storage/Infrastructure`,
        // and `fgetcsv` needs a stream. Nothing is stored — the file is parsed
        // and dropped, which is Point 1.2's decision about `import_batches`.
        $handle = $this->storage->readUploadStream($path);

        try {
            $rows = CustomerCsv::rows($handle);
        } finally {
            fclose($handle);
        }

        return $this->connection->transaction(function () use ($rows, $originalFilename, $actorId): ImportSummary {
            $imported = 0;
            $incomplete = 0;

            foreach ($rows as $row) {
                $attributes = self::attributes($row);

                if ($attributes === null) {
                    // No name, a name of spaces, or a value longer than its
                    // column. Counted in row_count, absent from imported_count.
                    continue;
                }

                $flagged = array_diff(CustomerDraft::EXPECTED, array_keys($attributes)) !== [];
                $draft = CustomerDraft::forImport($attributes, $flagged);

                $customer = $this->customers->create($draft, $actorId);

                // `AUD-01` names create explicitly, and an import is many
                // creates rather than one silent write. Inside the transaction
                // (`DB-11`) with the row it describes.
                $this->audit->record(
                    AuditEvent::of('CUSTOMER_CREATED'),
                    'customer',
                    $customer->id,
                    null,
                    $draft->attributes,
                );

                $imported++;

                if ($flagged) {
                    $incomplete++;
                }
            }

            return $this->batches->record($originalFilename, count($rows), $imported, $incomplete, $actorId);
        });
    }

    /**
     * The row as columns worth writing, or null when it cannot be saved at all.
     *
     * Empty cells are dropped rather than written as `''`: the columns are
     * nullable, and an empty string is a value that would make
     * `filter[is_incomplete]` disagree with what the row actually holds.
     *
     * @param  array<string, string>  $row
     * @return array<string, string>|null
     */
    private static function attributes(array $row): ?array
    {
        $attributes = [];

        foreach (CustomerCsv::COLUMNS as $column) {
            $value = $row[$column] ?? '';

            if ($value === '') {
                continue;
            }

            if (isset(self::LENGTHS[$column]) && mb_strlen($value) > self::LENGTHS[$column]) {
                // `mb_strlen`, because the columns are character-typed and
                // `strlen('أحمد')` is 8 where the column counts 4 (measured in
                // Point 3.3). Refusing beats truncating: a name cut at 255 is a
                // different customer, silently.
                return null;
            }

            if ($column === 'start_date' && strtotime($value) === false) {
                // A `date` column, so an unparseable value is a 500 waiting to
                // happen. `D-31` says accept the row: drop the field, and the
                // row is flagged for it like any other gap.
                continue;
            }

            $attributes[$column] = $value;
        }

        // `customers_name_not_blank` is a CHECK. A row that would violate it is
        // a failure, not `D-31`'s incomplete — an incomplete row still saves.
        return isset($attributes['name']) ? $attributes : null;
    }
}
