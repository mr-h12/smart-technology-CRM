<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Domain\Rbac\Role;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * `D-138` — the one rule for `team_leader_id` on both user requests: an active,
 * live user who holds the Team Leader role.
 *
 * ponytail: checked at save time only. A later role change or deactivation of
 * the leader leaves the column as it is; the Team scope (7.5b) reads the column,
 * and a sweep or an event is the upgrade if the owner wants it kept in step.
 */
final class ActiveTeamLeader
{
    public static function rule(): Exists
    {
        return Rule::exists('users', 'id')->where(
            static fn (Builder $query) => $query
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereIn('role_id', static fn (Builder $roles) => $roles
                    ->select('id')->from('roles')->where('slug', Role::TeamLeader->value)->whereNull('deleted_at')),
        );
    }
}
