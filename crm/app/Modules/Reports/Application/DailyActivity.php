<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Audit\Domain\Contracts\AuditEntryReaderInterface;
use App\Modules\Audit\Domain\Reading\AuditRecord;
use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationReaderInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * `D-126` RPT-DY: the author's own activity on the report's company day, and
 * RPT-WK's over its working week (`$lastDay`, inclusive).
 * The audit log is only the index (`D-129`, amended by `D-130`): it names the
 * records the author touched; each one is shown as its own page shows it,
 * read through the Deals, Quotations and Customers contracts, grouped by kind
 * with a count per kind. A record that is gone or soft-deleted is skipped.
 * Selling totals only — no cost, margin or profit (`D-126`).
 */
final readonly class DailyActivity
{
    /** Each section and the audit events that index it. */
    private const SECTIONS = [
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
        private DealFactsInterface $deals,
        private QuotationReaderInterface $quotations,
        private CustomerNamesInterface $customers,
    ) {}

    /** @return array{counts: array<string, int>, sections: array<string, list<array<string, string|int|null>>>} */
    public function of(string $authorId, string $day, ?string $lastDay = null): array
    {
        $zone = new DateTimeZone($this->today->zone());
        $from = new DateTimeImmutable($day.' 00:00:00', $zone);
        $to = new DateTimeImmutable(($lastDay ?? $day).' 00:00:00', $zone);
        $records = $this->audit->byActorBetween($authorId, $from, $to->modify('+1 day'), array_merge(...array_values(self::SECTIONS)));

        $ids = static fn (string ...$sections): array => array_values(array_unique(array_map(
            static fn (AuditRecord $r): string => $r->entityId,
            array_filter($records, static fn (AuditRecord $r): bool => in_array(self::sectionOf($r->event), $sections, true)),
        )));
        $deals = $this->deals->cardsOf($ids('deals_created', 'deal_status_moves'));
        $quotations = $this->quotations->summariesOf($ids('quotations_created', 'quotations_sent', 'customer_responses'));
        $orders = $this->quotations->purchaseOrdersOf($ids('purchase_orders'));

        $names = $this->customers->namesOf(array_values(array_unique([
            ...array_column($deals, 'customer_id'),
            ...array_map(static fn ($q): string => $q->customerId, array_values($quotations)),
            ...array_map(static fn ($o): string => $o->customerId, array_values($orders)),
        ])));

        $sections = array_fill_keys(array_keys(self::SECTIONS), []);
        foreach ($records as $r) {
            $section = self::sectionOf($r->event);
            $deal = $deals[$r->entityId] ?? null;
            $quotation = $quotations[$r->entityId] ?? null;
            $order = $orders[$r->entityId] ?? null;

            $row = match ($section) {
                'deals_created' => $deal === null ? null : [
                    'deal_id' => $r->entityId, 'code' => $deal['code'],
                    'customer_name' => $names[$deal['customer_id']] ?? '', 'status' => $deal['status'],
                ],
                'deal_status_moves' => $deal === null ? null : [
                    'deal_id' => $r->entityId, 'code' => $deal['code'], 'customer_name' => $names[$deal['customer_id']] ?? '',
                    'from_status' => $r->oldValue('status'), 'to_status' => $r->newValue('status'),
                ],
                'purchase_orders' => $order === null ? null : [
                    'purchase_order_id' => $order->id, 'po_number' => $order->poNumber, 'po_date' => $order->poDate,
                    'quotation_id' => $order->quotationId, 'quotation_code' => $order->quotationCode,
                    'customer_name' => $names[$order->customerId] ?? '', 'final_total' => $order->finalTotal, 'currency' => $order->currency,
                ],
                default => $quotation === null ? null : [
                    'quotation_id' => $quotation->id, 'code' => $quotation->code, 'version' => $quotation->version,
                    'customer_name' => $names[$quotation->customerId] ?? '',
                    ...($section === 'customer_responses' ? ['response' => strtolower(substr($r->event, strlen('QUOTATION_')))] : []),
                    'status' => $quotation->status, 'final_total' => $quotation->finalTotal, 'currency' => $quotation->currency,
                ],
            };

            if ($row !== null) {
                $sections[$section][] = $row;
            }
        }

        return ['counts' => array_map('count', $sections), 'sections' => $sections];
    }

    private static function sectionOf(string $event): string
    {
        foreach (self::SECTIONS as $section => $events) {
            if (in_array($event, $events, true)) {
                return $section;
            }
        }

        return '';
    }
}
