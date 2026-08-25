/**
 * §3.11's create-user allowlist and §3.12 rule 7, mirrored for the form.
 *
 * ── This is a mirror, and it is presentation ───────────────────────────────
 *
 * The rule lives in `RoleAssignmentPolicy` on the server, which is where every
 * `POST /api/v1/users` is judged (§3.12 rule 1, `SEC-09`). This copy exists so
 * the role dropdown does not offer a choice the API will refuse — a form that
 * lets somebody pick and then fails is worse than one that never offers it.
 * A caller who edits this list in a debugger gets a longer dropdown and a
 * `403`/`422`; `UserManagementTest` proves that end.
 *
 * ⚠️ **Two copies of an authorisation-shaped rule is a defect waiting to
 * happen**, so `RoleAssignmentMirrorTest` reads both `RoleAssignmentPolicy`'s
 * constants and this file and asserts they are identical. That is the same
 * arrangement `RequestAuditContext` uses for the impersonation attribute name,
 * for the same reason: the duplication is forced, so it is pinned.
 *
 * ── Where §3.11 and §3.12 rule 7 disagree ──────────────────────────────────
 *
 * §3.11's cell says a Manager may create *Out.Sup · Out.Sales · Sales ·
 * Procurement* **only**; §3.12 rule 7 says a Manager may not create Manager,
 * CEO or Super Admin, which would leave Team Leader assignable. `D-78` settled
 * it on §3.11's narrower reading: **a Manager may not create a Team Leader.**
 */

/** §3.11's Manager cell, verbatim. */
export const MANAGER_MAY_CREATE: readonly string[] = [
    'outdoor_supervisor',
    'outdoor_sales',
    'indoor_sales',
    'procurement',
];

/** §3.12 rule 7's three, kept for the message the form shows, not for the filter. */
export const FORBIDDEN_TO_MANAGER: readonly string[] = ['manager', 'ceo', 'super_admin'];

/** §3.1: only the Super Admin holds access the matrix does not describe. */
export const UNCONDITIONAL_ROLE = 'super_admin';

/**
 * The role slugs this actor may put on a new account.
 *
 * `null` actor — no session — assigns nothing. An actor with unconditional
 * access assigns anything (§3.11: "✅ any role"). Everybody else who is not the
 * Manager holds no create-user row at all, so the list is empty and the API
 * would refuse them before the role was even read.
 */
export function assignableBy(actorRole: string | null, available: readonly string[]): string[] {
    if (actorRole === null) {
        return [];
    }

    if (actorRole === UNCONDITIONAL_ROLE) {
        return [...available];
    }

    if (actorRole !== 'manager') {
        return [];
    }

    return available.filter((slug) => MANAGER_MAY_CREATE.includes(slug));
}
