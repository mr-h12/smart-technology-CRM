<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

/**
 * What a use case may say about `PRF-08`'s cache: *this is now wrong*.
 *
 * ── Why the Application layer never names a cache key ──────────────────────
 *
 * `deptrac.layers.yaml` gives Application `Domain`, `Framework` and
 * `SharedContracts` and nothing else — reaching into `Infrastructure` for the
 * concrete cache is a violation, and the boundary is right: *which* key holds
 * §13 screen 4's fields is a storage detail, and a use case that knew it would
 * have to be edited the day the cache is keyed differently.
 *
 * So the two methods are named after **what changed**, not after what to
 * delete. The implementation owns the keys.
 *
 * **There is no `remember()` here on purpose.** Reading through the cache is
 * something a repository does, and a repository is Infrastructure; putting the
 * read on this interface would invite a use case to cache things itself.
 */
interface SettingsCacheInterface
{
    /** §13 screen 4's fields have changed. */
    public function forgetSettings(): void;

    /** §13 screen 6's limits have changed — including `D-75`'s row. */
    public function forgetLimits(): void;
}
