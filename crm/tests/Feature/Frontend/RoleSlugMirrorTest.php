<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Presentation\CreateRoleRequest;
use RuntimeException;
use Tests\TestCase;

/**
 * Point 4.2 — the SPA's copy of the role slug's shape.
 *
 * `resources/js/domain/roleSlug.ts` mirrors
 * {@see CreateRoleRequest::SLUG_PATTERN} so §13 screen 3's create form can say
 * "that is not a slug" while it is being typed. The server is the authority —
 * `CustomRoleManagementTest` proves that end — and this asserts the two have
 * not drifted apart.
 *
 * ── Why the duplication is allowed to exist ────────────────────────────────
 *
 * The same trade-off `RoleAssignmentMirrorTest` and `PasswordPolicyMirrorTest`
 * record: without it, a typo costs a round trip that can only ever come back
 * `422`, which §6.6 asks a form to avoid. The copy is forced by the
 * interaction, so it is pinned rather than trusted.
 *
 * ── The third test is the one that is not a tautology ──────────────────────
 *
 * Comparing two strings proves the copies agree and nothing about whether
 * either is right. So the pattern is also run against §3.1's **own** eight
 * slugs, read from the enum: a pattern that rejected `outdoor_supervisor` would
 * be internally consistent and still wrong.
 */
final class RoleSlugMirrorTest extends TestCase
{
    private const MIRROR = 'resources/js/domain/roleSlug.ts';

    public function test_the_client_pattern_is_the_server_pattern(): void
    {
        // The PHP constant carries preg's delimiters and the `D` modifier;
        // JavaScript regex literals have neither, and `$` in JavaScript already
        // means what `D` forces in PCRE. The comparison is therefore of the
        // pattern body, which is the part that decides anything.
        self::assertSame(
            self::serverBody(),
            self::clientBody(),
            'roleSlug.ts and CreateRoleRequest::SLUG_PATTERN have drifted apart.',
        );
    }

    public function test_the_client_pattern_is_anchored(): void
    {
        // Without `^` and `$` the form would accept `My Role auditor` because it
        // contains something slug-shaped — and then send it.
        $body = self::clientBody();

        self::assertStringStartsWith('^', $body);
        self::assertStringEndsWith('$', $body);
    }

    public function test_the_pattern_accepts_every_slug_section_3_1_already_uses(): void
    {
        foreach (RoleName::cases() as $role) {
            self::assertSame(
                1,
                preg_match(CreateRoleRequest::SLUG_PATTERN, $role->value),
                '§3.1 already uses '.$role->value.'; a pattern that rejects it is wrong, not strict.',
            );
        }
    }

    public function test_the_pattern_rejects_what_a_slug_must_never_be(): void
    {
        foreach (['Auditor', 'audit or', 'audit-or', '9auditor', 'a', '', '_auditor', str_repeat('a', 65)] as $bad) {
            self::assertSame(
                0,
                preg_match(CreateRoleRequest::SLUG_PATTERN, $bad),
                'A slug appears in URLs and in Role::tryFrom(); '.$bad.' must not be one.',
            );
        }
    }

    private static function serverBody(): string
    {
        // `/^[a-z]...$/D` → `^[a-z]...$`
        return (string) preg_replace('#^/(.*)/D?$#s', '$1', CreateRoleRequest::SLUG_PATTERN);
    }

    private static function clientBody(): string
    {
        $source = self::read();

        if (preg_match('#export const SLUG_PATTERN = /(.+)/;#', $source, $matches) !== 1) {
            throw new RuntimeException('roleSlug.ts no longer exports SLUG_PATTERN as a regex literal.');
        }

        return $matches[1];
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
