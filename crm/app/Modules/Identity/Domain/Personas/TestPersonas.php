<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Personas;

use App\Modules\Identity\Domain\Rbac\Role;

/**
 * One test user per role — the last item on `DEV-08`'s list, and the only one
 * that must never reach production.
 *
 * `Coding Standards §7` asks for seed data "reproducible in **non-production**
 * environments", and that word is doing real work: roles, permissions, managed
 * lists and currencies belong in production on day one, while eight fictional
 * employees do not. The seeder that carries these is the one that answers
 * `seedsTestData() === true`, which `GuardedSeeder` refuses to run in
 * production (Point 7.1).
 *
 * Eight, in `Role`'s own order, including Super Admin: `§3.12` rule 6 hides
 * that account from every user list, and hidden is not the same as absent.
 *
 * Addresses are on `example.test` — RFC 6761 reserves `.test` as a domain that
 * never resolves. A plausible company address in seed data is one mistyped
 * environment away from a password-reset mail reaching a real inbox.
 */
final class TestPersonas
{
    /** @return list<TestPersona> */
    public static function all(): array
    {
        return [
            new TestPersona(Role::SuperAdmin, 'Test Super Admin', 'super.admin@example.test'),
            new TestPersona(Role::Ceo, 'Test CEO', 'ceo@example.test'),
            new TestPersona(Role::Manager, 'Test Manager', 'manager@example.test'),
            new TestPersona(Role::TeamLeader, 'Test Team Leader', 'team.leader@example.test'),
            new TestPersona(Role::OutdoorSupervisor, 'Test Outdoor Supervisor', 'outdoor.supervisor@example.test'),
            new TestPersona(Role::OutdoorSales, 'Test Outdoor Sales', 'outdoor.sales@example.test'),
            new TestPersona(Role::IndoorSales, 'Test Indoor Sales', 'indoor.sales@example.test'),
            new TestPersona(Role::Procurement, 'Test Procurement', 'procurement@example.test'),
        ];
    }

    public static function for(Role $role): ?TestPersona
    {
        foreach (self::all() as $persona) {
            if ($persona->role() === $role) {
                return $persona;
            }
        }

        return null;
    }
}
