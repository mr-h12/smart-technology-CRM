<?php

declare(strict_types=1);

namespace App\Support\Search;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * `D-48`'s first driver — "first implementation: `PostgresSearchDriver`
 * (ILIKE)", replaced by Meilisearch in Module 15 without a caller changing.
 *
 * ── Every character is a character ─────────────────────────────────────────
 *
 * `%` and `_` are ILIKE wildcards and `\` escapes them. A person searching for
 * `50%` is not writing a pattern, and passing it through unescaped answers a
 * different question in silence — which is precisely what §6.2 forbids with
 * "do not expose a database-specific search syntax". The escaping below is the
 * load-bearing line in this class, and `PostgresSearchDriverTest` breaks it on
 * purpose to prove the tests can see it.
 *
 * ── ponytail: a hard cap, no pagination ────────────────────────────────────
 *
 * A one-letter query matches most of the table, and `where id in (…)` with tens
 * of thousands of ids is a query that falls over. So the driver takes the first
 * {@see self::MAX_RESULTS} and says so. **The ceiling:** a search whose matches
 * exceed the cap silently shows a truncated set with no "more results" signal.
 * **The upgrade path:** Meilisearch paginates natively, so Module 15 replaces
 * this with a real offset/limit rather than raising the number here. Until then
 * this is the same behaviour Meilisearch's own default gives.
 *
 * ── No relevance, and none pretended ───────────────────────────────────────
 *
 * ILIKE has no notion of a better match, so the truncation is ordered by the
 * index's first searchable column — stable and alphabetical, rather than
 * whatever the planner returns. Ordering the *page* is the caller's job (§6.2's
 * `sort`), and this driver never claims to have ranked anything.
 */
final readonly class PostgresSearchDriver implements SearchService
{
    /** @see the ponytail note above — a ceiling, not a page size. */
    public const MAX_RESULTS = 500;

    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @param  array<string, string|int|bool|null>  $filters
     * @return list<string>
     */
    public function search(SearchIndex $index, string $query, array $filters = []): array
    {
        $words = trim($query);

        if ($words === '') {
            // Answering this would be a silent wrong answer either way: every
            // row, or none of them. The caller decides what an empty box means.
            throw new InvalidArgumentException('A search query cannot be blank.');
        }

        $allowed = $index->filterable();
        $bindings = ['%'.self::escaped($words).'%'];
        $columns = $index->columns();

        $matches = implode(
            ' or ',
            // `ESCAPE '\'` is stated rather than left to the default: the
            // default *is* backslash on PostgreSQL, and stating it means a
            // reader does not have to know that to see the escaping is real.
            array_map(static fn (string $c): string => "{$c} ilike ? escape '\\'", $columns),
        );

        $conditions = '';

        foreach ($filters as $column => $value) {
            if (! in_array($column, $allowed, true)) {
                throw new InvalidArgumentException("`{$column}` is not a filterable field of this index.");
            }

            // The key is now one of a fixed set of literals; the value is bound.
            $conditions .= " and {$column} ".($value === null ? 'is null' : '= ?');

            if ($value !== null) {
                $bindings[] = $value;
            }
        }

        $rows = $this->connection->select(
            'select id from '.$index->table()
            // `DB-01`: a soft-deleted row is gone as far as every read is
            // concerned, and search is a read.
            ." where deleted_at is null and ({$matches}){$conditions}"
            .' order by '.$columns[0]
            .' limit '.self::MAX_RESULTS,
            $bindings,
        );

        $ids = [];

        // A loop rather than `array_map`: `select()` is typed as `array`, so a
        // mapped result is `array<string>` and this method promises a `list`.
        // Appending in order is the shape the promise describes.
        foreach ($rows as $row) {
            $ids[] = self::identifier($row);
        }

        return $ids;
    }

    /**
     * The three characters ILIKE reads as instructions.
     *
     * Backslash first, or the escapes added for `%` and `_` would themselves be
     * escaped a second time.
     */
    private static function escaped(string $words): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $words);
    }

    /**
     * `select` answers with untyped row objects; this narrows one with an
     * assertion rather than a cast, and throws on the branch that cannot happen
     * — the column is the table's non-null primary key.
     */
    private static function identifier(mixed $row): string
    {
        $id = is_object($row) ? $row->id ?? null : null;

        if (! is_string($id)) {
            throw new InvalidArgumentException('A search index answered with a row that has no identifier.');
        }

        return $id;
    }
}
