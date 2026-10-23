<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain\Contracts;

/**
 * Module 16, Point 4 (`D-153`, `J-14`) — how full the volume holding the uploaded
 * files is. Read-only.
 */
interface StorageUsageInterface
{
    /**
     * The size of the volume under the `secure_uploads` disk's root and the bytes in
     * use on it, as the operating system reports them; `null` when the root cannot
     * be measured — a caller decides nothing on a guess.
     *
     * @return array{used_bytes: int, total_bytes: int}|null
     */
    public function usage(): ?array;
}
