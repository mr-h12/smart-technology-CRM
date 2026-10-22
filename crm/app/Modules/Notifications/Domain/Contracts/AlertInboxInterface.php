<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Contracts;

/**
 * Module 16 (`D-153`) — one person's open alerts, read under the bell.
 *
 * Every method takes the reader: an alert is only ever read by the person it
 * is addressed to. Cleared alerts are history and are never returned.
 */
interface AlertInboxInterface
{
    /**
     * The reader's open alerts, newest first.
     *
     * @return array{items: list<array{id: string, kind: string, subject_id: string|null, details: array<string, mixed>, occurrences: int, first_raised_at: string, last_raised_at: string, read: bool}>, total: int}
     */
    public function page(string $userId, int $page, int $perPage): array;

    public function unread(string $userId): int;

    /** False when the alert is not the reader's (or does not exist); reading twice is reading once. */
    public function markRead(string $userId, string $alertId): bool;

    public function markAllRead(string $userId): void;
}
