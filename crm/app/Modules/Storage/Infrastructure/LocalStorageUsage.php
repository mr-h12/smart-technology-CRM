<?php

declare(strict_types=1);

namespace App\Modules\Storage\Infrastructure;

use App\Modules\Storage\Domain\Contracts\StorageUsageInterface;

/**
 * Module 16 · 4 (`D-153`) — the `secure_uploads` disk's volume, measured where its
 * root is (`STORAGE_PATH`, the `crm-storage` volume in docker-compose.yml).
 */
final class LocalStorageUsage implements StorageUsageInterface
{
    public function usage(): ?array
    {
        $root = config('filesystems.disks.secure_uploads.root');

        if (! is_string($root) || ! is_dir($root)) {
            return null;
        }

        $total = @disk_total_space($root);
        $free = @disk_free_space($root);

        if (! is_float($total) || ! is_float($free) || $total <= 0) {
            return null;
        }

        return ['used_bytes' => max(0, (int) $total - (int) $free), 'total_bytes' => (int) $total];
    }
}
