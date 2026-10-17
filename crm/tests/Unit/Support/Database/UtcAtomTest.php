<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Database;

use App\Support\Database\UtcAtom;
use PHPUnit\Framework\TestCase;

/**
 * Module 13, slice 7 · 7.1 — one formatter for a stored timestamp written to
 * the wire as UTC `DATE_ATOM` (`DB-08`), replacing the Reports module's four
 * inline copies.
 */
final class UtcAtomTest extends TestCase
{
    public function test_that_a_stored_timestamp_with_an_offset_is_written_as_utc_atom(): void
    {
        self::assertSame('2026-10-16T23:17:00+00:00', UtcAtom::of('2026-10-17 01:17:00+02'));
    }

    /** The app timezone is UTC (`config/app.php`), so an offset-less value is UTC. */
    public function test_that_a_stored_timestamp_without_an_offset_is_read_as_utc(): void
    {
        self::assertSame('2026-10-17T01:17:00+00:00', UtcAtom::of('2026-10-17 01:17:00'));
    }
}
