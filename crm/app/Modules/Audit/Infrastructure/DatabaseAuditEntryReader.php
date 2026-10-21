<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure;

use App\Modules\Audit\Domain\Contracts\AuditEntryReaderInterface;
use App\Modules\Audit\Domain\Reading\AuditRecord;
use App\Modules\Audit\Domain\Reading\AuditRecordPage;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see AuditEntryReaderInterface} over `audit_log`.
 *
 * ── A separate class from `DatabaseAuditEntries`, deliberately ─────────────
 *
 * That class's docblock says "insert and nothing else", and the claim is worth
 * keeping true: it is the sentence that tells a reader why no `update` method
 * is missing by accident. Adding a `SELECT` to it would make the sentence
 * false and cost the next reader the reasoning. Two classes over one table is
 * the cheaper price.
 *
 * ── `created_at` comes back as a string, and is parsed as UTC ──────────────
 *
 * The column is `timestamptz` and the driver hands it over as text; `DB-08`
 * fixes storage in UTC, and `AuditEntry` normalises to UTC before the insert.
 * Constructing the `DateTimeImmutable` with an explicit UTC zone rather than
 * the ambient default is what stops a machine set to Africa/Cairo from
 * re-reading its own history three hours off — the same class of defect the
 * frontend suite's `TZ=UTC` requirement exists for.
 */
final readonly class DatabaseAuditEntryReader implements AuditEntryReaderInterface
{
    private const TABLE = 'audit_log';

    public function __construct(private ConnectionInterface $connection) {}

    public function forEntity(string $entityType, string $entityId, int $page, int $perPage): AuditRecordPage
    {
        // Counted before the page is fetched, and against the same predicate.
        // `OpenAPI §4.2`'s `total` is the size of the answer, not of the page.
        $total = $this->connection->table(self::TABLE)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->count();

        if ($total === 0) {
            return new AuditRecordPage([], 0, $page, $perPage);
        }

        /**
         * @var list<object{
         *     id: string, event: string, entity_type: string, entity_id: string,
         *     old_values: ?string, new_values: ?string, user_id: ?string,
         *     impersonated_user_id: ?string, created_at: string
         * }> $rows
         */
        $rows = $this->connection->table(self::TABLE)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            // `id` is a UUIDv7 (`D-61`) and therefore time-ordered, which makes
            // it a deterministic tie-break for two rows written inside one
            // transaction — where `created_at` can be identical to the
            // microsecond and an unordered pair would shuffle between requests.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get()
            ->all();

        $items = [];

        foreach ($rows as $row) {
            $items[] = self::hydrate($row);
        }

        return new AuditRecordPage($items, $total, $page, $perPage);
    }

    public function byActorBetween(string $actorId, DateTimeImmutable $from, DateTimeImmutable $to, array $events): array
    {
        if ($events === []) {
            return [];
        }

        /**
         * @var list<object{
         *     id: string, event: string, entity_type: string, entity_id: string,
         *     old_values: ?string, new_values: ?string, user_id: ?string,
         *     impersonated_user_id: ?string, created_at: string
         * }> $rows
         */
        $rows = $this->connection->table(self::TABLE)
            ->where('user_id', $actorId)
            ->where('created_at', '>=', $from->format(DATE_ATOM))
            ->where('created_at', '<', $to->format(DATE_ATOM))
            ->whereIn('event', $events)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * The row's shape, spelled out because the query builder answers `mixed`
     * per property and PHPStan at level 10 will not cast one to a string on
     * trust. `AuditLogMigrationTest` is what actually holds the database to
     * this shape; this annotation only stops the analyser guessing.
     *
     * @param  object{
     *     id: string, event: string, entity_type: string, entity_id: string,
     *     old_values: ?string, new_values: ?string, user_id: ?string,
     *     impersonated_user_id: ?string, created_at: string
     * }  $row
     */
    private static function hydrate(object $row): AuditRecord
    {
        return new AuditRecord(
            id: $row->id,
            event: $row->event,
            entityType: $row->entity_type,
            entityId: $row->entity_id,
            oldValues: self::decode($row->old_values),
            newValues: self::decode($row->new_values),
            actorId: $row->user_id,
            impersonatedUserId: $row->impersonated_user_id,
            // `setTimezone` and not the constructor's zone argument: the
            // driver hands back "2026-09-09 12:00:00+00", and a
            // DateTimeImmutable built from a string that carries an offset
            // ignores the zone passed beside it — the object comes back named
            // "+00:00", which is the same instant wearing a different name and
            // fails any assertion on the zone. Caught by running it.
            recordedAt: (new DateTimeImmutable($row->created_at))
                ->setTimezone(new DateTimeZone('UTC')),
        );
    }

    /** @return array<array-key, mixed>|null */
    private static function decode(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, associative: true);

        // A payload that will not decode answers null rather than throwing.
        // The writer encodes with JSON_THROW_ON_ERROR so this should be
        // unreachable — but an audit row is permanent, and a timeline that
        // 500s on one malformed row from 2026 is worse than one that shows the
        // other forty and says nothing about that field.
        return is_array($decoded) ? $decoded : null;
    }

    public function countsByDay(string $event, string $zone, ?array $days = null): array
    {
        if ($days === []) {
            return [];
        }

        $query = $this->connection->table(self::TABLE)
            ->where('event', $event)->whereNotNull('user_id')
            ->selectRaw('((created_at AT TIME ZONE ?)::date)::text AS day, user_id AS actor_id, count(*) AS entries', [$zone])
            ->groupByRaw('1, user_id')
            ->orderByRaw('1')->orderBy('user_id');

        if ($days !== null) {
            $query->whereRaw('((created_at AT TIME ZONE ?)::date)::text IN ('.implode(', ', array_fill(0, count($days), '?')).')', [$zone, ...$days]);
        }

        $rows = [];
        /** @var object{day: string, actor_id: string, entries: int} $row */
        foreach ($query->get() as $row) {
            $rows[] = ['day' => $row->day, 'actor_id' => $row->actor_id, 'entries' => (int) $row->entries];
        }

        return $rows;
    }

    public function daysWithEntriesSince(string $event, \DateTimeInterface $since, string $zone): array
    {
        /** @var list<string> $days */
        $days = $this->connection->table(self::TABLE)
            ->where('event', $event)->where('created_at', '>', $since->format(DATE_ATOM))
            ->selectRaw('DISTINCT ((created_at AT TIME ZONE ?)::date)::text AS day', [$zone])
            ->orderBy('day')
            ->pluck('day')->all();

        return $days;
    }
}
