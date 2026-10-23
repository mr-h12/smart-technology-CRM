<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Notifications\Domain\Contracts\AlertLedgerInterface;
use App\Modules\Storage\Domain\Contracts\StorageUsageInterface;
use App\Support\Settings\SettingReader;

/**
 * Module 16, Point 4 — `J-14` storage_threshold_check (`D-153`).
 *
 * When the share of the files volume in use reaches `limits.storage_alert_percent`
 * (80 while unset; the owner's reading of "the limit", 2026-10-22), every active
 * Super Admin has one open alert, raised once; back under the limit, it clears.
 * An unmeasurable volume changes nothing.
 */
final readonly class CheckStorageThreshold
{
    public const KIND = 'storage_over_limit';

    private const DEFAULT_PERCENT = 80;

    public function __construct(
        private StorageUsageInterface $storage,
        private UserFactsInterface $users,
        private AlertLedgerInterface $alerts,
        private SettingReader $limits,
    ) {}

    /** @return array{raised: int, cleared: int}|null null when the volume could not be measured */
    public function run(): ?array
    {
        $usage = $this->storage->usage();
        if ($usage === null) {
            return null;
        }

        $limit = $this->limits->nullableInteger('limits.storage_alert_percent') ?? self::DEFAULT_PERCENT;

        // Integers only: reached when used / total ≥ limit / 100.
        if ($limit * $usage['total_bytes'] > $usage['used_bytes'] * 100) {
            return $this->alerts->keepOpen(self::KIND, []);
        }

        $details = [
            'used_percent' => (string) intdiv($usage['used_bytes'] * 100, $usage['total_bytes']),
            'limit_percent' => (string) $limit,
        ];

        return $this->alerts->keepOpen(self::KIND, array_map(
            static fn (string $userId): array => ['user_id' => $userId, 'subject_id' => null, 'details' => $details],
            $this->users->superAdminIds(),
        ));
    }
}
