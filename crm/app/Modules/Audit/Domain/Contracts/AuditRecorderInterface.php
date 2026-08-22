<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Contracts;

use App\Modules\Audit\Domain\AuditEntry;
use App\Modules\Audit\Domain\AuditEvent;

/**
 * What every other module depends on to satisfy `AUD-01`.
 *
 * The caller supplies what only it knows — the event and the thing it happened
 * to. Actor, IP, user agent, request id, correlation id and the timestamp are
 * ambient and are filled in behind this line, because a module that had to pass
 * them would eventually pass them wrong, or not at all.
 */
interface AuditRecorderInterface
{
    /**
     * @param  array<array-key, mixed>|null  $oldValues  absent on a create
     * @param  array<array-key, mixed>|null  $newValues  absent on a delete
     */
    public function record(
        AuditEvent $event,
        string $entityType,
        string $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AuditEntry;
}
