<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Domain\Contracts\SettingsCacheInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * `PRF-08` — *"Caching for catalog · suppliers · permissions · settings"*, the
 * last of the four.
 *
 * ── Forever, with an explicit invalidation — and no TTL ────────────────────
 *
 * A time-to-live would be a number nobody wrote down, and this project does not
 * invent those (`SystemSettingsSeeder` refuses to seed §13's fields for the same
 * reason). Every write that can change these tables goes through
 * `UpdateSettings`, `UpdateSystemLimits` or `SystemSettingsSeeder`, and each one
 * forgets its key. The one path left uncovered is somebody editing a row
 * directly in SQL, which is not a supported way to change configuration — and a
 * TTL would only shorten that window, not close it.
 *
 * ── Why a shape check on read ──────────────────────────────────────────────
 *
 * A cache store hands back `mixed`, and `CLAUDE.md` forbids casting out of it.
 * An entry of the wrong shape is **reloaded whole** rather than filtered down to
 * the parts that look right: half a settings map is worse than none, because the
 * screen would render fields as empty that are not, and an administrator would
 * fix an emptiness that was never there.
 *
 * ── What it does not cache ─────────────────────────────────────────────────
 *
 * `currencies` and `enum_lists`. `PRF-08` does not name them; the managed-list
 * read is paginated, and caching pages is a different problem with a different
 * invalidation. Recorded in `CHECKLIST.md` rather than done on the way past.
 */
final readonly class SettingsCache implements SettingsCacheInterface
{
    /** §13 screen 4's fields. */
    public const SETTINGS = 'admin:settings';

    /** §13 screen 6's limits — and `D-75`'s row, the one with a reader on it. */
    public const LIMITS = 'admin:system_limits';

    public function __construct(private CacheRepository $cache) {}

    /**
     * The stored key/value map for $key, from cache or from $load.
     *
     * @param  callable(): array<string, string|null>  $load
     * @return array<string, string|null>
     */
    public function remember(string $key, callable $load): array
    {
        $cached = $this->cache->get($key);

        if (! is_array($cached)) {
            return $this->store($key, $load);
        }

        $narrowed = [];

        foreach ($cached as $storedKey => $value) {
            if (! is_string($storedKey) || (! is_string($value) && $value !== null)) {
                return $this->store($key, $load);
            }

            $narrowed[$storedKey] = $value;
        }

        return $narrowed;
    }

    public function forgetSettings(): void
    {
        $this->cache->forget(self::SETTINGS);
    }

    public function forgetLimits(): void
    {
        $this->cache->forget(self::LIMITS);
    }

    /**
     * @param  callable(): array<string, string|null>  $load
     * @return array<string, string|null>
     */
    private function store(string $key, callable $load): array
    {
        $fresh = $load();

        $this->cache->forever($key, $fresh);

        return $fresh;
    }
}
