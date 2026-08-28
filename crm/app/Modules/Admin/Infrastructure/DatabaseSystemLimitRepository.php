<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Domain\Contracts\SystemLimitRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemLimit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The `system_limits` table, one row per §13 screen 6 field.
 *
 * The shape mirrors {@see DatabaseSettingsRepository} exactly, including its
 * rule about archived rows: `DB-01` soft-deletes and the unique index is
 * partial, so writing a key whose only row is archived creates a new row beside
 * it rather than reviving a value somebody withdrew.
 *
 * The one difference is `unit`, and it is the column §13 screen 6 needs — the
 * unit comes from the enum rather than from the caller, because a limit's unit
 * is a property of the limit and not something an administrator types.
 */
final readonly class DatabaseSystemLimitRepository implements SystemLimitRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private SettingsCache $cache,
    ) {}

    public function all(): array
    {
        // `PRF-08`, and the entry `DatabaseSettingReader` shares — `D-75`'s
        // lockout is read on every failed login, which was a query per attempt.
        $stored = $this->cache->remember(SettingsCache::LIMITS, fn (): array => $this->read());

        $limits = [];

        foreach (SystemLimit::cases() as $limit) {
            $limits[$limit->value] = [
                'value' => $stored[$limit->value] ?? null,
                'unit' => $limit->unit(),
                'value_type' => $limit->valueType(),
            ];
        }

        return $limits;
    }

    /** @return array<string, string|null> */
    private function read(): array
    {
        $stored = [];

        foreach ($this->connection->table('system_limits')->whereNull('deleted_at')->get() as $row) {
            if (is_string($row->key)) {
                $stored[$row->key] = is_string($row->value) ? $row->value : null;
            }
        }

        return $stored;
    }

    public function put(SystemLimit $limit, string $value): array
    {
        $existing = $this->connection->table('system_limits')
            ->where('key', $limit->value)
            ->whereNull('deleted_at')
            ->first();

        if ($existing === null) {
            $id = Str::uuid7()->toString();

            $this->connection->table('system_limits')->insert([
                'id' => $id,
                'key' => $limit->value,
                'value' => $value,
                'value_type' => $limit->valueType(),
                'unit' => $limit->unit(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $id, 'previous' => null];
        }

        $id = is_string($existing->id) ? $existing->id : '';

        $this->connection->table('system_limits')
            ->where('id', $id)
            ->update([
                'value' => $value,
                'value_type' => $limit->valueType(),
                'unit' => $limit->unit(),
                'updated_at' => now(),
            ]);

        return ['id' => $id, 'previous' => is_string($existing->value) ? $existing->value : null];
    }
}
