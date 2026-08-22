<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Personas;

use App\Modules\Identity\Domain\Rbac\Role;

/**
 * One of `DEV-08`'s test users: who they are and which role they hold.
 *
 * **No password.** `SEC-17` keeps secrets out of code, and a literal here would
 * be a working credential committed to the repository — worse than an ordinary
 * one, because it would be identical on every checkout and every developer
 * machine. The seeder reads `SEED_TEST_USER_PASSWORD` from the environment and
 * checks it against `PasswordPolicy` before use.
 *
 * The name is the role rather than an invented person. Seed data that looks
 * like a real employee is seed data somebody eventually emails.
 */
final readonly class TestPersona
{
    public function __construct(
        private Role $role,
        private string $name,
        private string $email,
    ) {}

    public function role(): Role
    {
        return $this->role;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Always on `.test`, which RFC 6761 reserves as never-resolvable. */
    public function email(): string
    {
        return $this->email;
    }
}
