import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import type { Router } from 'vue-router';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import type { AuthenticatedUser } from '@/stores/auth';

/**
 * Point 5.3 — §13 screen 3, *Roles & Permissions (RBAC)*.
 *
 * §3.1 · §3.2 · §3.11 · §3.12 rules 1, 3 and 5 · `D-67` · `D-70` · `D-73` ·
 * `OpenAPI §4.2`, §5.1, §6.1.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 puts enforcement at the API, and `RolePermissionManagementTest`
 * is where the Super Admin's immutability and rule 3's refusal are proved
 * against the server. Everything below is about what the screen draws and what
 * it sends — a grid that offers a checkbox the API refuses is a presentation
 * defect worth catching, and it is not the boundary.
 */

const SUPER_ADMIN: AuthenticatedUser = {
    id: '01a0-sa',
    name: 'Test Super Admin',
    email: 'super.admin@example.test',
    is_active: true,
    role: { id: '01a0-role-sa', slug: 'super_admin', name: 'Super Admin' },
    permissions: [],
    unconditional_access: true,
};

function permission(triple: string, grantable = true) {
    const [resource, action, scope] = triple.split('.');

    return { id: `id-${triple}`, resource, action, scope, triple, is_grantable: grantable };
}

/** Enough of §3.3–§3.11's shape to exercise the grid: two resources, four keys. */
const PERMISSIONS = [
    permission('customer.view.own'),
    permission('customer.view.team'),
    permission('customer.view.all'),
    permission('customer.edit.own'),
    permission('deal.view.asgn'),
    permission('deal.approve.all'),
];

const PROCUREMENT = {
    id: '01a0-role-proc',
    slug: 'procurement',
    name: 'Procurement',
    is_system: true,
    is_editable: true,
    description: null,
    permissions: [permission('customer.view.own'), permission('deal.view.asgn')],
};

const SUPER_ADMIN_ROLE = {
    id: '01a0-role-sa',
    slug: 'super_admin',
    name: 'Super Admin',
    is_system: true,
    is_editable: false,
    description: null,
    permissions: [],
};

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function page(items: unknown[], overrides: Record<string, unknown> = {}): Response {
    return json(200, {
        data: items,
        meta: {
            pagination: {
                page: 1,
                per_page: 100,
                total: items.length,
                total_pages: 1,
                has_next_page: false,
                has_previous_page: false,
                ...overrides,
            },
        },
    });
}

interface Handler {
    match: RegExp;
    method?: string;
    response: (url: string) => Response;
}

function routedFetch(handlers: Handler[]) {
    return vi.fn((input: string, init?: { method?: string; body?: string }) => {
        const method = init?.method ?? 'GET';

        for (const handler of handlers) {
            if (handler.match.test(input) && (handler.method ?? 'GET') === method) {
                return Promise.resolve(handler.response(input));
            }
        }

        return Promise.resolve(json(404, { error: { code: 'resource_not_found', message: 'no stub' } }));
    });
}

const ROLES_OK: Handler = { match: /\/roles(\?|$)/, response: () => page([PROCUREMENT, SUPER_ADMIN_ROLE]) };
const PERMISSIONS_OK: Handler = { match: /\/permissions(\?|$)/, response: () => page(PERMISSIONS) };

async function mountMatrix(handlers: Handler[] = [ROLES_OK, PERMISSIONS_OK], locale: 'ar' | 'en' = 'en') {
    vi.resetModules();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/roles');

    const fetchMock = routedFetch([
        { match: /\/auth\/login$/, method: 'POST', response: () => json(201, {
            data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: SUPER_ADMIN },
        }) },
        ...handlers,
    ]);
    vi.stubGlobal('fetch', fetchMock);

    const { createAppRouter } = await import('@/router');
    const router: Router = createAppRouter();

    const { useAuth } = await import('@/stores/auth');
    const auth = useAuth();
    await auth.login(SUPER_ADMIN.email, 'Passw0rd123');

    await router.push('/roles');
    await router.isReady();

    const RolesMatrixView = (await import('@/pages/roles/RolesMatrixView.vue')).default;

    const wrapper = mount(RolesMatrixView, {
        global: {
            plugins: [router, createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { ar, en } })],
        },
    });

    await flushPromises();

    return { wrapper, router, fetchMock };
}

function patchBodies(fetchMock: ReturnType<typeof routedFetch>): unknown[] {
    return fetchMock.mock.calls
        .filter((call) => (call[1]?.method ?? 'GET') === 'PATCH')
        .map((call) => JSON.parse(String(call[1]?.body ?? '{}')));
}

beforeEach(() => {
    window.localStorage.clear();
});

describe('the grid', () => {
    it('renders a section per resource and a row per resource.action', async () => {
        const { wrapper } = await mountMatrix();

        expect(wrapper.findAll('[data-testid="roles-group"]')).toHaveLength(2);
        expect(wrapper.findAll('[data-testid="roles-row"]').map((row) => row.attributes('data-permission')))
            .toEqual(['customer.view', 'customer.edit', 'deal.view', 'deal.approve']);
    });

    it('draws a checkbox only where a permission row exists, and §3.2\'s dash elsewhere', async () => {
        const { wrapper } = await mountMatrix();

        // `deal.view` exists at `asgn` alone: four of its five cells have no
        // row, and the client cannot mint a permission id for them.
        const row = wrapper.findAll('[data-testid="roles-row"]')[2];

        expect(row?.findAll('input[type="checkbox"]')).toHaveLength(1);
        expect(row?.text()).toContain(en.roles.cell.notDefined);
    });

    it('checks exactly the triples the selected role holds', async () => {
        const { wrapper } = await mountMatrix();

        const checked = wrapper.findAll('input[type="checkbox"]')
            .filter((input) => input.attributes('data-testid') !== undefined
                && (input.element as HTMLInputElement).checked)
            .map((input) => input.attributes('data-testid'));

        expect(checked).toEqual(['roles-toggle-customer.view.own', 'roles-toggle-deal.view.asgn']);
    });

    it('names the columns with §3.2\'s five scopes', async () => {
        const { wrapper } = await mountMatrix();

        const headers = wrapper.findAll('thead th').slice(1, 6).map((th) => th.text());

        expect(headers).toEqual([
            en.roles.scope.own, en.roles.scope.team, en.roles.scope.all,
            en.roles.scope.out, en.roles.scope.asgn,
        ]);
    });

    it('opens on the first editable role, not on the one that cannot be edited', async () => {
        // Opening on the Super Admin would greet an administrator with a grid
        // that cannot be touched and no sign that any other role can.
        const { wrapper } = await mountMatrix([
            { match: /\/roles(\?|$)/, response: () => page([SUPER_ADMIN_ROLE, PROCUREMENT]) },
            PERMISSIONS_OK,
        ]);

        expect(wrapper.find('[data-testid="roles-immutable-notice"]').exists()).toBe(false);
        expect(wrapper.findAll('input:disabled')).toHaveLength(0);
    });
});

describe('§3.12 rule 5 — every page of the matrix, or none of it', () => {
    it('follows has_next_page rather than assuming one request is the whole matrix', async () => {
        // `MAX_PER_PAGE` is 100 and the seeded matrix holds 143 rows. A screen
        // that read one page would draw 100 permissions and, on the first save,
        // revoke every grant sitting in the missing 43 — `PATCH` takes the full
        // desired set, so an unloaded id is an omitted id.
        const { wrapper, fetchMock } = await mountMatrix([
            ROLES_OK,
            {
                match: /\/permissions(\?|$)/,
                response: (url) => url.includes('page=2')
                    ? page([permission('deal.view.asgn'), permission('deal.approve.all')], { page: 2, total: 6, total_pages: 2, has_previous_page: true })
                    : page(PERMISSIONS.slice(0, 4), { total: 6, total_pages: 2, has_next_page: true }),
            },
        ]);

        const permissionCalls = fetchMock.mock.calls
            .map((call) => String(call[0]))
            .filter((url) => url.includes('/permissions'));

        expect(permissionCalls).toHaveLength(2);
        expect(permissionCalls[1]).toContain('page=2');
        expect(wrapper.findAll('[data-testid="roles-row"]')).toHaveLength(4);
    });
});

describe('§3.1 — the role that cannot be edited', () => {
    it('badges it, explains it, and disables every toggle', async () => {
        const { wrapper } = await mountMatrix();

        await wrapper.findAll('[data-testid="roles-tab"]')[1]?.trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="roles-immutable-chip"]').text()).toBe(en.roles.badge.immutable);
        expect(wrapper.find('[data-testid="roles-immutable-notice"]').exists()).toBe(true);

        const toggles = wrapper.findAll('input[type="checkbox"]');

        expect(toggles.length).toBeGreaterThan(0);
        expect(toggles.every((input) => input.attributes('disabled') !== undefined)).toBe(true);
    });

    it('ignores a click on a disabled toggle instead of staging it', async () => {
        // `disabled` stops a real pointer; it does not stop a dispatched event,
        // and the guard in `toggle()` is what makes the two agree.
        const { wrapper } = await mountMatrix();

        await wrapper.findAll('[data-testid="roles-tab"]')[1]?.trigger('click');
        await flushPromises();

        await wrapper.find('[data-testid="roles-toggle-customer.view.own"]').trigger('change');

        expect(wrapper.find('[data-testid="roles-pending"]').text()).toBe(en.roles.changes.none);
        expect(wrapper.find('[data-testid="roles-save"]').attributes('disabled')).toBeDefined();
    });

    it('reports is_editable, not is_system — all eight seeded roles are system roles', async () => {
        const { wrapper } = await mountMatrix();

        // Both fixtures carry `is_system: true`; only one is immutable. A
        // screen reading `is_system` would freeze the whole matrix and delete
        // §3.12 rule 5.
        expect(wrapper.findAll('[data-testid="roles-immutable-chip"]')).toHaveLength(1);
    });
});

describe('§3.12 rule 3 — an action no role may hold', () => {
    const FORBIDDEN = [...PERMISSIONS, permission('customer.delete.all', false)];

    it('locks the row and disables its toggle', async () => {
        // No `permissions` row exists for a rule 3 cell in the seeded matrix,
        // so this state is unreachable today. It is exactly the row a later
        // module could add, and `RolePermissionManagementTest` proves the server
        // refuses the grant — this proves the screen never offers it.
        const { wrapper } = await mountMatrix([
            ROLES_OK,
            { match: /\/permissions(\?|$)/, response: () => page(FORBIDDEN) },
        ]);

        expect(wrapper.find('[data-testid="roles-locked-chip"]').text()).toBe(en.roles.badge.locked);
        expect(wrapper.find('[data-testid="roles-toggle-customer.delete.all"]').attributes('disabled')).toBeDefined();
    });

    it('refuses to stage it even when the change event is dispatched anyway', async () => {
        const { wrapper } = await mountMatrix([
            ROLES_OK,
            { match: /\/permissions(\?|$)/, response: () => page(FORBIDDEN) },
        ]);

        await wrapper.find('[data-testid="roles-toggle-customer.delete.all"]').trigger('change');

        expect(wrapper.find('[data-testid="roles-pending"]').text()).toBe(en.roles.changes.none);
    });

    it('leaves the grantable rows alone', async () => {
        const { wrapper } = await mountMatrix([
            ROLES_OK,
            { match: /\/permissions(\?|$)/, response: () => page(FORBIDDEN) },
        ]);

        expect(wrapper.find('[data-testid="roles-toggle-customer.view.all"]').attributes('disabled')).toBeUndefined();
    });
});

describe('staging and the diff', () => {
    it('counts unsaved changes in both directions', async () => {
        const { wrapper } = await mountMatrix();

        await wrapper.find('[data-testid="roles-toggle-customer.view.all"]').trigger('change');
        await wrapper.find('[data-testid="roles-toggle-deal.view.asgn"]').trigger('change');

        expect(wrapper.find('[data-testid="roles-pending"]').text()).toBe(
            en.roles.changes.pending.replace('{count}', '2'),
        );
    });

    it('returns to zero when a toggle is put back, rather than counting the clicks', async () => {
        const { wrapper } = await mountMatrix();

        await wrapper.find('[data-testid="roles-toggle-customer.view.all"]').trigger('change');
        await wrapper.find('[data-testid="roles-toggle-customer.view.all"]').trigger('change');

        expect(wrapper.find('[data-testid="roles-pending"]').text()).toBe(en.roles.changes.none);
    });

    it('discards back to what the server holds without sending anything', async () => {
        const { wrapper, fetchMock } = await mountMatrix();

        await wrapper.find('[data-testid="roles-toggle-customer.view.all"]').trigger('change');
        await wrapper.find('[data-testid="roles-discard"]').trigger('click');

        expect(wrapper.find('[data-testid="roles-pending"]').text()).toBe(en.roles.changes.none);
        expect(patchBodies(fetchMock)).toEqual([]);
    });

    it('drops staged changes when another role is selected', async () => {
        const { wrapper } = await mountMatrix();

        await wrapper.find('[data-testid="roles-toggle-customer.view.all"]').trigger('change');
        await wrapper.findAll('[data-testid="roles-tab"]')[1]?.trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="roles-pending"]').text()).toBe(en.roles.changes.none);
    });

    it('shows the exact diff before anything is sent', async () => {
        const { wrapper, fetchMock } = await mountMatrix();

        await wrapper.find('[data-testid="roles-toggle-customer.view.all"]').trigger('change');
        await wrapper.find('[data-testid="roles-toggle-deal.view.asgn"]').trigger('change');
        await wrapper.find('[data-testid="roles-save"]').trigger('click');

        const modal = wrapper.find('[data-testid="permission-diff-modal"]');

        expect(modal.exists()).toBe(true);
        expect(wrapper.find('[data-testid="diff-granted"]').text()).toContain('customer.view.all');
        expect(wrapper.find('[data-testid="diff-revoked"]').text()).toContain('deal.view.asgn');

        // §6.6: the consequence is shown *before* submission.
        expect(patchBodies(fetchMock)).toEqual([]);
    });
});

describe('saving (§3.12 rule 5)', () => {
    const saved = {
        ...PROCUREMENT,
        permissions: [permission('customer.view.own'), permission('customer.view.all')],
        diff: { granted: ['customer.view.all'], revoked: ['deal.view.asgn'], changed: true },
    };

    const SAVE_OK: Handler = {
        match: /\/roles\/[^/]+\/permissions$/,
        method: 'PATCH',
        response: () => json(200, { data: saved, meta: { request_id: 'req-1' } }),
    };

    async function stageAndSave(handlers: Handler[]) {
        const mounted = await mountMatrix(handlers);

        await mounted.wrapper.find('[data-testid="roles-toggle-customer.view.all"]').trigger('change');
        await mounted.wrapper.find('[data-testid="roles-toggle-deal.view.asgn"]').trigger('change');
        await mounted.wrapper.find('[data-testid="roles-save"]').trigger('click');
        await mounted.wrapper.find('[data-testid="diff-confirm"]').trigger('click');
        await flushPromises();

        return mounted;
    }

    it('sends the complete desired set, not a delta', async () => {
        const { fetchMock } = await stageAndSave([ROLES_OK, PERMISSIONS_OK, SAVE_OK]);

        const bodies = patchBodies(fetchMock);

        expect(bodies).toHaveLength(1);
        expect(bodies[0]).toEqual({ permission_ids: ['id-customer.view.own', 'id-customer.view.all'] });
    });

    it('rebuilds the grid from the server\'s answer rather than from the boxes that were clicked', async () => {
        const { wrapper } = await stageAndSave([ROLES_OK, PERMISSIONS_OK, SAVE_OK]);

        expect(wrapper.find('[data-testid="roles-pending"]').text()).toBe(en.roles.changes.none);
        expect((wrapper.find('[data-testid="roles-toggle-customer.view.all"]').element as HTMLInputElement).checked)
            .toBe(true);
        expect((wrapper.find('[data-testid="roles-toggle-deal.view.asgn"]').element as HTMLInputElement).checked)
            .toBe(false);
    });

    it('reports what moved, in a live region and not only in colour', async () => {
        const { wrapper } = await stageAndSave([ROLES_OK, PERMISSIONS_OK, SAVE_OK]);

        const result = wrapper.find('[data-testid="roles-save-result"]');

        expect(result.attributes('role')).toBe('status');
        expect(result.text()).toBe(en.roles.saved.replace('{granted}', '1').replace('{revoked}', '1'));
        expect(wrapper.find('[data-testid="permission-diff-modal"]').exists()).toBe(false);
    });

    it('renders §5.1\'s stable code, not the HTTP status alone', async () => {
        const { wrapper } = await stageAndSave([
            ROLES_OK,
            PERMISSIONS_OK,
            {
                match: /\/roles\/[^/]+\/permissions$/,
                method: 'PATCH',
                response: () => json(422, {
                    error: {
                        code: 'business_rule_blocked',
                        message: 'blocked',
                        details: [{ field: 'permission_ids', code: 'grant_forbidden' }],
                    },
                }),
            },
        ]);

        expect(wrapper.find('[data-testid="roles-save-error"]').text()).toBe(en.roles.error.forbidden);

        // The staged changes survive a refusal: nothing was saved, so nothing
        // the administrator did should be thrown away.
        expect(wrapper.find('[data-testid="roles-pending"]').text()).toBe(
            en.roles.changes.pending.replace('{count}', '2'),
        );
    });

    it('distinguishes an immutable role from a forbidden grant', async () => {
        const { wrapper } = await stageAndSave([
            ROLES_OK,
            PERMISSIONS_OK,
            {
                match: /\/roles\/[^/]+\/permissions$/,
                method: 'PATCH',
                response: () => json(422, {
                    error: {
                        code: 'business_rule_blocked',
                        message: 'blocked',
                        details: [{ field: 'role', code: 'role_is_immutable' }],
                    },
                }),
            },
        ]);

        expect(wrapper.find('[data-testid="roles-save-error"]').text()).toBe(en.roles.error.immutable);
    });
});

describe('loading states', () => {
    it('shows the error state when the matrix cannot be loaded', async () => {
        const { wrapper } = await mountMatrix([
            { match: /\/roles(\?|$)/, response: () => json(500, { error: { code: 'unknown_error', message: 'x' } }) },
            PERMISSIONS_OK,
        ]);

        expect(wrapper.find('[data-testid="error-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="roles-matrix"]').exists()).toBe(false);
    });
});

describe('Arabic', () => {
    it('renders Arabic labels rather than raw keys', async () => {
        const { wrapper } = await mountMatrix([ROLES_OK, PERMISSIONS_OK], 'ar');

        expect(wrapper.find('h1').text()).toBe(ar.roles.title);
        expect(wrapper.find('[data-testid="roles-save"]').text()).toBe(ar.roles.save);
        expect(wrapper.text()).not.toContain('roles.scope');
    });
});
