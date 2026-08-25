<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Modules\Identity\Domain\Administration\RoleAssignmentPolicy;
use App\Modules\Identity\Domain\Rbac\Role;
use RuntimeException;
use Tests\TestCase;

/**
 * Point 5.2 — the SPA's copy of §3.11's create-user allowlist.
 *
 * `resources/js/domain/roleAssignment.ts` mirrors `RoleAssignmentPolicy` so the
 * role dropdown does not offer a choice the API will refuse. The server is the
 * authority (§3.12 rule 1, `SEC-09`) and `UserManagementTest` proves that end;
 * this asserts the two lists have not drifted apart.
 *
 * ── Why the duplication is allowed to exist ────────────────────────────────
 *
 * The alternative is a form that lets somebody pick a role and then fails, or
 * an extra endpoint whose only job is to restate a rule already in §3.11. The
 * same trade-off `RequestAuditContext` takes for the impersonation attribute
 * name — the copy is forced, so it is pinned rather than trusted.
 */
final class RoleAssignmentMirrorTest extends TestCase
{
    private const MIRROR = 'resources/js/domain/roleAssignment.ts';

    public function test_the_spa_allowlist_matches_section_three_elevens(): void
    {
        $mirrored = self::stringArray('MANAGER_MAY_CREATE');
        $documented = RoleAssignmentPolicy::MANAGER_MAY_CREATE;

        sort($mirrored);
        sort($documented);

        self::assertSame($documented, $mirrored,
            '§3.11 decides which roles a Manager may create; the dropdown must offer exactly those.');
    }

    public function test_the_spa_denylist_matches_section_three_twelve_rule_seven(): void
    {
        $mirrored = self::stringArray('FORBIDDEN_TO_MANAGER');
        $documented = RoleAssignmentPolicy::FORBIDDEN_TO_MANAGER;

        sort($mirrored);
        sort($documented);

        self::assertSame($documented, $mirrored);
    }

    public function test_every_mirrored_slug_is_a_documented_role(): void
    {
        $slugs = array_map(static fn (Role $role): string => $role->value, Role::cases());

        foreach ([...self::stringArray('MANAGER_MAY_CREATE'), ...self::stringArray('FORBIDDEN_TO_MANAGER')] as $slug) {
            self::assertContains($slug, $slugs, "'{$slug}' is not one of §3.1's eight roles.");
        }
    }

    public function test_the_unconditional_role_is_the_one_section_three_one_names(): void
    {
        $source = self::read();

        if (preg_match("/UNCONDITIONAL_ROLE = '([a-z_]+)'/", $source, $matches) !== 1) {
            throw new RuntimeException('roleAssignment.ts no longer declares UNCONDITIONAL_ROLE.');
        }

        $unconditional = array_values(array_filter(
            Role::cases(),
            static fn (Role $role): bool => $role->hasUnconditionalAccess(),
        ));

        self::assertCount(1, $unconditional, '§3.1 gives unconditional access to exactly one role.');

        $only = reset($unconditional);

        self::assertInstanceOf(Role::class, $only);
        self::assertSame($only->value, $matches[1]);
    }

    /**
     * A `readonly string[]` constant, read out of the TypeScript source.
     *
     * @return list<string>
     */
    private static function stringArray(string $name): array
    {
        $source = self::read();

        $start = strpos($source, "export const {$name}");

        if ($start === false) {
            throw new RuntimeException("roleAssignment.ts no longer exports {$name}.");
        }

        $end = strpos($source, '];', $start);

        if ($end === false) {
            throw new RuntimeException("{$name} has no closing bracket.");
        }

        $block = substr($source, $start, $end - $start);

        if (preg_match_all("/'([a-z_]+)'/", $block, $matches) === false) {
            throw new RuntimeException('Reading '.$name.' failed: '.preg_last_error_msg());
        }

        $found = $matches[1];

        self::assertNotSame([], $found, "{$name} parsed as empty, which would make this test vacuous.");

        return $found;
    }

    private static function read(): string
    {
        $contents = file_get_contents(base_path(self::MIRROR));

        if ($contents === false) {
            throw new RuntimeException('Could not read '.self::MIRROR.'.');
        }

        return $contents;
    }
}
