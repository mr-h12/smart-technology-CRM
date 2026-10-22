<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Notifications\Domain\Contracts\AlertLedgerInterface;
use App\Modules\Quotations\Domain\Contracts\ExpiringQuotationsInterface;
use App\Support\Settings\SettingReader;

/**
 * Module 16, Point 2 — `J-16`, "quotation expiring soon" (`D-153`).
 *
 * A `sent` quotation whose `valid_until` is between the company's today and
 * today + `quotations.expiry_alert_days` (3 while unset), both included, alerts
 * the deal's owner and the owner's Team Leader (`D-138`) — each only if active.
 * The alert is raised once and cleared on the run after the quotation leaves
 * that list, whatever took it out (an answer, `J-01`, a new version, a new date,
 * a new owner).
 */
final readonly class RaiseQuotationExpiryAlerts
{
    public const KIND = 'quotation_expiring';

    private const DEFAULT_DAYS = 3;

    public function __construct(
        private ExpiringQuotationsInterface $quotations,
        private UserFactsInterface $users,
        private AlertLedgerInterface $alerts,
        private CompanyToday $today,
        private SettingReader $limits,
    ) {}

    /** @return array{raised: int, cleared: int} */
    public function run(): array
    {
        $days = $this->limits->nullableInteger('quotations.expiry_alert_days') ?? self::DEFAULT_DAYS;
        $from = $this->today->date();
        $to = (new \DateTimeImmutable($from))->modify("+{$days} days")->format('Y-m-d');

        $expiring = $this->quotations->sentExpiringBetween($from, $to);

        $owners = array_values(array_unique(array_filter(array_column($expiring, 'owner_id'), static fn (?string $id): bool => $id !== null)));
        $active = $this->users->activeRolesOf($owners);
        $leaders = $this->users->teamLeadersOf($owners);

        $wanted = [];
        foreach ($expiring as $quotation) {
            $owner = $quotation['owner_id'];
            if ($owner === null) {
                continue;
            }

            $details = ['number' => $quotation['number'], 'valid_until' => $quotation['valid_until']];
            $recipients = array_unique(array_filter([
                isset($active[$owner]) ? $owner : null,
                $leaders[$owner] ?? null,
            ], static fn (?string $id): bool => $id !== null));

            foreach ($recipients as $userId) {
                $wanted[] = ['user_id' => $userId, 'subject_id' => $quotation['quotation_id'], 'details' => $details];
            }
        }

        return $this->alerts->keepOpen(self::KIND, $wanted);
    }
}
