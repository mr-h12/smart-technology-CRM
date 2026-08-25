/**
 * The matrix screen's arithmetic, kept out of the component.
 *
 * Grouping 143 rows into §3.3–§3.11's nine sections and working out what a
 * checkbox grid changed are the two things on this screen that can be wrong
 * without looking wrong. Both are pure functions here so a test can state a
 * before and an after instead of clicking and reading the DOM.
 *
 * ── Nothing here decides anything ──────────────────────────────────────────
 *
 * `D-67`: the SPA "never owns a calculation, a permission decision, or a state
 * transition". These functions arrange rows and subtract two sets. Which
 * permissions exist is `GET /permissions`; which are grantable is the server's
 * `is_grantable` (§3.12 rule 3, derived from `PermissionMatrix`); whether the
 * change is allowed is `PATCH`'s answer. A client that computed any of those
 * would be a second implementation of the authorisation model.
 */
import type { PermissionOption } from '@/services/identity';

/**
 * §3.2's five codes, in the order the document prints them.
 *
 * The column order of the grid, and deliberately a constant rather than
 * whatever order the API happened to return: a table whose columns move
 * between roles is a table nobody can read across.
 *
 * A sixth value cannot exist — the `scope` column carries a CHECK constraint
 * listing these five — but a row that somehow held one would be dropped from
 * every column if this list were the only thing consulted, so
 * {@see groupPermissions} keeps such rows in their own bucket rather than
 * silently losing them.
 */
export const SCOPES = ['own', 'team', 'all', 'out', 'asgn'] as const;

export type ScopeCode = (typeof SCOPES)[number];

export function isKnownScope(value: string): value is ScopeCode {
    return (SCOPES as readonly string[]).includes(value);
}

/** One `resource.action` row of the grid: up to five scope cells. */
export interface MatrixRow {
    /** §3.2's `resource.action`, which is what rule 3 forbids by name. */
    readonly key: string;
    readonly resource: string;
    readonly action: string;
    /** The permission rows that exist for this key, by scope code. */
    readonly cells: Readonly<Record<string, PermissionOption>>;
    /**
     * Scope codes present on this key that {@see SCOPES} does not name.
     *
     * Empty in every real dataset. Non-empty means the database holds a scope
     * §3.2 does not define, and the screen says so rather than hiding the row.
     */
    readonly unknownScopes: readonly string[];
}

/** One §3.3–§3.11 section. */
export interface MatrixGroup {
    readonly resource: string;
    readonly rows: readonly MatrixRow[];
}

/**
 * The flat permission list, grouped by resource and then by `resource.action`.
 *
 * Resources come out in the order the server sent them (`§6.2`'s documented
 * default sort for this resource is `resource`), and rows within a resource in
 * first-seen order, so a re-fetch cannot reshuffle the page under a reader.
 */
export function groupPermissions(permissions: readonly PermissionOption[]): MatrixGroup[] {
    const groups = new Map<string, Map<string, { row: MatrixRow; cells: Record<string, PermissionOption>; unknown: string[] }>>();

    for (const permission of permissions) {
        let rows = groups.get(permission.resource);

        if (rows === undefined) {
            rows = new Map();
            groups.set(permission.resource, rows);
        }

        const key = `${permission.resource}.${permission.action}`;
        let entry = rows.get(key);

        if (entry === undefined) {
            const cells: Record<string, PermissionOption> = {};
            const unknown: string[] = [];

            entry = {
                cells,
                unknown,
                row: {
                    key,
                    resource: permission.resource,
                    action: permission.action,
                    cells,
                    unknownScopes: unknown,
                },
            };
            rows.set(key, entry);
        }

        entry.cells[permission.scope] = permission;

        if (!isKnownScope(permission.scope)) {
            entry.unknown.push(permission.scope);
        }
    }

    const result: MatrixGroup[] = [];

    for (const [resource, rows] of groups) {
        result.push({ resource, rows: [...rows.values()].map((entry) => entry.row) });
    }

    return result;
}

/** One cell of the grid: a scope column, and the permission row behind it or nothing. */
export interface ScopeCell {
    readonly scope: ScopeCode;
    /** Null where no `permissions` row exists for this key and scope — §3.2's `—`. */
    readonly permission: PermissionOption | null;
}

/**
 * A row expanded to five cells, in {@see SCOPES} order.
 *
 * The component renders this rather than indexing `row.cells` inside the
 * template: a lookup that can miss forces a non-null assertion at every use,
 * and `!` is exactly the untyped escape hatch this project does not take.
 */
export function scopeCells(row: MatrixRow): ScopeCell[] {
    return SCOPES.map((scope) => ({ scope, permission: row.cells[scope] ?? null }));
}

/**
 * True when rule 3 forbids granting this row to anybody.
 *
 * Read off the cells rather than recomputed: the server sets `is_grantable`
 * per permission from `PermissionMatrix::forbiddenKeys()`, and rule 3 applies
 * to the whole `resource.action`, so one locked cell locks the row.
 */
export function isRowLocked(row: MatrixRow): boolean {
    return Object.values(row.cells).some((permission) => !permission.is_grantable);
}

export interface GrantDiff {
    /** Triples, sorted — the same shape and order the audit row records. */
    readonly granted: string[];
    readonly revoked: string[];
}

/**
 * What a staged grid changed, in triples a person can read.
 *
 * Sorted, because `AUD-02` stores old and new values and `RoleView::triples()`
 * sorts before writing them; a confirmation listing the same set in a different
 * order than the audit log is a confirmation of something else.
 *
 * Ids that resolve to no known permission are skipped rather than shown as
 * `undefined` — the server refuses them with `422 permission_not_found`, and
 * that refusal is the answer, not a line of prose in a modal.
 */
export function diffGrants(
    original: ReadonlySet<string>,
    staged: ReadonlySet<string>,
    byId: ReadonlyMap<string, PermissionOption>,
): GrantDiff {
    const granted: string[] = [];
    const revoked: string[] = [];

    for (const id of staged) {
        if (!original.has(id)) {
            const triple = byId.get(id)?.triple;

            if (triple !== undefined) {
                granted.push(triple);
            }
        }
    }

    for (const id of original) {
        if (!staged.has(id)) {
            const triple = byId.get(id)?.triple;

            if (triple !== undefined) {
                revoked.push(triple);
            }
        }
    }

    granted.sort();
    revoked.sort();

    return { granted, revoked };
}
