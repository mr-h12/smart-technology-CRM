<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use App\Support\Settings\SettingReader;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * `system_limits`, read one key at a time, with `config/` underneath.
 *
 * Not memoised, and deliberately so — the same reasoning `AppServiceProvider`
 * records for `PermissionRepositoryInterface`: §3.12 rule 5 and `AP-08` make
 * these values changeable without a deployment, and a value cached for the life
 * of the process is a deployment wearing a different name. Point 4.2 adds a
 * cache with an explicit invalidation on write, which is a different thing from
 * an instance quietly holding an answer.
 */
final readonly class DatabaseSettingReader implements SettingReader
{
    public function __construct(
        private ConnectionInterface $connection,
        private ConfigRepository $config,
    ) {}

    public function integer(string $key): int
    {
        $stored = $this->connection->table('system_limits')
            ->where('key', $key)
            ->whereNull('deleted_at')
            ->value('value');

        // `is_numeric` and not a cast: `(int) 'half an hour'` is 0, and a lock
        // of zero minutes is an account that never locks. A value that is not a
        // number is not an answer, so the configured default stands.
        if (is_string($stored) && is_numeric($stored)) {
            return (int) $stored;
        }

        return $this->config->integer($key);
    }
}
