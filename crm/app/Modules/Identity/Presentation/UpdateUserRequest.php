<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/v1/users/{user}` — a partial update, so every field is
 * `sometimes`.
 *
 * `password` is **not** here. §9 Flow 0 gives the owner of an account their own
 * endpoint for that, and an administrator setting somebody else's password is a
 * separate documented action with a separate audit event — not a field on a
 * profile form.
 */
final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var mixed $userId */
        $userId = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')
                    ->whereNull('deleted_at')
                    // Ignoring self, or every PATCH that resubmits the current
                    // address fails on a rule about a row that is this row.
                    ->ignore(is_string($userId) ? $userId : null),
            ],
            'role_id' => [
                'sometimes', 'required', 'uuid',
                Rule::exists('roles', 'id')->whereNull('deleted_at'),
            ],
            // `D-107` ruling (2) — optional, so `null` (or `""`, which
            // ConvertEmptyStringsToNull turns into it) clears the title.
            'job_title_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'job_title_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            // `D-138` — null clears it. Never the user themself: only a Team Leader
            // could be their own leader, and `D-139` gives a Team Leader none.
            'team_leader_id' => ['sometimes', 'nullable', 'uuid', ActiveTeamLeader::rule()],
        ];
    }

    /**
     * A `PATCH` with none of the three fields in it is a malformed request,
     * not a no-op.
     *
     * Without this the endpoint answers 200 with the unchanged resource and the
     * client reads it as "saved" — the one failure mode a validator can catch
     * and a controller cannot.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (self::UPDATABLE as $field) {
                if ($this->has($field)) {
                    return;
                }
            }

            $validator->errors()->add(
                'payload',
                (string) __('identity.administration.no_fields_submitted'),
            );
        });

        $validator->after(function (Validator $validator): void {
            $this->refuseLeaderOnLeadRole($validator);
        });
    }

    /**
     * `D-139` — the role and the leader as they will stand after this PATCH
     * (a key that is not sent keeps its stored value) must not be a lead role
     * with a leader. The field named is the one the caller just sent.
     */
    private function refuseLeaderOnLeadRole(Validator $validator): void
    {
        $userId = $this->route('user');

        if (
            ! is_string($userId)
            || (! $this->has('role_id') && ! $this->has('team_leader_id'))
            || $validator->errors()->hasAny(['role_id', 'team_leader_id'])
        ) {
            return;
        }

        $stored = DB::table('users')->where('id', $userId)->where('is_hidden', false)->whereNull('deleted_at')->first(['role_id', 'team_leader_id']);

        // Not there, or hidden (§3.12 rule 6): the use case answers 404 for both.
        if ($stored === null) {
            return;
        }

        $leaderId = $this->has('team_leader_id') ? $this->input('team_leader_id') : $stored->team_leader_id;

        if ($leaderId === null) {
            return;
        }

        $roleId = $this->has('role_id') ? $this->input('role_id') : $stored->role_id;

        if ($this->input('team_leader_id') !== null) {
            ActiveTeamLeader::refuseLeadRole($validator, $roleId, 'team_leader_id', 'identity.administration.team_leader_not_allowed_for_role');
        } else {
            ActiveTeamLeader::refuseLeadRole($validator, $roleId, 'role_id', 'identity.administration.role_change_needs_team_leader_cleared');
        }
    }

    /** @var list<string> */
    private const UPDATABLE = ['name', 'email', 'role_id', 'job_title_en', 'job_title_ar', 'team_leader_id'];
}
