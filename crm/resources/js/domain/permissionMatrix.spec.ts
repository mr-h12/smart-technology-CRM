import { describe, expect, it } from 'vitest';
import { SCOPES, diffGrants, groupPermissions, isRowLocked, scopeCells } from '@/domain/permissionMatrix';
import type { MatrixRow } from '@/domain/permissionMatrix';
import type { PermissionOption } from '@/services/identity';

/**
 * Point 5.3 — the matrix screen's arithmetic.
 *
 * §3.2 · §3.3–§3.11 · §3.12 rule 3 · `AUD-02`. Nothing here authorises
 * anything; these are the two transformations between `GET /permissions` and a
 * grid, and both are wrong in ways that render perfectly.
 */

function permission(triple: string, grantable = true): PermissionOption {
    const [resource, action, scope] = triple.split('.');

    return {
        id: `id-${triple}`,
        resource: resource ?? '',
        action: action ?? '',
        scope: scope ?? '',
        triple,
        is_grantable: grantable,
    };
}

/** The single row a fixture produces, without a non-null assertion at each use. */
function onlyRow(permissions: PermissionOption[]): MatrixRow {
    const row = groupPermissions(permissions)[0]?.rows[0];

    if (row === undefined) {
        throw new Error('The fixture produced no row.');
    }

    return row;
}

describe('SCOPES', () => {
    it('is §3.2\'s five codes, in the document\'s order', () => {
        expect([...SCOPES]).toEqual(['own', 'team', 'all', 'out', 'asgn']);
    });
});

describe('groupPermissions', () => {
    it('groups by resource and then by resource.action', () => {
        const groups = groupPermissions([
            permission('customer.view.own'),
            permission('customer.view.all'),
            permission('customer.edit.own'),
            permission('deal.view.team'),
        ]);

        expect(groups.map((group) => group.resource)).toEqual(['customer', 'deal']);
        expect(groups[0]?.rows.map((row) => row.key)).toEqual(['customer.view', 'customer.edit']);
        expect(Object.keys(groups[0]?.rows[0]?.cells ?? {})).toEqual(['own', 'all']);
    });

    it('keeps the server\'s order rather than sorting, so a re-fetch cannot reshuffle the page', () => {
        const groups = groupPermissions([permission('visit.view.out'), permission('admin.create_user.all')]);

        expect(groups.map((group) => group.resource)).toEqual(['visit', 'admin']);
    });

    it('surfaces a scope §3.2 does not define instead of dropping the row', () => {
        // The `scope` column carries a CHECK constraint, so this cannot happen
        // from the seeder. If it ever did, a grid keyed only on the five known
        // columns would show the row with every cell empty and no explanation.
        const row = onlyRow([permission('customer.view.region')]);

        expect(row.unknownScopes).toEqual(['region']);
        expect(scopeCells(row).every((cell) => cell.permission === null)).toBe(true);
    });
});

describe('scopeCells', () => {
    it('returns five cells in column order, empty where no permission row exists', () => {
        const cells = scopeCells(onlyRow([permission('customer.view.own'), permission('customer.view.all')]));

        expect(cells.map((cell) => cell.scope)).toEqual(['own', 'team', 'all', 'out', 'asgn']);
        expect(cells.map((cell) => cell.permission?.triple ?? null)).toEqual([
            'customer.view.own', null, 'customer.view.all', null, null,
        ]);
    });
});

describe('isRowLocked (§3.12 rule 3)', () => {
    it('locks the whole resource.action when any of its rows is not grantable', () => {
        // Rule 3 forbids the *action*: `customer.delete.own` is no more
        // grantable than `customer.delete.all`.
        const row = onlyRow([
            permission('customer.delete.own', false),
            permission('customer.delete.all', false),
        ]);

        expect(isRowLocked(row)).toBe(true);
    });

    it('leaves a permitted delete alone', () => {
        // §3.5 grants "delete (Draft only)" to four roles. A lock that matched
        // the word `delete` would revoke a permission the document grants.
        expect(isRowLocked(onlyRow([permission('quotation.delete.own')]))).toBe(false);
    });
});

describe('diffGrants', () => {
    const catalogue = new Map(
        ['customer.view.own', 'customer.view.all', 'deal.view.team'].map((triple) => [
            `id-${triple}`,
            permission(triple),
        ]),
    );

    it('reports what was added and what was removed, as triples', () => {
        const diff = diffGrants(
            new Set(['id-customer.view.own', 'id-deal.view.team']),
            new Set(['id-customer.view.own', 'id-customer.view.all']),
            catalogue,
        );

        expect(diff.granted).toEqual(['customer.view.all']);
        expect(diff.revoked).toEqual(['deal.view.team']);
    });

    it('sorts both lists, because AUD-02 stores the sorted set', () => {
        const diff = diffGrants(
            new Set(),
            new Set(['id-deal.view.team', 'id-customer.view.all', 'id-customer.view.own']),
            catalogue,
        );

        expect(diff.granted).toEqual(['customer.view.all', 'customer.view.own', 'deal.view.team']);
    });

    it('is empty when nothing moved', () => {
        const same = new Set(['id-customer.view.own']);

        expect(diffGrants(same, new Set(same), catalogue)).toEqual({ granted: [], revoked: [] });
    });

    it('skips an id no permission row explains rather than printing undefined', () => {
        const diff = diffGrants(new Set(), new Set(['id-ghost']), catalogue);

        expect(diff.granted).toEqual([]);
    });
});
