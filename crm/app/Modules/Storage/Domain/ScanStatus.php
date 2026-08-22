<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain;

/**
 * Where a file stands with the virus scanner (SEC-15, §17).
 *
 * The three values are the ones Point 5.1 wrote into the `files_scan_status_check`
 * constraint, and a test asserts this enum against that constraint rather than
 * against a copy of the list — two lists in two files is how they drift.
 *
 * `Pending` is the initial state and is **not** a benign one: only `Clean` is
 * servable, so a file that is never scanned is a file that is never delivered.
 * That is deliberate. The alternative — treating "not yet checked" as safe —
 * turns a scanner outage into an open door.
 */
enum ScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';

    public function isServable(): bool
    {
        return $this === self::Clean;
    }
}
