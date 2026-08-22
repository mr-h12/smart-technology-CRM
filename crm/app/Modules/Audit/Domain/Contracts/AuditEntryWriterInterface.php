<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Contracts;

use App\Modules\Audit\Domain\AuditEntry;

/**
 * The persistence port.
 *
 * Deliberately write-only: `AUD-03` and `D-30` make an audit row immutable and
 * permanent, and Point 6.2 has the database refuse anything else. An interface
 * offering `update` or `delete` would describe operations the database exists
 * to reject.
 */
interface AuditEntryWriterInterface
{
    public function write(AuditEntry $entry): void;
}
