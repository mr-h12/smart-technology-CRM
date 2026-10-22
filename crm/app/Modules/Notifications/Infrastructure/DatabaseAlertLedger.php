<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Infrastructure;

use App\Modules\Notifications\Domain\Contracts\AlertLedgerInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/** Module 16 (`D-153`) — the `alerts` table, written by the jobs that raise and clear alerts. */
final class DatabaseAlertLedger implements AlertLedgerInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function keepOpen(string $kind, array $wanted): array
    {
        return $this->connection->transaction(function () use ($kind, $wanted): array {
            $now = now();

            /** @var array<string, object{id: string, details: string}> $open keyed by "user|subject" */
            $open = [];
            /** @var object{id: string, user_id: string, subject_id: string|null, details: string} $row */
            foreach ($this->connection->table('alerts')->where('kind', $kind)->whereNull('cleared_at')->lockForUpdate()
                ->get(['id', 'user_id', 'subject_id', 'details']) as $row) {
                $open[$row->user_id.'|'.($row->subject_id ?? '')] = $row;
            }

            $raised = 0;
            foreach ($wanted as $alert) {
                $key = $alert['user_id'].'|'.$alert['subject_id'];
                $details = json_encode($alert['details'], JSON_THROW_ON_ERROR);

                if (isset($open[$key])) {
                    // Raised once: the counter and the read state stay; only what the sentence says may change.
                    if (json_decode($open[$key]->details, true) !== $alert['details']) {
                        $this->connection->table('alerts')->where('id', $open[$key]->id)->update(['details' => $details, 'updated_at' => $now]);
                    }
                    unset($open[$key]);

                    continue;
                }

                $this->connection->table('alerts')->insert([
                    'id' => (string) Str::uuid7(),
                    'user_id' => $alert['user_id'],
                    'kind' => $kind,
                    'subject_id' => $alert['subject_id'],
                    'details' => $details,
                    'occurrences' => 1,
                    'first_raised_at' => $now,
                    'last_raised_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $raised++;
            }

            // What is left open is no longer wanted: cleared, never deleted.
            $stale = array_map(static fn (object $row): string => $row->id, array_values($open));
            if ($stale !== []) {
                $this->connection->table('alerts')->whereIn('id', $stale)->update(['cleared_at' => $now, 'updated_at' => $now]);
            }

            return ['raised' => $raised, 'cleared' => count($stale)];
        });
    }

    public function occurred(string $kind, string $subjectId, array $userIds, array $details): void
    {
        $this->connection->transaction(function () use ($kind, $subjectId, $userIds, $details): void {
            $now = now();
            $json = json_encode($details, JSON_THROW_ON_ERROR);

            foreach (array_values(array_unique($userIds)) as $userId) {
                $updated = $this->connection->table('alerts')
                    ->where('user_id', $userId)->where('kind', $kind)->where('subject_id', $subjectId)->whereNull('cleared_at')
                    ->update([
                        'occurrences' => $this->connection->raw('occurrences + 1'),
                        'details' => $json,
                        'last_raised_at' => $now,
                        'read_at' => null,
                        'updated_at' => $now,
                    ]);

                if ($updated === 0) {
                    $this->connection->table('alerts')->insert([
                        'id' => (string) Str::uuid7(),
                        'user_id' => $userId,
                        'kind' => $kind,
                        'subject_id' => $subjectId,
                        'details' => $json,
                        'occurrences' => 1,
                        'first_raised_at' => $now,
                        'last_raised_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function clearAllBut(string $kind, array $stillTrue): int
    {
        $query = $this->connection->table('alerts')->where('kind', $kind)->whereNull('cleared_at');
        if ($stillTrue !== []) {
            $query->where(static fn (Builder $q): Builder => $q->whereNull('subject_id')->orWhereNotIn('subject_id', $stillTrue));
        }

        return $query->update(['cleared_at' => now(), 'updated_at' => now()]);
    }
}
