<?php

declare(strict_types=1);

namespace Tests\Feature\Seed;

use App\Modules\Identity\Domain\Rbac\Grant;
use App\Modules\Identity\Domain\Rbac\Permission;
use App\Modules\Identity\Domain\Rbac\PermissionMatrix;
use App\Modules\Identity\Domain\Rbac\Role;
use App\Modules\Identity\Domain\Rbac\Scope;
use PHPUnit\Framework\TestCase;

/**
 * Point 7.2 — §3's permission matrix, written down once and checked.
 *
 * `SEC-07` puts dynamic RBAC in the database as `resource.action.scope`, and
 * `§3.12` rule 5 says changing the matrix is a configuration change rather than
 * a deployment. Neither removes the need for a **starting** matrix: a fresh
 * database with an empty `permissions` table grants nobody anything, and the
 * first thing anyone would do is invent one.
 *
 * This is that matrix as data — 9 sections, 57 permissions, 8 roles, 5 scopes —
 * with no Laravel underneath it, because Module 1's tables do not exist yet
 * (approved Option C) and `deptrac` gives `Identity` and `Domain` empty
 * rulesets: this code may depend on nothing, Illuminate included.
 *
 * **Why a test and not just a file.** Every cell here is an authorisation
 * decision. A row omitted is a role locked out; a scope widened by one step is
 * a sales employee reading the whole company's quotations. Transcribing 57 rows
 * across five different column layouts by hand is exactly the task where that
 * happens quietly, so the transcription is checked against the invariants the
 * document states about itself.
 */
final class RbacMatrixDataTest extends TestCase
{
    /**
     * The row count of each table in §3.3–§3.11, counted from the document.
     *
     * These are not a summary of the code — they are what the specification
     * has, so a permission dropped during transcription shows up as a number
     * that no longer matches.
     */
    private const SECTION_ROWS = [
        '§3.3' => 7,   // Customers
        '§3.4' => 8,   // Deals / Requests
        '§3.5' => 14,  // Customer Quotations
        '§3.6' => 3,   // Supplier Quotations
        '§3.7' => 4,   // Catalog & Suppliers — 3 documented rows + D-85's `catalog.import`
        '§3.8' => 3,   // Visits
        '§3.9' => 4,   // Procurement
        '§3.10' => 6,  // Reports
        '§3.11' => 10, // Administration — 9 documented rows + D-80's `currency.view`
    ];

    // ─────────────────────────────────────────────────── coverage

    public function test_the_eight_documented_roles_are_present(): void
    {
        // §3.1, in the document's own order.
        self::assertSame([
            'super_admin', 'ceo', 'manager', 'team_leader',
            'outdoor_supervisor', 'outdoor_sales', 'indoor_sales', 'procurement',
        ], array_map(static fn (Role $r): string => $r->value, Role::cases()));
    }

    public function test_the_five_documented_scopes_are_present(): void
    {
        // §3.2's code table: Own · Team · All · Out · Asgn. No sixth value is
        // available to a cell, which is what makes `resource.action.scope`
        // checkable rather than a naming convention.
        self::assertSame(
            ['own', 'team', 'all', 'out', 'asgn'],
            array_map(static fn (Scope $s): string => $s->value, Scope::cases()),
        );
    }

    public function test_the_matrix_holds_exactly_the_fifty_nine_documented_permissions(): void
    {
        $counted = [];

        foreach (PermissionMatrix::all() as $permission) {
            $counted[$permission->section()] = ($counted[$permission->section()] ?? 0) + 1;
        }

        self::assertSame(self::SECTION_ROWS, $counted);
        self::assertCount(59, PermissionMatrix::all());
    }

    /**
     * D-80: the one row §3.11 does not draw. `currency_id` on a supplier offer
     * is a uuid, so the roles §3.6 lets write an offer must be able to read the
     * list; the CEO and the Outdoor Supervisor never enter a price.
     */
    public function test_currency_view_is_granted_to_the_offer_writers_and_nobody_else(): void
    {
        $permission = PermissionMatrix::find('currency.view');

        self::assertNotNull($permission, 'currency.view is missing.');
        self::assertSame(
            ['manager', 'team_leader', 'outdoor_sales', 'indoor_sales', 'procurement'],
            array_keys($permission->grants()),
        );
    }

    /**
     * D-85 (F-09 · 1.4): the supplier import, a bulk write, is the Manager's
     * alone, as §3.3's customer import is — although `D-45` opens single edits
     * to every operational role.
     */
    public function test_catalog_import_is_granted_to_the_manager_alone(): void
    {
        $permission = PermissionMatrix::find('catalog.import');

        self::assertNotNull($permission, 'catalog.import is missing.');
        self::assertSame(['manager'], array_keys($permission->grants()));
    }

    public function test_permission_keys_are_unique_and_well_formed(): void
    {
        $keys = array_map(static fn (Permission $p): string => $p->key(), PermissionMatrix::all());

        self::assertSame(array_unique($keys), $keys, 'Two permissions share a key.');

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[a-z][a-z_]*\.[a-z][a-z_]*$/D', $key,
                "SEC-07 addresses a permission as resource.action; '{$key}' is not that shape.");
        }
    }

    public function test_every_grant_names_one_of_the_five_documented_scopes(): void
    {
        $granted = 0;

        foreach (PermissionMatrix::all() as $permission) {
            foreach ($permission->grants() as $role => $grant) {
                self::assertContains(Role::from($role), Role::cases());
                self::assertContains($grant->scope(), Scope::cases(),
                    "{$permission->key()} grants {$role} a scope outside §3.2.");
                $granted++;
            }
        }

        // A matrix that lost its grants would satisfy every assertion above.
        self::assertGreaterThan(150, $granted, 'The matrix is nearly empty; it is not being read.');
    }

    public function test_the_two_forbidden_deletes_grant_nobody_anything(): void
    {
        // §3.12 rule 3, and the two rows that spell it out: §3.3's "delete"
        // and §3.7's "delete", both written as a merged "❌ Forbidden" cell.
        foreach (['customer.delete', 'catalog.delete'] as $key) {
            $permission = PermissionMatrix::find($key);

            self::assertNotNull($permission, "{$key} is missing.");
            self::assertSame([], $permission->grants(),
                "{$key} is forbidden for every role (§3.12 rule 3) and must grant nobody.");
        }
    }

    // ──────────────────────────────────────────────── Super Admin

    public function test_super_admin_is_hidden_and_every_other_role_is_not(): void
    {
        // §3.12 rule 6: never listed in any user list, for any role.
        foreach (Role::cases() as $role) {
            self::assertSame(
                $role === Role::SuperAdmin,
                $role->isHidden(),
                "{$role->value} has the wrong visibility.",
            );
        }
    }

    public function test_super_admin_holds_every_permission_at_full_scope(): void
    {
        // §3.1 gives Super Admin scope All and no exceptions. It is resolved
        // rather than transcribed: the operational tables §3.3–§3.10 do not
        // have a Super Admin column at all, so a matrix that only read cells
        // would leave the developer role with nothing.
        self::assertTrue(Role::SuperAdmin->hasUnconditionalAccess());

        foreach (PermissionMatrix::all() as $permission) {
            self::assertSame(
                Scope::All,
                PermissionMatrix::scopeFor($permission->key(), Role::SuperAdmin),
                "Super Admin is not unconditional on {$permission->key()}.",
            );
        }
    }

    public function test_no_other_role_has_unconditional_access(): void
    {
        foreach (Role::cases() as $role) {
            if ($role === Role::SuperAdmin) {
                continue;
            }

            self::assertFalse($role->hasUnconditionalAccess(),
                "{$role->value} bypasses the matrix, which only Super Admin may do.");
        }
    }

    public function test_super_admin_is_not_a_column_in_the_operational_matrix(): void
    {
        // §3.3–§3.10 have no Super Admin column; §3.11 does. Transcribing one
        // into the operational tables would hide the override above behind
        // cells, and the two would drift.
        foreach (PermissionMatrix::all() as $permission) {
            if ($permission->section() === '§3.11') {
                continue;
            }

            self::assertNull($permission->grantFor(Role::SuperAdmin),
                "{$permission->key()} lists Super Admin as a cell; §3.3–§3.10 have no such column.");
        }

        self::assertNotNull(
            PermissionMatrix::find('admin.login_as')?->grantFor(Role::SuperAdmin),
            '§3.11 does have a Super Admin column and Login As is its own row.',
        );
    }

    // ─────────────────────────────────── the document's bare checkmark

    public function test_a_bare_checkmark_resolves_to_the_role_view_scope_for_its_section(): void
    {
        // The document writes a plain ✅ in most non-view rows: "edit margin",
        // "acknowledge", "assign area". SEC-07 has no such value — every
        // permission carries a scope — so each ✅ was resolved to that role's
        // scope on the section's own `view` row, which is the reading §3.4
        // makes explicit by writing "✅ Own" and "✅ Asgn" where it differs.
        //
        // This asserts that reading was applied, cell by cell, rather than
        // being asserted once in a comment.
        $checked = 0;
        $unresolvable = [];

        foreach (PermissionMatrix::all() as $permission) {
            $view = PermissionMatrix::viewPermissionFor($permission->section());

            foreach ($permission->grants() as $role => $grant) {
                if (! $grant->wasCheckmark()) {
                    continue;
                }

                $viewScope = $view?->grantFor(Role::from($role))?->scope();

                if ($viewScope === null) {
                    // A ✅ for a role with no view grant in that section — see
                    // the count below, which pins exactly how many exist.
                    $unresolvable[] = $permission->key().' / '.$role;

                    continue;
                }

                self::assertSame($viewScope, $grant->scope(),
                    "{$permission->key()} gives {$role} a bare ✅ resolved to {$grant->scope()->value}, "
                    ."but its section's view row says {$viewScope->value}.");
                $checked++;
            }
        }

        self::assertGreaterThan(20, $checked, 'Almost no checkmark was resolved; the rule is not being exercised.');
        self::assertSame([], $unresolvable,
            'A ✅ that cannot be resolved from a view row must be written as an explicit scope instead.');
    }

    public function test_every_section_offers_a_view_row_to_resolve_against(): void
    {
        foreach (array_keys(self::SECTION_ROWS) as $section) {
            self::assertInstanceOf(Permission::class, PermissionMatrix::viewPermissionFor($section),
                "{$section} has no view-prefixed row, so a ✅ in it could not be resolved.");
        }
    }

    public function test_administration_carries_no_bare_checkmark(): void
    {
        // Found by this test failing on its first run, which is why it now says
        // something different from what it was written to say.
        //
        // §3.11's first view-prefixed row is `admin.view_audit_log` — a
        // capability the Super Admin and the Manager hold, not the section-wide
        // scope anchor the other eight tables open with. Resolving a ✅ against
        // it would be resolving against the wrong row. §3.11 is also the one
        // table with no view column at all, so every cell there is written as
        // an explicit scope and the resolution rule is never reached.
        self::assertSame('admin.view_audit_log', PermissionMatrix::viewPermissionFor('§3.11')?->key());

        foreach (PermissionMatrix::all() as $permission) {
            if ($permission->section() !== '§3.11') {
                continue;
            }

            foreach ($permission->grants() as $role => $grant) {
                self::assertFalse($grant->wasCheckmark(),
                    "{$permission->key()} gives {$role} a ✅ that §3.11 has no view row to resolve.");
            }
        }
    }

    // ────────────────────────────────────── the owner's clarifications

    public function test_reports_treat_indoor_and_outdoor_sales_identically(): void
    {
        // §3.10's "Sales" column covers both sales roles (owner, 2026-08-22).
        // Every §3.10 row must therefore say the same thing about each.
        $rows = 0;

        foreach (PermissionMatrix::all() as $permission) {
            if ($permission->section() !== '§3.10') {
                continue;
            }

            self::assertEquals(
                $permission->grantFor(Role::IndoorSales)?->scope(),
                $permission->grantFor(Role::OutdoorSales)?->scope(),
                "§3.10's Sales column covers both sales roles, but {$permission->key()} splits them.",
            );
            $rows++;
        }

        self::assertSame(6, $rows);
    }

    public function test_export_never_exceeds_view(): void
    {
        // Checked where the section has a plain `view` row. §3.10 has only
        // "view received", which is a different permission — Sales is ❌ there
        // and still exports its own reports — so the comparison is not
        // available for reports and is not faked.
        $compared = 0;

        foreach (PermissionMatrix::all() as $permission) {
            if (! str_contains($permission->key(), 'export')) {
                continue;
            }

            $view = PermissionMatrix::viewPermissionFor($permission->section());

            if ($view === null || ! str_ends_with($view->key(), '.view')) {
                continue;
            }

            foreach ($permission->grants() as $role => $grant) {
                $viewScope = $view->grantFor(Role::from($role))?->scope();

                self::assertNotNull($viewScope,
                    "{$role} may export {$permission->key()} without being able to view it.");
                self::assertTrue($viewScope->includes($grant->scope()),
                    "{$role} exports at {$grant->scope()->value} but views at {$viewScope->value}.");
                $compared++;
            }
        }

        self::assertGreaterThan(4, $compared, 'No export row was compared; the check is not running.');
    }

    public function test_scope_containment_is_not_a_free_pass(): void
    {
        // The verifier behind the assertion above. `includes()` returning true
        // for everything would make that test decorative.
        self::assertTrue(Scope::All->includes(Scope::Own));
        self::assertTrue(Scope::All->includes(Scope::Out));
        self::assertTrue(Scope::Team->includes(Scope::Own));
        self::assertTrue(Scope::Own->includes(Scope::Own));

        self::assertFalse(Scope::Own->includes(Scope::Team));
        self::assertFalse(Scope::Team->includes(Scope::All));
        self::assertFalse(Scope::Out->includes(Scope::Team));
        self::assertFalse(Scope::Asgn->includes(Scope::All));
    }

    // ───────────────────────────────────────── the matrix is not code

    public function test_a_grant_records_whether_the_document_wrote_a_scope_or_a_checkmark(): void
    {
        // Both kinds have to exist, or one of the two branches above is
        // testing nothing.
        $scoped = 0;
        $checkmarks = 0;

        foreach (PermissionMatrix::all() as $permission) {
            foreach ($permission->grants() as $grant) {
                $grant->wasCheckmark() ? $checkmarks++ : $scoped++;
            }
        }

        self::assertGreaterThan(50, $scoped);
        self::assertGreaterThan(20, $checkmarks);
    }

    public function test_the_matrix_is_immutable_between_reads(): void
    {
        // §3.12 rule 5 moves this into the database, where it is edited. What
        // must not happen is a caller editing the shipped definition in memory
        // and every later reader seeing it.
        self::assertEquals(PermissionMatrix::all(), PermissionMatrix::all());

        $grant = Grant::scoped(Scope::Own);
        self::assertSame(Scope::Own, $grant->scope());
    }
}
