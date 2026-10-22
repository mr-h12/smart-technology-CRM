<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Infrastructure;

use App\Modules\Notifications\Domain\Contracts\AlertInboxInterface;
use App\Support\Database\UtcAtom;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/** Module 16, Point 1 (`D-153`) — the `alerts` table, read by the person each row is addressed to. */
final class DatabaseAlertInbox implements AlertInboxInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function page(string $userId, int $page, int $perPage): array
    {
        $open = $this->open($userId);
        $total = (clone $open)->count();

        $rows = $open
            ->orderByDesc('last_raised_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get(['id', 'kind', 'subject_id', 'details', 'occurrences', 'first_raised_at', 'last_raised_at', 'read_at']);

        $items = [];
        /** @var object{id: string, kind: string, subject_id: string|null, details: string, occurrences: int, first_raised_at: string, last_raised_at: string, read_at: string|null} $row */
        foreach ($rows as $row) {
            /** @var array<string, mixed> $details */
            $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
            $items[] = [
                'id' => $row->id,
                'kind' => $row->kind,
                'subject_id' => $row->subject_id,
                'details' => $details,
                'occurrences' => $row->occurrences,
                'first_raised_at' => UtcAtom::of($row->first_raised_at),
                'last_raised_at' => UtcAtom::of($row->last_raised_at),
                'read' => $row->read_at !== null,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    public function unread(string $userId): int
    {
        return $this->open($userId)->whereNull('read_at')->count();
    }

    public function markRead(string $userId, string $alertId): bool
    {
        $mine = $this->connection->table('alerts')->where('id', $alertId)->where('user_id', $userId);

        if (! (clone $mine)->exists()) {
            return false;
        }

        // Read once: a second call keeps the first moment.
        $mine->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return true;
    }

    public function markAllRead(string $userId): void
    {
        $this->open($userId)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
    }

    private function open(string $userId): Builder
    {
        return $this->connection->table('alerts')->where('user_id', $userId)->whereNull('cleared_at');
    }
}
