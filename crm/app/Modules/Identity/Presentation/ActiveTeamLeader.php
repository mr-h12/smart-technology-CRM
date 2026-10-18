<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Domain\Rbac\Role;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * `D-138` — the one rule for `team_leader_id` on both user requests: an active,
 * live user who holds the Team Leader role.
 *
 * ponytail: checked at save time only. A later role change or deactivation of
 * the leader leaves the column as it is; the Team scope (7.5c) reads the column,
 * and a sweep or an event is the upgrade if the owner wants it kept in step.
 */
final class ActiveTeamLeader
{
    /** `D-139`: the roles that never have a Team Leader — teams are one level deep. */
    private const LEAD_ROLES = [Role::SuperAdmin->value, Role::Ceo->value, Role::Manager->value, Role::TeamLeader->value];

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

    /**
     * `D-139`: adds `$messageKey` on `$field` when `$roleId` is one of the four
     * lead roles. The caller decides whether a leader is in play.
     */
    public static function refuseLeadRole(Validator $validator, mixed $roleId, string $field, string $messageKey): void
    {
        if (! is_string($roleId)) {
            return;
        }

        $slug = DB::table('roles')->where('id', $roleId)->whereNull('deleted_at')->value('slug');

        if (in_array($slug, self::LEAD_ROLES, true)) {
            $validator->errors()->add($field, (string) __($messageKey));
        }
    }
}
