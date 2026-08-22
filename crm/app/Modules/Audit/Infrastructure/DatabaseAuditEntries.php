<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure;

use App\Modules\Audit\Domain\AuditEntry;
use App\Modules\Audit\Domain\Contracts\AuditEntryWriterInterface;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The row, written through the caller's connection so it joins the caller's
 * transaction (`AUD-01`, `DB-11`).
 *
 * Insert and nothing else: Point 6.2 has the database refuse an `UPDATE`, a
 * `DELETE` or a `TRUNCATE` on this table with SQLSTATE `AUD03`, so any other
 * method here would describe an operation that cannot succeed.
 */
final readonly class DatabaseAuditEntries implements AuditEntryWriterInterface
{
    private const TABLE = 'audit_log';

    public function __construct(private ConnectionInterface $connection) {}

    public function write(AuditEntry $entry): void
    {
        $this->connection->table(self::TABLE)->insert([
            // D-61: a time-ordered UUID, so inserts stay sequential and the
            // index does not fragment. Str::uuid7 rather than ramsey directly —
            // the framework is a covered deptrac layer and the vendor is not.
            'id' => (string) Str::uuid7(),
            'user_id' => $entry->context->actorId,
            'event' => $entry->event->value,
            'entity_type' => $entry->entityType,
            'entity_id' => $entry->entityId,
            // json_encode once. Passing the array straight to the query builder
            // would let it encode too, and a double-encoded JSONB column is a
            // quoted string: jsonb_typeof says "string", and every later query
            // for old_values->>'x' returns nothing rather than failing loudly.
            'old_values' => self::encode($entry->oldValues),
            'new_values' => self::encode($entry->newValues),
            'ip_address' => $entry->context->ip,
            'user_agent' => $entry->userAgent(),
            'request_id' => $entry->context->requestId,
            'correlation_id' => $entry->context->correlationId,
            'created_at' => $entry->recordedAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ]);
    }

    /** @param  array<array-key, mixed>|null  $values */
    private static function encode(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        // THROW_ON_ERROR rather than a silent false: a payload that will not
        // encode — a resource, invalid UTF-8 — would otherwise be stored as the
        // string "false", permanently, in place of what actually changed.
        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
