<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Modules\Storage\Domain\Contracts\StorageUsageInterface;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Module 16, Point 4 (`D-153`, `J-14`) — how full the volume holding the uploaded
 * files is. The Storage module owns the `secure_uploads` disk, so it answers:
 * the volume's size and the bytes in use on it, as the operating system reports
 * them for the disk's root. A root that cannot be measured answers `null` — the
 * caller decides nothing on a guess.
 */
final class StorageUsageTest extends TestCase
{
    public function test_that_the_volume_under_the_files_disk_is_measured(): void
    {
        $root = sys_get_temp_dir();
        Config::set('filesystems.disks.secure_uploads.root', $root);

        $usage = $this->app->make(StorageUsageInterface::class)->usage();

        self::assertNotNull($usage);
        $total = disk_total_space($root);
        $free = disk_free_space($root);
        self::assertIsFloat($total);
        self::assertIsFloat($free);
        self::assertSame((int) $total, $usage['total_bytes']);
        self::assertGreaterThan(0, $usage['total_bytes']);
        self::assertGreaterThanOrEqual(0, $usage['used_bytes']);
        self::assertLessThanOrEqual($usage['total_bytes'], $usage['used_bytes']);
        // Used is the volume's size less what is free, measured a moment apart: within a megabyte.
        self::assertEqualsWithDelta((int) $total - (int) $free, $usage['used_bytes'], 1_048_576);
    }

    public function test_that_a_root_that_does_not_exist_answers_null(): void
    {
        Config::set('filesystems.disks.secure_uploads.root', '/nonexistent/'.bin2hex(random_bytes(6)));

        self::assertNull($this->app->make(StorageUsageInterface::class)->usage());
    }
}
