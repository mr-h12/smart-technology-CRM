<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\AuditEntry;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditContextResolverInterface;
use App\Modules\Audit\Domain\Contracts\AuditEntryWriterInterface;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

/**
 * `AUD-01` — the one place a mutation becomes a record.
 *
 * Two destinations, and the order between them matters. The **row** goes first,
 * inside whatever transaction the caller opened (`DB-11`): if the business
 * operation rolls back, so does its audit row, because nothing happened. The
 * **log line** follows, and it is *not* transactional — a rolled-back operation
 * still leaves one. That is a known asymmetry rather than an oversight: `AUD-05`
 * asks for structured logs so failures can be traced, and an attempt that was
 * rolled back is exactly the kind of thing worth tracing.
 */
final readonly class AuditRecorder implements AuditRecorderInterface
{
    /** `AUD-05`: a channel of its own, so the audit stream stays JSON and separable. */
    public const CHANNEL = 'audit';

    public function __construct(
        private AuditContextResolverInterface $context,
        private AuditEntryWriterInterface $entries,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $oldValues
     * @param  array<array-key, mixed>|null  $newValues
     */
    public function record(
        AuditEvent $event,
        string $entityType,
        string $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AuditEntry {
        $entry = new AuditEntry(
            $event,
            $entityType,
            $entityId,
            $oldValues,
            $newValues,
            $this->context->current(),
            // DB-08. Taken here rather than in the database so the row and the
            // log line carry the same instant; `now()` in SQL would give the
            // statement's time, which drifts from the log by the round trip.
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $this->entries->write($entry);

        Log::channel(self::CHANNEL)->info('audit', $entry->toLogContext());

        return $entry;
    }
}
