<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/v1/roles/{role}/permissions` — the desired grant set.
 *
 * ── Why `permission_ids` is `present` and not `required` ───────────────────
 *
 * `required` rejects an empty array, and an empty array is a meaningful
 * submission here: it revokes every grant the role holds. A role with nothing
 * granted is exactly what §3's `—` column describes, so refusing it would make
 * the endpoint unable to express a state the document already contains.
 * `present` keeps the key mandatory — a body with no `permission_ids` at all is
 * a malformed request rather than "revoke everything", which is the one mistake
 * that must not be silently obeyed.
 *
 * ── Existence is checked in the use case, not here ─────────────────────────
 *
 * A `Rule::exists` per entry would answer `422 validation_failed` on the array
 * index, which is the same status the use case produces — but the use case also
 * has to check §3.12 rule 3, and splitting "this id is unusable" across two
 * layers means two refusal shapes for one class of mistake. This validates the
 * *shape*; `SyncRolePermissions` validates the *meaning*.
 */
final class SyncRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Bounded so a single request cannot ask the database for an
            // unbounded `whereKey(...)`. 500 is above the 143 rows §3 produces
            // today with room for the modules still to come, and far below
            // anything that would make one PATCH a denial of service.
            'permission_ids' => ['present', 'array', 'max:500'],
            'permission_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'permission_ids.present' => (string) __('identity.role_administration.permission_ids_required'),
        ];
    }

    /** @return list<string> */
    public function permissionIds(): array
    {
        /** @var array<array-key, mixed> $ids */
        $ids = $this->validated()['permission_ids'] ?? [];

        $strings = [];

        foreach ($ids as $id) {
            if (is_string($id)) {
                $strings[] = $id;
            }
        }

        return $strings;
    }
}
