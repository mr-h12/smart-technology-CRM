<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
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
    }

    /** @var list<string> */
    private const UPDATABLE = ['name', 'email', 'role_id'];
}
