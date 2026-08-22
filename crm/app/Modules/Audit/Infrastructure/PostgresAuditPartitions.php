<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure;

use App\Modules\Audit\Domain\AuditMonth;
use App\Modules\Audit\Domain\Contracts\AuditPartitionsInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * The DDL half of `J-15`.
 *
 * Everything here is PostgreSQL-specific and says so by living in
 * Infrastructure. `§14.2` fixes this project on PostgreSQL and `EnvironmentTest`
 * asserts the driver, so there is no second grammar to write for.
 */
final readonly class PostgresAuditPartitions implements AuditPartitionsInterface
{
    private const PARENT = 'audit_log';

    private const DEFAULT_PARTITION = 'audit_log_default';

    /** The names Point 6.2's migration created; repeated here, not invented. */
    private const GUARD = 'audit_log_no_truncate';

    private const GUARD_FUNCTION = 'audit_log_is_append_only';

    public function __construct(private ConnectionInterface $connection) {}

    /** @return list<string> */
    public function names(): array
    {
        /** @var list<object{relname: string}> $rows */
        $rows = $this->connection->select(
            'select child.relname
             from pg_inherits
             join pg_class parent on parent.oid = pg_inherits.inhparent
             join pg_class child on child.oid = pg_inherits.inhrelid
             where parent.relname = ?
             order by child.relname',
            [self::PARENT],
        );

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }

    public function create(AuditMonth $month): void
    {
        // The bounds arrive already carrying their offset, from AuditMonth. A
        // literal without one is read in the session's TimeZone, which moves
        // every boundary by however far the connection sits from UTC — and on
        // a UTC connection looks identical, which is why that defect survives
        // review and not measurement.
        //
        // Identifiers are interpolated because PostgreSQL takes no parameter in
        // a DDL identifier position. They are safe to interpolate because none
        // of them comes from a user: the partition name is derived from a
        // month, and the bounds are formatted timestamps.
        $this->connection->statement(sprintf(
            'CREATE TABLE %s PARTITION OF %s FOR VALUES FROM (\'%s\') TO (\'%s\')',
            $month->partition,
            self::PARENT,
            $month->lowerBound(),
            $month->upperBound(),
        ));
    }

    /** @return list<string> */
    public function unguarded(): array
    {
        /** @var list<object{relname: string}> $rows */
        $rows = $this->connection->select(
            'select child.relname
             from pg_inherits
             join pg_class parent on parent.oid = pg_inherits.inhparent
             join pg_class child on child.oid = pg_inherits.inhrelid
             where parent.relname = ?
               and not exists (
                   select 1 from pg_trigger t
                   where t.tgrelid = child.oid
                     and t.tgname = ?
                     and not t.tgisinternal
               )
             order by child.relname',
            [self::PARENT, self::GUARD],
        );

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }

    public function armTruncateGuard(string $partition): void
    {
        $this->connection->statement(sprintf(
            'CREATE TRIGGER %s BEFORE TRUNCATE ON %s
             FOR EACH STATEMENT EXECUTE FUNCTION %s()',
            self::GUARD,
            $partition,
            self::GUARD_FUNCTION,
        ));
    }

    public function strayRowsWithin(AuditMonth $month): int
    {
        // Read from the default partition by name rather than from the parent:
        // asking the parent would also count rows already filed correctly in a
        // sibling, and the question is specifically what is stranded.
        return (int) $this->connection->table(self::DEFAULT_PARTITION)
            ->where('created_at', '>=', $month->lowerBound())
            ->where('created_at', '<', $month->upperBound())
            ->count();
    }

    public function strayRowCount(): int
    {
        return (int) $this->connection->table(self::DEFAULT_PARTITION)->count();
    }
}
