<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Audit\Domain\Contracts\AuditEntryReaderInterface;
use App\Modules\Audit\Domain\Reading\AuditRecord;
use DateTimeImmutable;
use DateTimeZone;

/**
 * `D-126` RPT-DY: the author's own activity on the report's company day, as
 * counts and as a list, read from the audit log (`D-129`) — the one record of
 * who sent a quotation, moved a deal or recorded a customer response.
 */
final readonly class DailyActivity
{
    /** Each count and the audit events it counts. */
    private const COUNTS = [
        'deals_created' => ['DEAL_CREATED'],
        'deal_status_moves' => ['DEAL_STATUS_CHANGED'],
        'quotations_created' => ['QUOTATION_CREATED'],
        'quotations_sent' => ['QUOTATION_SENT'],
        'customer_responses' => ['QUOTATION_ACCEPTED', 'QUOTATION_REJECTED', 'QUOTATION_PARTIAL', 'QUOTATION_COUNTERED'],
        'purchase_orders' => ['PURCHASE_ORDER_CREATED'],
    ];

    public function __construct(
        private AuditEntryReaderInterface $audit,
        private CompanyToday $today,
    ) {}

    /** @return array{counts: array<string, int>, items: list<array{event: string, entity_type: string, entity_id: string, occurred_at: string}>} */
    public function of(string $authorId, string $day): array
    {
        $from = new DateTimeImmutable($day.' 00:00:00', new DateTimeZone($this->today->zone()));
        $records = $this->audit->byActorBetween($authorId, $from, $from->modify('+1 day'), array_merge(...array_values(self::COUNTS)));

        $counts = [];
        foreach (self::COUNTS as $key => $events) {
            $counts[$key] = count(array_filter($records, static fn (AuditRecord $r): bool => in_array($r->event, $events, true)));
        }

        return [
            'counts' => $counts,
            'items' => array_map(static fn (AuditRecord $r): array => [
                'event' => $r->event,
                'entity_type' => $r->entityType,
                'entity_id' => $r->entityId,
                'occurred_at' => $r->recordedAt->format(DATE_ATOM),
            ], $records),
        ];
    }
}
