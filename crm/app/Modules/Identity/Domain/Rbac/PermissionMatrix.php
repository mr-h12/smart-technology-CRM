<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

/**
 * §3.3–§3.11's permission matrix: 9 sections, 57 rows, 8 roles, 5 scopes.
 *
 * `§3.12` rule 5 puts this in the database, where changing it is configuration
 * rather than a deployment. That does not remove the need for a starting
 * matrix — an empty `permissions` table grants nobody anything, and whoever
 * filled it in first would be inventing the company's authorisation model at a
 * keyboard. This is the documented one, transcribed once.
 *
 * It is deliberately plain PHP. `deptrac` gives both `Identity` and `Domain`
 * empty rulesets, so nothing here may reach Illuminate; and Module 1's
 * `roles`, `permissions` and `role_permissions` tables do not exist yet
 * (approved Option C, 2026-08-22), so the definition has to stand on its own
 * until a seeder can carry it into them.
 *
 * **Two things this file does not decide.** Super Admin is not a column in
 * §3.3–§3.10 and is not transcribed into them — §3.1 gives it scope All
 * unconditionally, and `scopeFor()` answers for it directly, so the override
 * lives in one place instead of in 57 cells. And a bare ✅ in the document is
 * recorded as `Grant::checkmark()` with the scope it resolves to, so
 * `RbacMatrixDataTest` can check that resolution against the section's own
 * view row rather than taking it on trust.
 *
 * Five different column layouts appear across the nine tables. Each block below
 * names the one it was read from.
 */
final class PermissionMatrix
{
    /** @var list<Permission>|null */
    private static ?array $cache = null;

    /** @return list<Permission> */
    public static function all(): array
    {
        return self::$cache ??= self::define();
    }

    public static function find(string $key): ?Permission
    {
        foreach (self::all() as $permission) {
            if ($permission->key() === $key) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * The scope a role holds on a permission, or null if it holds none.
     *
     * Super Admin is answered before the cells are consulted (§3.1).
     */
    public static function scopeFor(string $key, Role $role): ?Scope
    {
        if ($role->hasUnconditionalAccess()) {
            return self::find($key) instanceof Permission ? Scope::All : null;
        }

        return self::find($key)?->grantFor($role)?->scope();
    }

    /**
     * The `view` row of a section — the first permission in it whose action
     * begins with `view`, which is the row the document prints first in every
     * table that has one.
     *
     * This is what a bare ✅ resolves against. §3.11 has no such row, and that
     * is asserted rather than assumed.
     */
    public static function viewPermissionFor(string $section): ?Permission
    {
        foreach (self::all() as $permission) {
            if ($permission->section() === $section && str_starts_with($permission->action(), 'view')) {
                return $permission;
            }
        }

        return null;
    }

    /** @return list<Permission> */
    private static function define(): array
    {
        return [
            /**
             * Customers. `delete` is a merged "❌ Forbidden for every role" cell
             * (§3.12 rule 3): the row exists so that nobody re-adds it, and grants nobody.
             */
            new Permission('customer', 'view', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
                Role::Procurement->value => Grant::scoped(Scope::Asgn),
                Role::Ceo->value => Grant::scoped(Scope::All),
            ], '§3.3'),
            new Permission('customer', 'create', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::All),
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.3'),
            new Permission('customer', 'edit', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.3'),
            new Permission('customer', 'assign', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
            ], '§3.3'),
            new Permission('customer', 'archive', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
            ], '§3.3'),
            new Permission('customer', 'import', [
                Role::Manager->value => Grant::scoped(Scope::All),
            ], '§3.3'),
            new Permission('customer', 'delete', [], '§3.3'),

            /**
             * Deals / Requests. `mark_delivery_complete` is the row where the document
             * writes the scope beside the tick — "✅ Own", "✅ Asgn" — which is what fixes
             * the reading of a bare ✅ everywhere else. Module 11 expects four roles to be
             * able to confirm delivery, and four is what this grants.
             */
            new Permission('deal', 'view', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
                Role::Procurement->value => Grant::scoped(Scope::Asgn),
                Role::Ceo->value => Grant::scoped(Scope::All),
            ], '§3.4'),
            new Permission('deal', 'create', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::All),
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.4'),
            new Permission('deal', 'edit', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
                Role::Procurement->value => Grant::scoped(Scope::Asgn),
            ], '§3.4'),
            new Permission('deal', 'approve', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
            ], '§3.4'),
            new Permission('deal', 'assign_owner', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
            ], '§3.4'),
            new Permission('deal', 'change_status', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
                Role::Procurement->value => Grant::scoped(Scope::Asgn),
            ], '§3.4'),
            new Permission('deal', 'mark_delivery_complete', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
                Role::Procurement->value => Grant::scoped(Scope::Asgn),
            ], '§3.4'),
            new Permission('deal', 'view_timeline', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
                Role::Procurement->value => Grant::scoped(Scope::Asgn),
                Role::Ceo->value => Grant::scoped(Scope::All),
            ], '§3.4'),

            /**
             * Customer Quotations. `edit` reads "Own (Draft)" in the document: Draft is a
             * state condition on the row, not a sixth scope, and Module 7 enforces it.
             * The CEO exports an existing PDF but has no `generate_pdf` grant — read-only,
             * as the note under the table says.
             */
            new Permission('quotation', 'view', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
                Role::Procurement->value => Grant::scoped(Scope::Asgn),
                Role::Ceo->value => Grant::scoped(Scope::All),
            ], '§3.5'),
            new Permission('quotation', 'create', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.5'),
            new Permission('quotation', 'edit', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.5'),
            new Permission('quotation', 'view_cost_and_margin', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::OutdoorSales->value => Grant::checkmark(Scope::Own),
                Role::IndoorSales->value => Grant::checkmark(Scope::Own),
                Role::Procurement->value => Grant::checkmark(Scope::Asgn),
                Role::Ceo->value => Grant::checkmark(Scope::All),
            ], '§3.5'),
            new Permission('quotation', 'edit_margin', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::OutdoorSales->value => Grant::checkmark(Scope::Own),
                Role::IndoorSales->value => Grant::checkmark(Scope::Own),
            ], '§3.5'),
            new Permission('quotation', 'edit_tax', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::OutdoorSales->value => Grant::checkmark(Scope::Own),
                Role::IndoorSales->value => Grant::checkmark(Scope::Own),
            ], '§3.5'),
            new Permission('quotation', 'submit_for_approval', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.5'),
            new Permission('quotation', 'approve', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
            ], '§3.5'),
            new Permission('quotation', 'return_with_note', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
            ], '§3.5'),
            new Permission('quotation', 'send_to_customer', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.5'),
            new Permission('quotation', 'generate_pdf', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
                Role::Procurement->value => Grant::scoped(Scope::Asgn),
            ], '§3.5'),
            new Permission('quotation', 'export_pdf', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
                Role::Procurement->value => Grant::scoped(Scope::Asgn),
                Role::Ceo->value => Grant::checkmark(Scope::All),
            ], '§3.5'),
            new Permission('quotation', 'record_customer_response', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.5'),
            new Permission('quotation', 'delete', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.5'),

            /**
             * Supplier Quotations — a shared screen, so every view grant is All rather
             * than being restricted by ownership.
             */
            new Permission('supplier_quotation', 'view', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::All),
                Role::OutdoorSales->value => Grant::scoped(Scope::All),
                Role::IndoorSales->value => Grant::scoped(Scope::All),
                Role::Procurement->value => Grant::scoped(Scope::All),
                Role::Ceo->value => Grant::scoped(Scope::All),
            ], '§3.6'),
            new Permission('supplier_quotation', 'create', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::All),
                Role::OutdoorSales->value => Grant::checkmark(Scope::All),
                Role::IndoorSales->value => Grant::checkmark(Scope::All),
                Role::Procurement->value => Grant::checkmark(Scope::All),
            ], '§3.6'),
            new Permission('supplier_quotation', 'upload_attachment', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::All),
                Role::OutdoorSales->value => Grant::checkmark(Scope::All),
                Role::IndoorSales->value => Grant::checkmark(Scope::All),
                Role::Procurement->value => Grant::checkmark(Scope::All),
            ], '§3.6'),

            /**
             * Catalog & Suppliers. Two columns only: "All operational roles" and CEO. The
             * first is Role::operational(); the CEO's ✅ is annotated "read-only", which is
             * the absence of the `manage` grant rather than a scope.
             */
            new Permission('catalog', 'view', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::All),
                Role::OutdoorSupervisor->value => Grant::checkmark(Scope::All),
                Role::OutdoorSales->value => Grant::checkmark(Scope::All),
                Role::IndoorSales->value => Grant::checkmark(Scope::All),
                Role::Procurement->value => Grant::checkmark(Scope::All),
                Role::Ceo->value => Grant::checkmark(Scope::All),
            ], '§3.7'),
            new Permission('catalog', 'manage', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::All),
                Role::OutdoorSupervisor->value => Grant::checkmark(Scope::All),
                Role::OutdoorSales->value => Grant::checkmark(Scope::All),
                Role::IndoorSales->value => Grant::checkmark(Scope::All),
                Role::Procurement->value => Grant::checkmark(Scope::All),
            ], '§3.7'),
            new Permission('catalog', 'delete', [], '§3.7'),

            /**
             * Visits. "Other roles" — Indoor Sales, Procurement, CEO — hold nothing here.
             */
            new Permission('visit', 'view', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.8'),
            new Permission('visit', 'create', [
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.8'),
            new Permission('visit', 'assign_area', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::checkmark(Scope::Out),
            ], '§3.8'),

            /**
             * Procurement. "Others" on the profit row is Out.Sup, Out.Sales and Indoor,
             * the three roles the table does not give a column of their own.
             */
            new Permission('procurement', 'view_negotiation_log', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::Procurement->value => Grant::scoped(Scope::All),
                Role::Ceo->value => Grant::scoped(Scope::All),
            ], '§3.9'),
            new Permission('procurement', 'create_negotiation', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::Procurement->value => Grant::checkmark(Scope::All),
            ], '§3.9'),
            new Permission('procurement', 'record_saving', [
                Role::Procurement->value => Grant::checkmark(Scope::All),
            ], '§3.9'),
            new Permission('procurement', 'view_profit', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::Procurement->value => Grant::checkmark(Scope::All),
                Role::Ceo->value => Grant::checkmark(Scope::All),
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Own),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
            ], '§3.9'),

            /**
             * Reports. "Sales" covers Indoor **and** Outdoor Sales (owner, 2026-08-22), so
             * every row here says the same thing about both. Sales and Procurement are ❌
             * on `view_received` yet still create and export their **own** reports — which
             * is why those two cells carry an explicit Own instead of a bare ✅: there is
             * no view row to resolve one against, and guessing is how a scope widens.
             */
            new Permission('report', 'create', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::checkmark(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
                Role::Procurement->value => Grant::scoped(Scope::Own),
            ], '§3.10'),
            new Permission('report', 'view_received', [
                Role::Manager->value => Grant::scoped(Scope::All),
                Role::TeamLeader->value => Grant::scoped(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::scoped(Scope::Out),
                Role::Ceo->value => Grant::scoped(Scope::All),
            ], '§3.10'),
            new Permission('report', 'acknowledge', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::checkmark(Scope::Out),
            ], '§3.10'),
            new Permission('report', 'return_report', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::checkmark(Scope::Out),
            ], '§3.10'),
            new Permission('report', 'comment', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::checkmark(Scope::Out),
                Role::Ceo->value => Grant::checkmark(Scope::All),
            ], '§3.10'),
            new Permission('report', 'export', [
                Role::Manager->value => Grant::checkmark(Scope::All),
                Role::TeamLeader->value => Grant::checkmark(Scope::Team),
                Role::OutdoorSupervisor->value => Grant::checkmark(Scope::Out),
                Role::OutdoorSales->value => Grant::scoped(Scope::Own),
                Role::IndoorSales->value => Grant::scoped(Scope::Own),
                Role::Procurement->value => Grant::scoped(Scope::Own),
                Role::Ceo->value => Grant::checkmark(Scope::All),
            ], '§3.10'),

            /**
             * Administration — the one table with a Super Admin column, and the one with
             * no view row, so every cell is an explicit scope. §3.12 rule 7 further limits
             * which roles a Manager may create; that is a constraint on values rather than
             * a scope and belongs to Module 1's use case, not to this matrix.
             */
            new Permission('admin', 'create_user', [
                Role::SuperAdmin->value => Grant::scoped(Scope::All),
                Role::Manager->value => Grant::scoped(Scope::All),
            ], '§3.11'),
            new Permission('admin', 'deactivate_user', [
                Role::SuperAdmin->value => Grant::scoped(Scope::All),
                Role::Manager->value => Grant::scoped(Scope::All),
            ], '§3.11'),
            new Permission('admin', 'manage_roles', [
                Role::SuperAdmin->value => Grant::scoped(Scope::All),
            ], '§3.11'),
            new Permission('admin', 'system_settings', [
                Role::SuperAdmin->value => Grant::scoped(Scope::All),
            ], '§3.11'),
            new Permission('admin', 'fx_rates', [
                Role::SuperAdmin->value => Grant::scoped(Scope::All),
                Role::Manager->value => Grant::scoped(Scope::All),
            ], '§3.11'),
            new Permission('admin', 'system_limits', [
                Role::SuperAdmin->value => Grant::scoped(Scope::All),
            ], '§3.11'),
            new Permission('admin', 'view_audit_log', [
                Role::SuperAdmin->value => Grant::scoped(Scope::All),
                Role::Manager->value => Grant::scoped(Scope::Team),
            ], '§3.11'),
            new Permission('admin', 'login_as', [
                Role::SuperAdmin->value => Grant::scoped(Scope::All),
            ], '§3.11'),
            new Permission('admin', 'database_ops', [
                Role::SuperAdmin->value => Grant::scoped(Scope::All),
            ], '§3.11'),
        ];
    }
}
