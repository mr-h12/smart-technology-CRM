<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see UserFactsInterface} over `users.name` — one `WHERE id IN` through the
 * query builder, on {@see \App\Modules\Deals\Infrastructure\EloquentDealFacts}'s
 * shape. `is_hidden = false` is §3.1 spelled out where {@see EloquentUserDirectory}
 * would otherwise do it.
 */
final readonly class EloquentUserFacts implements UserFactsInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function namesOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $names = [];
        /** @var object{id: string, name: string} $row */
        foreach ($this->connection->table('users')
            ->whereNull('deleted_at')
            ->where('is_hidden', false)
            ->whereIn('id', $userIds)
            ->select('id', 'name')
            ->get() as $row) {
            $names[(string) $row->id] = (string) $row->name;
        }

        return $names;
    }

    public function jobTitlesOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $titles = [];
        /** @var object{id: string, job_title_en: string|null, job_title_ar: string|null} $row */
        foreach ($this->connection->table('users')
            ->whereNull('deleted_at')
            ->where('is_hidden', false)
            ->whereIn('id', $userIds)
            ->select('id', 'job_title_en', 'job_title_ar')
            ->get() as $row) {
            $titles[$row->id] = ['en' => $row->job_title_en, 'ar' => $row->job_title_ar];
        }

        return $titles;
    }
}
