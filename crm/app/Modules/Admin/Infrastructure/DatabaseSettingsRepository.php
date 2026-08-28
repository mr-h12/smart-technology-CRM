<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The `settings` table, one row per §13 screen 4 field.
 *
 * Archived rows are skipped and never resurrected: `DB-01` soft-deletes, the
 * unique index is partial, so writing a key whose only row is archived creates
 * a new row beside it rather than reviving a value somebody withdrew.
 */
final readonly class DatabaseSettingsRepository implements SettingsRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private SettingsCache $cache,
    ) {}

    public function all(): array
    {
        // `PRF-08`. Only the stored map is cached; the assembly over
        // `SystemSetting::cases()` stays outside it, so the cached payload is a
        // plain string map whose shape `SettingsCache` can actually check.
        $stored = $this->cache->remember(SettingsCache::SETTINGS, fn (): array => $this->read());

        $settings = [];

        foreach (SystemSetting::cases() as $setting) {
            $settings[$setting->value] = $stored[$setting->value] ?? null;
        }

        return $settings;
    }

    /** @return array<string, string|null> */
    private function read(): array
    {
        $stored = [];

        foreach ($this->connection->table('settings')->whereNull('deleted_at')->get() as $row) {
            if (is_string($row->key)) {
                $stored[$row->key] = is_string($row->value) ? $row->value : null;
            }
        }

        return $stored;
    }

    public function put(SystemSetting $setting, string $value): array
    {
        $existing = $this->connection->table('settings')
            ->where('key', $setting->value)
            ->whereNull('deleted_at')
            ->first();

        if ($existing === null) {
            $id = Str::uuid7()->toString();

            $this->connection->table('settings')->insert([
                'id' => $id,
                'key' => $setting->value,
                'value' => $value,
                'value_type' => $setting->valueType(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $id, 'previous' => null];
        }

        $id = is_string($existing->id) ? $existing->id : '';

        $this->connection->table('settings')
            ->where('id', $id)
            ->update([
                'value' => $value,
                'value_type' => $setting->valueType(),
                'updated_at' => now(),
            ]);

        return ['id' => $id, 'previous' => is_string($existing->value) ? $existing->value : null];
    }
}
