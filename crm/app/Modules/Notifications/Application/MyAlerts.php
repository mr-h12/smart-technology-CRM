<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Contracts\AlertInboxInterface;
use App\Modules\Notifications\Domain\UnknownAlert;

/**
 * Module 16, Point 1 (`D-153`) — what the bell does with the caller's alerts.
 * No permission: an alert is addressed to a person, who reads their own.
 */
final class MyAlerts
{
    public const PER_PAGE = 25;

    public function __construct(private readonly AlertInboxInterface $inbox) {}

    /** @return array{items: list<array{id: string, kind: string, subject_id: string|null, details: array<string, mixed>, occurrences: int, first_raised_at: string, last_raised_at: string, read: bool}>, total: int} */
    public function page(string $userId, int $page): array
    {
        return $this->inbox->page($userId, $page, self::PER_PAGE);
    }

    public function unread(string $userId): int
    {
        return $this->inbox->unread($userId);
    }

    /**
     * @return int the caller's unread count after the change
     *
     * @throws UnknownAlert when the alert is not the caller's — a 404, never a 403 (`SEC-09`)
     */
    public function markRead(string $userId, string $alertId): int
    {
        if (! $this->inbox->markRead($userId, $alertId)) {
            throw new UnknownAlert;
        }

        return $this->inbox->unread($userId);
    }

    /** @return int the caller's unread count after the change: 0 */
    public function markAllRead(string $userId): int
    {
        $this->inbox->markAllRead($userId);

        return $this->inbox->unread($userId);
    }
}
