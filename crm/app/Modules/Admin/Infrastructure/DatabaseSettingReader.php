<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Domain\Contracts\SystemLimitRepositoryInterface;
use App\Support\Settings\SettingReader;
use Illuminate\Config\Repository as ConfigRepository;

/**
 * `system_limits`, read one key at a time, with `config/` underneath.
 *
 * ── Point 4.2: it reads through the repository now, not the connection ─────
 *
 * The class this replaced queried `system_limits` directly, once per call — and
 * `AuthenticateUser` calls it on **every failed login**. `PRF-08` asked for a
 * cache; the lazy way to have one is to stop owning a second copy of the query
 * and read the map `DatabaseSystemLimitRepository` already caches. One cache
 * entry now serves both §13 screen 6 and this reader, and one invalidation
 * covers both.
 *
 * **A key outside `SystemLimit` falls back to configuration**, because `all()`
 * returns the declared set. That is the same fallback a missing row already
 * had, and it is the honest one: `SystemLimit` is where a limit is declared, so
 * a limit nobody declared has no stored value by definition.
 *
 * **Configuration is the fallback, not the loser** — unchanged, and still the
 * reason this class exists. A missing row, an archived row, or a row somebody
 * typed a word into all fall back to the configured default. Failing closed on
 * a malformed value would turn a typo in a settings screen into a system that
 * locks accounts for zero minutes.
 */
final readonly class DatabaseSettingReader implements SettingReader
{
    public function __construct(
        private SystemLimitRepositoryInterface $limits,
        private ConfigRepository $config,
    ) {}

    public function integer(string $key): int
    {
        $stored = $this->limits->all()[$key]['value'] ?? null;

        // `is_numeric` and not a cast: `(int) 'half an hour'` is 0, and a lock
        // of zero minutes is an account that never locks. A value that is not a
        // number is not an answer, so the configured default stands.
        if (is_string($stored) && is_numeric($stored)) {
            return (int) $stored;
        }

        return $this->config->integer($key);
    }
}
