<?php

declare(strict_types=1);

namespace App\Modules\SupplierQuotations\Infrastructure;

use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemQuantityInterface;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * {@see SupplierItemQuantityInterface} over `supplier_quotation_items` and its
 * guard table `supplier_quotation_item_consumptions`.
 *
 * The database decides both halves, as `DocumentNumberAllocator` does: the
 * guard row goes in with `ON CONFLICT DO NOTHING RETURNING id`, so a replay is
 * known from the statement's own result rather than from a read the next
 * transition could race; the balance moves with `UPDATE … RETURNING`, so the
 * value handed back is the row's, not an addition done in PHP (`DB-07`).
 * Both run in one transaction — a guard row never outlives a failed update.
 * ponytail: that atomicity has no test — the FK refuses an unknown line at the
 * insert, and a failing update on an existing line has no cheap provocation.
 */
final readonly class EloquentSupplierItemQuantity implements SupplierItemQuantityInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function consume(string $supplierQuotationItemId, string $quantity, string $idempotencyKey): string
    {
        // Boundary validation in PHP mirrors the table's `quantity > 0` CHECK,
        // so a bad quantity is refused before a transaction is opened.
        if (! is_numeric($quantity) || bccomp($quantity, '0', 4) <= 0) {
            throw new InvalidArgumentException('A consumed quantity must be positive.');
        }

        return $this->connection->transaction(function () use ($supplierQuotationItemId, $quantity, $idempotencyKey): string {
            $guarded = $this->connection->select(
                'insert into supplier_quotation_item_consumptions (id, supplier_quotation_item_id, idempotency_key, quantity, created_at) '
                .'values (?, ?, ?, ?, now()) on conflict (idempotency_key) do nothing returning id',
                [Uuid::uuid4()->toString(), $supplierQuotationItemId, $idempotencyKey, $quantity],
            );

            if ($guarded === []) {
                // A replay: the key already drew its quantity. Hand back the
                // balance as it stands.
                /** @var object{consumed_quantity: string}|null $current */
                $current = $this->connection->selectOne(
                    'select consumed_quantity from supplier_quotation_items where id = ?',
                    [$supplierQuotationItemId],
                );

                return self::balance($current);
            }

            /** @var object{consumed_quantity: string}|null $moved */
            $moved = $this->connection->selectOne(
                'update supplier_quotation_items set consumed_quantity = consumed_quantity + ? where id = ? returning consumed_quantity',
                [$quantity, $supplierQuotationItemId],
            );

            return self::balance($moved);
        });
    }

    /**
     * Unreachable while the guard's FK stands — a key can only exist for a
     * line that exists — but a `(string) null` would be a silent `''` balance,
     * the failure `DB-07` exists to prevent; refuse loudly instead.
     *
     * @param  object{consumed_quantity: string}|null  $row
     */
    private static function balance(?object $row): string
    {
        if ($row === null) {
            throw new RuntimeException('supplier_quotation_items has no row for a guarded line.');
        }

        return $row->consumed_quantity;
    }
}
