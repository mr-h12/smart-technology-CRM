<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;

/**
 * One user per §3.1 role, signed in over the real `POST /auth/login`, for the
 * Module 9 endpoint tests (4.1, 4.2, 3.5) — one copy here instead of one per
 * file. The seeded `RolePermissionSeeder` grants are the matrix under test, so
 * the using class seeds it in `setUp()`.
 */
trait SignsInByRole
{
    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, string> one login per role per test — the login limit counts every call */
    private array $tokens = [];

    private function userWith(RoleName $role): User
    {
        if (isset($this->users[$role->value])) {
            return $this->users[$role->value];
        }

        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => str_replace('_', '.', $role->value).'@example.test',
            'password' => 'Passw0rd123',
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $this->users[$role->value] = $user;
    }

    /** @return array<string, string> */
    private function bearerFor(RoleName $role): array
    {
        if (! isset($this->tokens[$role->value])) {
            $token = $this->postJson('/api/v1/auth/login', [
                'email' => $this->userWith($role)->email,
                'password' => 'Passw0rd123',
            ])->assertStatus(201)->json('data.token');

            self::assertIsString($token);
            $this->tokens[$role->value] = $token;
        }

        return ['Authorization' => 'Bearer '.$this->tokens[$role->value]];
    }
}
