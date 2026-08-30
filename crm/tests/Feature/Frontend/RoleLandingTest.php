<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Modules\Identity\Domain\Rbac\Role;
use RuntimeException;
use Tests\TestCase;

/**
 * Point 5.1 — the SPA's landing screen per role, checked against §8.
 *
 * §8 lists the screens each role gets, in order. The first entry of each list
 * is the screen that role opens on, and `resources/js/router/index.ts` has to
 * agree with it — a redirect table transcribed once and then edited on its own
 * is a product decision made in a TypeScript file.
 *
 * ── Why this test exists at all ────────────────────────────────────────────
 *
 * ⚠️ Point 5.1's brief proposed a **different** table: `/deals` for Manager and
 * Team Leader, `/requests` for both Sales roles. §8 opens Manager and Team
 * Leader with *Dashboard*, Outdoor Sales with *Today's Visits*, and Indoor Sales
 * with *Customers*. `CLAUDE.md` puts the master documentation above the brief,
 * so the map follows §8 — and this reads §8 back out of the mounted
 * documentation rather than trusting the transcription, which is the only form
 * of the check that would notice the brief's table being pasted in later.
 */
final class RoleLandingTest extends TestCase
{
    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const ROUTER = 'resources/js/router/index.ts';

    /**
     * §8's screen names, as route names.
     *
     * The only hand-written step in the chain, and deliberately small: five of
     * the eight are the screen name lowercased, and the three that are not say
     * why here rather than in the router.
     *
     * @var array<string, string>
     */
    private const SCREEN_ROUTE = [
        'Dashboard' => 'dashboard',
        'Customers' => 'customers',
        'Assigned Deals' => 'assigned-deals',
        // Ordered noun-first so every visits screen sorts together once Module
        // 12 registers "Visit History" beside it.
        "Today's Visits" => 'visits-today',
        // §8: "22 administrative screens (section 13) — completely hidden".
        // A section, not a screen, so the route names the section.
        '22 administrative screens (section 13) — completely hidden' => 'admin',
    ];

    public function test_the_router_names_a_landing_route_for_every_documented_role(): void
    {
        $mapped = array_keys(self::landingRoutes());
        $documented = array_map(static fn (Role $role): string => $role->value, Role::cases());

        sort($mapped);
        sort($documented);

        self::assertSame($documented, $mapped,
            '§3.1 names eight roles; every one of them needs a screen to land on.');
    }

    public function test_every_landing_route_is_the_first_screen_section_eight_gives_that_role(): void
    {
        $landing = self::landingRoutes();
        $screens = self::firstScreenPerRole();

        self::assertNotSame([], $screens, 'The §8 scan found nothing, which means it is not scanning.');

        foreach (Role::cases() as $role) {
            $screen = $screens[$role->label()] ?? null;

            self::assertIsString($screen, "§8 has no screen list for {$role->label()}.");

            $expected = self::SCREEN_ROUTE[$screen] ?? null;

            self::assertIsString($expected,
                "§8 opens {$role->label()} with '{$screen}', which SCREEN_ROUTE does not name. "
                .'Add it here deliberately rather than changing the router to match itself.');

            self::assertSame($expected, $landing[$role->value] ?? null,
                "§8 opens {$role->label()} with '{$screen}', so the router must land them on '{$expected}'.");
        }
    }

    /**
     * Landing targets whose module has shipped its screen.
     *
     * One entry per registered landing route, added by the point that registers
     * it. `customers` — §8's first screen for Indoor Sales — arrived with
     * Module 3 Point 4.0.
     *
     * @var list<string>
     */
    private const BUILT = ['customers'];

    /**
     * The landing targets a module has **not** built yet, which must still fall back.
     *
     * ⚠️ **`customers` left this list with Module 3 Point 4.0**, which is what
     * the assertion below told whoever registered it to do — and the other half
     * of that instruction was carried out too: `guards.spec.ts` now asserts that
     * an Indoor Sales caller redirected off `/login` lands on `customers`, and
     * that a Manager still lands on the fallback.
     *
     * Everything else remains unbuilt: Dashboard is Module 14, Deals Module 5,
     * Visits Module 12, §13's administration later still. `landingRouteFor`
     * falls back rather than redirecting into a blank screen — `navigation.ts`
     * calls that defect by its name: "a dead link is not a permission problem,
     * it is a lie".
     *
     * This asserts the gap rather than hiding it. When the next module lands,
     * this test fails and its module is added to `BUILT` in the same commit.
     */
    public function test_the_landing_targets_are_honest_about_not_existing_yet(): void
    {
        $router = self::read(base_path(self::ROUTER));
        $registered = self::registeredRouteNames($router);

        foreach (self::landingRoutes() as $roleSlug => $target) {
            if (in_array($target, self::BUILT, true)) {
                // Registered on purpose, and exercised by guards.spec.ts. The
                // route still has to exist, or the redirect is a blank screen.
                self::assertContains($target, $registered,
                    "'{$target}' is listed as built but the router does not register it.");

                continue;
            }

            self::assertNotContains($target, $registered,
                "'{$target}' is now a registered route for {$roleSlug}. Add it to BUILT "
                .'and confirm the landing redirect is exercised by guards.spec.ts.');
        }

        self::assertContains('home', $registered, 'The fallback landing route must exist.');
    }

    /**
     * `LANDING_ROUTE` in `router/index.ts`, read out of the source.
     *
     * @return array<string, string> role slug => route name
     */
    private static function landingRoutes(): array
    {
        $source = self::read(base_path(self::ROUTER));

        $start = strpos($source, 'export const LANDING_ROUTE');

        if ($start === false) {
            throw new RuntimeException('router/index.ts no longer exports LANDING_ROUTE.');
        }

        $end = strpos($source, '};', $start);

        if ($end === false) {
            throw new RuntimeException('LANDING_ROUTE has no closing brace.');
        }

        $block = substr($source, $start, $end - $start);

        if (preg_match_all("/^\s*([a-z_]+):\s*'([a-z0-9-]+)'/m", $block, $matches) === false) {
            throw new RuntimeException('Reading LANDING_ROUTE failed: '.preg_last_error_msg());
        }

        /** @var array<string, string> $pairs */
        $pairs = array_combine($matches[1], $matches[2]);

        return $pairs;
    }

    /** @return list<string> */
    private static function registeredRouteNames(string $source): array
    {
        // Only the `routes` array — LANDING_ROUTE's values are quoted the same
        // way, and counting them as registered would make the assertion above
        // vacuously true.
        $start = strpos($source, 'export const routes');

        if ($start === false) {
            throw new RuntimeException('router/index.ts no longer exports routes.');
        }

        if (preg_match_all("/\bname:\s*'([a-z0-9-]+)'/", substr($source, $start), $matches) === false) {
            throw new RuntimeException('Reading route names failed: '.preg_last_error_msg());
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * §8, parsed: the role heading and the first screen on the line below it.
     *
     * @return array<string, string> role label => screen name
     */
    private static function firstScreenPerRole(): array
    {
        $document = self::read(self::MASTER_DOCUMENTATION);

        $start = strpos($document, '## 8. Screens by Role');
        $end = strpos($document, '## 9. Workflows');

        if ($start === false || $end === false) {
            throw new RuntimeException('§8 is not where this test expects it in the master documentation.');
        }

        $lines = explode("\n", substr($document, $start, $end - $start));
        $screens = [];
        $pendingRole = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, '### ')) {
                // "### Outdoor Sales (mobile + desktop)" — §8 annotates one
                // heading with the devices it serves; the role is the name.
                $pendingRole = trim(preg_replace('/\s*\(.*$/', '', substr($line, 4)) ?? '');

                continue;
            }

            if ($pendingRole === null || $line === '' || str_starts_with($line, '>')) {
                continue;
            }

            $first = explode(' · ', $line)[0];

            $screens[$pendingRole] = trim(str_replace('**', '', $first));
            $pendingRole = null;
        }

        return $screens;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Could not read {$path}.");
        }

        return $contents;
    }
}
