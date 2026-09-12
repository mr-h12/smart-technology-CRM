<?php

declare(strict_types=1);

namespace App\Modules\Idempotency\Infrastructure;

use App\Modules\Idempotency\Domain\IdempotencyRecord;
use App\Modules\Idempotency\Domain\IdempotencyStoreInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

/**
 * {@see IdempotencyStoreInterface} over `idempotency_keys`.
 *
 * `claim()` is one `INSERT … ON CONFLICT DO NOTHING` against the table's
 * UNIQUE `(user_id, route, key)`: zero rows affected means somebody holds the
 * key, and the row is then read. No transaction and no row lock — the UNIQUE
 * decides the race, and a query builder `insertOrIgnore` raises nothing
 * inside a caller's transaction the way a unique violation would.
 *
 * No Eloquent model, on `EloquentQuotationDirectory`'s terms: the table has
 * no lifecycle a model would manage, and `DB-01`'s `SoftDeletes` does not
 * apply to a row that is never retired.
 */
final readonly class DatabaseIdempotencyStore implements IdempotencyStoreInterface
{
    private const TABLE = 'idempotency_keys';

    public function __construct(private ConnectionInterface $connection) {}

    public function claim(string $userId, string $route, string $key, string $requestHash): ?IdempotencyRecord
    {
        // Twice, not until it works: the only way to insert nothing and find
        // nothing is a holder releasing the key between the two statements,
        // and a second attempt then claims it. A third miss means the schema
        // is not the one this class was written against — measured: with the
        // UNIQUE narrowed on purpose, an unbounded retry looped until PHP ran
        // out of memory.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $claimed = $this->connection->table(self::TABLE)->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'user_id' => $userId,
                'route' => $route,
                'key' => $key,
                'request_hash' => $requestHash,
                'created_at' => now(),
            ]);

            if ($claimed === 1) {
                return null;
            }

            $row = $this->rowFor($userId, $route, $key)->first();

            if ($row instanceof stdClass) {
                return new IdempotencyRecord(
                    is_string($row->request_hash) ? $row->request_hash : '',
                    is_numeric($row->status) ? (int) $row->status : null,
                    is_string($row->response) ? self::body($row->response) : null,
                );
            }
        }

        throw new RuntimeException('idempotency_keys ignored an insert it does not hold a row for.');
    }

    public function complete(string $userId, string $route, string $key, int $status, array $body): void
    {
        $this->rowFor($userId, $route, $key)->update([
            'status' => $status,
            'response' => json_encode($body, JSON_THROW_ON_ERROR),
        ]);
    }

    public function release(string $userId, string $route, string $key): void
    {
        $this->rowFor($userId, $route, $key)->delete();
    }

    /**
     * `response` is jsonb; PostgreSQL hands it back as text. A body this class
     * did not write as an object is not one it will replay.
     *
     * @return array<string, mixed>|null
     */
    private static function body(string $json): ?array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function rowFor(string $userId, string $route, string $key): Builder
    {
        return $this->connection->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('route', $route)
            ->where('key', $key);
    }
}
