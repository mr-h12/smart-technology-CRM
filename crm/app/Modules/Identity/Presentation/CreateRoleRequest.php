<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/roles` — §13 screen 3's "create new roles".
 *
 * ── Shape here, meaning in the use case ────────────────────────────────────
 *
 * The same split {@see SyncRolePermissionsRequest} documents. This says a slug
 * is 2–64 lowercase characters; {@see \App\Modules\Identity\Application\RoleAdministration\CreateRole}
 * says whether that slug is free, whether the labels collide and whether the
 * grants are permitted. Uniqueness in particular is deliberately **not** a
 * `Rule::unique` here: `roles_slug_unique_alive` is partial
 * (`WHERE deleted_at IS NULL`), Laravel's rule would have to reproduce that
 * clause, and a second copy of a database predicate is how an archived role
 * ends up permanently reserving a name nobody can see.
 *
 * ── The slug pattern ───────────────────────────────────────────────────────
 *
 * `^[a-z][a-z0-9_]{1,63}$` — the shape every §3.1 slug already has, measured
 * rather than assumed: `super_admin`, `outdoor_supervisor`, `team_leader`. It
 * is a machine key that appears in `resource.action.scope` reasoning, in
 * `Role::tryFrom()` and in URLs, so a space or a capital in it is a defect that
 * only shows up somewhere far away.
 */
final class CreateRoleRequest extends FormRequest
{
    /** Matches `roles.slug`, which is `varchar(64)`. */
    public const SLUG_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/D';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'regex:'.self::SLUG_PATTERN],
            // 128 is the column. Both labels are trimmed by `prepareForValidation`
            // so " " cannot become a role nobody can read.
            'name' => ['required', 'string', 'min:2', 'max:128'],
            'name_ar' => ['nullable', 'string', 'min:2', 'max:128'],
            'description' => ['nullable', 'string', 'max:255'],
            // Optional here where the PATCH endpoint makes it `present`: a
            // create with no grants is an ordinary empty role, whereas an
            // omitted key on the sync endpoint would silently mean "revoke
            // everything".
            'permission_ids' => ['sometimes', 'array', 'max:500'],
            'permission_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'slug' => self::trimmed($this->input('slug')),
            'name' => self::trimmed($this->input('name')),
            'name_ar' => self::trimmed($this->input('name_ar')),
            'description' => self::trimmed($this->input('description')),
        ], static fn (?string $value): bool => $value !== null));
    }

    public function slug(): string
    {
        return $this->string('slug')->toString();
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }

    public function nameAr(): ?string
    {
        return self::optional($this->validated()['name_ar'] ?? null);
    }

    public function description(): ?string
    {
        return self::optional($this->validated()['description'] ?? null);
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

    /**
     * An empty string is the same as absent.
     *
     * A form that clears an optional field posts `""`, and storing that would
     * make `name_ar` a value the Arabic fallback treats as present — the role
     * would render as a blank label. {@see \App\Modules\Identity\Domain\RoleAdministration\RoleView::label()}
     * guards it too; this stops it reaching the column in the first place.
     */
    private static function optional(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function trimmed(mixed $value): ?string
    {
        return is_string($value) ? trim($value) : null;
    }
}
