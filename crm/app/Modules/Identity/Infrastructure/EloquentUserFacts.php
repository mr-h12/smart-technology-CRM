<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Identity\Domain\Rbac\Role;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
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

    public function activeRolesOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $roles = [];
        /** @var object{id: string, slug: string} $row */
        foreach ($this->connection->table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereNull('users.deleted_at')
            ->where('users.is_hidden', false)
            ->where('users.is_active', true)
            ->whereIn('users.id', $userIds)
            ->select('users.id', 'roles.slug')
            ->get() as $row) {
            $roles[(string) $row->id] = (string) $row->slug;
        }

        return $roles;
    }

    public function teamLeadersOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $leaders = [];
        /** @var object{id: string, leader_id: string} $row */
        foreach ($this->connection->table('users')
            ->join('users as leaders', 'leaders.id', '=', 'users.team_leader_id')
            ->whereNull('leaders.deleted_at')
            ->where('leaders.is_hidden', false)
            ->where('leaders.is_active', true)
            ->whereIn('users.id', $userIds)
            ->select('users.id', 'leaders.id as leader_id')
            ->get() as $row) {
            $leaders[(string) $row->id] = (string) $row->leader_id;
        }

        return $leaders;
    }

    public function superAdminIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereNull('users.deleted_at')
            ->where('users.is_active', true)
            ->where('roles.slug', RoleName::SuperAdmin->value)
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(static fn (mixed $id): string => is_string($id) ? $id : '')
            ->all();

        return $ids;
    }

    public function lockedUserIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->table('users')
            ->whereNull('deleted_at')
            ->where('locked_until', '>', now())
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => is_string($id) ? $id : '')
            ->all();

        return $ids;
    }

    public function holdersOf(string $resource, string $action): array
    {
        $holders = [];
        /** @var object{id: string, name: string, slug: string, scope: string, created_at: string} $row */
        foreach ($this->connection->table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereNull('users.deleted_at')
            ->whereNull('roles.deleted_at')
            ->whereNull('role_permissions.deleted_at')
            ->whereNull('permissions.deleted_at')
            ->where('users.is_hidden', false)
            ->where('users.is_active', true)
            ->where('permissions.resource', $resource)
            ->where('permissions.action', $action)
            ->orderBy('users.name')
            ->select('users.id', 'users.name', 'roles.slug', 'permissions.scope', 'users.created_at')
            ->get() as $row) {
            $id = (string) $row->id;
            $holders[$id] ??= ['name' => (string) $row->name, 'role' => (string) $row->slug, 'scopes' => [], 'created_at' => (string) $row->created_at];
            $holders[$id]['scopes'][] = (string) $row->scope;
        }

        return $holders;
    }

    public function teamMemberIdsOf(string $leaderId): array
    {
        $members = [];
        /** @var object{id: string} $row */
        foreach ($this->connection->table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereNull('users.deleted_at')
            ->where('users.is_hidden', false)
            ->where('users.team_leader_id', $leaderId)
            ->whereNotIn('roles.slug', Role::leadSlugs())
            ->select('users.id')
            ->get() as $row) {
            $members[] = (string) $row->id;
        }

        return [$leaderId, ...$members];
    }
}
