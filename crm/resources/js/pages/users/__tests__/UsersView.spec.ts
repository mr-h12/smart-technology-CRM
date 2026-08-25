import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import type { Router } from 'vue-router';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import type { AuthenticatedUser } from '@/stores/auth';

/**
 * Point 5.2 — §8's *Employees* screen.
 *
 * §3.11 · §3.12 rules 1, 4, 6 and 7 · §9 Flow 9 · §10.1 · `D-28` · `D-34` ·
 * `D-78` · `SEC-09` · `SEC-10` · `OpenAPI §4.2`, §5.1, §6.2.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * Every assertion below is about what is drawn. §3.12 rule 1 puts enforcement
 * at the API, and `UserManagementTest` and `ImpersonationTest` are where a 403
 * and a §3.12 rule 6 hidden account are proved. A button drawn for the wrong
 * role is a presentation defect worth catching — it is not the boundary.
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

const MANAGER: AuthenticatedUser = {
    id: '01a0-mgr',
    name: 'Test Manager',
    email: 'manager@example.test',
    is_active: true,
    role: { id: '01a0-role-mgr', slug: 'manager', name: 'Manager' },
    permissions: ['admin.create_user.all', 'admin.deactivate_user.all'],
    unconditional_access: false,
};

function user(overrides: Partial<Record<string, unknown>> = {}) {
    return {
        id: '01a0-indoor',
        name: 'Nadia Indoor',
        email: 'indoor.sales@example.test',
        role_id: '01a0-role-indoor',
        role: { slug: 'indoor_sales', name: 'Indoor Sales' },
        is_active: true,
        created_at: '2026-08-01T09:00:00+00:00',
        updated_at: '2026-08-01T09:00:00+00:00',
        ...overrides,
    };
}

const ROLES = [
    { id: '01a0-role-indoor', slug: 'indoor_sales', name: 'Indoor Sales', is_system: true },
    { id: '01a0-role-proc', slug: 'procurement', name: 'Procurement', is_system: true },
    { id: '01a0-role-mgr', slug: 'manager', name: 'Manager', is_system: true },
    { id: '01a0-role-sa', slug: 'super_admin', name: 'Super Admin', is_system: true },
];

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function page(items: unknown[], overrides: Record<string, unknown> = {}): Response {
    return json(200, {
        data: items,
        meta: {
            pagination: {
                page: 1,
                per_page: 25,
                total: items.length,
                total_pages: 1,
                has_next_page: false,
                has_previous_page: false,
                ...overrides,
            },
        },
    });
}

/**
 * One fetch stub routed by URL, so a test states what each endpoint answers
 * instead of counting call order — the order changed the first time `load()`
 * and `loadRoles()` were put in a `Promise.all`.
 */
function routedFetch(handlers: { match: RegExp; method?: string; response: () => Response }[]) {
    return vi.fn((input: string, init?: { method?: string }) => {
        const method = init?.method ?? 'GET';

        for (const handler of handlers) {
            if (handler.match.test(input) && (handler.method ?? 'GET') === method) {
                return Promise.resolve(handler.response());
            }
        }

        return Promise.resolve(json(404, { error: { code: 'resource_not_found', message: 'no stub' } }));
    });
}

async function mountUsers(
    profile: AuthenticatedUser,
    handlers: { match: RegExp; method?: string; response: () => Response }[],
    locale: 'ar' | 'en' = 'en',
) {
    vi.resetModules();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/users');

    const fetchMock = routedFetch([
        { match: /\/auth\/login$/, method: 'POST', response: () => json(201, {
            data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
        }) },
        ...handlers,
    ]);
    vi.stubGlobal('fetch', fetchMock);

    const { createAppRouter } = await import('@/router');
    const router: Router = createAppRouter();

    const { useAuth } = await import('@/stores/auth');
    const auth = useAuth();
    await auth.login(profile.email, 'Passw0rd123');

    await router.push('/users');
    await router.isReady();

    const UsersView = (await import('@/pages/users/UsersView.vue')).default;

    const wrapper = mount(UsersView, {
        global: {
            plugins: [router, createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { ar, en } })],
        },
    });

    await flushPromises();

    return { wrapper, router, fetchMock, auth };
}

const LIST_OK = { match: /\/users(\?|$)/, response: () => page([user(), user({ id: '01a0-proc', name: 'Omar Procurement', role: { slug: 'procurement', name: 'Procurement' }, is_active: false })]) };
const ROLES_OK = { match: /\/roles/, response: () => page(ROLES) };

beforeEach(() => {
    window.localStorage.clear();
});

describe('the table', () => {
    it('renders a row per user with the columns §8 needs', async () => {
        const { wrapper } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        const rows = wrapper.findAll('[data-testid="users-row"]');

        expect(rows).toHaveLength(2);
        expect(rows[0]?.text()).toContain('Nadia Indoor');
        expect(rows[0]?.text()).toContain('indoor.sales@example.test');
        expect(rows[0]?.text()).toContain('Indoor Sales');
    });

    it('says Active or Suspended in words, not only in colour', async () => {
        // §9.5: a state must be legible without relying on colour alone.
        const { wrapper } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        const badges = wrapper.findAll('[data-testid="users-status"]');

        expect(badges[0]?.text()).toBe('Active');
        expect(badges[1]?.text()).toBe('Suspended');
    });

    it('shows the empty state rather than a bare table', async () => {
        const { wrapper } = await mountUsers(SUPER_ADMIN, [
            { match: /\/users(\?|$)/, response: () => page([]) },
            ROLES_OK,
        ]);

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="users-table"]').exists()).toBe(false);
    });

    it('shows the error state when the list cannot be loaded', async () => {
        const { wrapper } = await mountUsers(SUPER_ADMIN, [
            { match: /\/users(\?|$)/, response: () => json(500, { error: { code: 'unknown_error', message: 'x' } }) },
            ROLES_OK,
        ]);

        expect(wrapper.find('[data-testid="error-state"]').exists()).toBe(true);
    });

    it('never has a hidden account to filter, because the server never sends one', async () => {
        // §3.12 rule 6 is enforced by `User::scopeListable()` inside
        // EloquentUserDirectory, and UserManagementTest proves it. The payload
        // carries no `is_hidden` at all — a field telling a client which account
        // to omit is a field telling it the account exists.
        const { wrapper } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        expect(wrapper.html()).not.toContain('is_hidden');

        // The *rows*, not the whole page: the role filter legitimately lists
        // "Super Admin" as a role. Rule 6 hides the account, not the role —
        // §3.1 names the role in the documentation and §3.11 gives it a column.
        for (const row of wrapper.findAll('[data-testid="users-row"]')) {
            expect(row.text()).not.toContain('Super Admin');
        }
    });
});

describe('filtering and pagination (OpenAPI §6.2, §4.2)', () => {
    it('sends only the two filters the resource declares', async () => {
        const { wrapper, fetchMock } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        await wrapper.find('[data-testid="users-filter-status"]').setValue('inactive');
        await flushPromises();

        const urls = fetchMock.mock.calls.map((call) => String(call[0]));
        const filtered = urls.filter((url) => url.includes('filter'));

        expect(filtered.at(-1)).toContain('filter%5Bis_active%5D=false');
        // §6.2 rejects an unknown parameter with 400 rather than ignoring it,
        // so an unset filter must be absent and not empty.
        expect(filtered.at(-1)).not.toContain('filter%5Brole%5D');
    });

    it('returns to page one when a filter changes', async () => {
        const { wrapper, fetchMock } = await mountUsers(SUPER_ADMIN, [
            { match: /\/users(\?|$)/, response: () => page([user()], { page: 2, total: 40, total_pages: 2, has_previous_page: true }) },
            ROLES_OK,
        ]);

        await wrapper.find('[data-testid="users-filter-role"]').setValue('procurement');
        await flushPromises();

        const last = String(fetchMock.mock.calls.at(-1)?.[0]);

        expect(last).toContain('page=1');
    });

    it('hides the pager on a single page and shows it on more', async () => {
        const single = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        expect(single.wrapper.find('[data-testid="users-pagination"]').exists()).toBe(false);

        const many = await mountUsers(SUPER_ADMIN, [
            { match: /\/users(\?|$)/, response: () => page([user()], { total: 40, total_pages: 2, has_next_page: true }) },
            ROLES_OK,
        ]);

        expect(many.wrapper.find('[data-testid="users-pagination"]').exists()).toBe(true);
        expect(many.wrapper.find('[data-testid="users-previous"]').attributes('disabled')).toBeDefined();
    });
});

describe('creating a user (§9 Flow 9)', () => {
    it('never opens the form for a Manager, because there is no role list to fill it', async () => {
        // Not a design choice — §3.11 gives `admin.manage_roles` to the Super
        // Admin alone, so `GET /roles` is not a call a Manager can make and
        // there is no `role_id` to submit. The dropdown's own filtering is
        // tested in UserFormModal.spec.ts, where a role list can be supplied.
        const { wrapper } = await mountUsers(MANAGER, [LIST_OK, ROLES_OK]);

        expect(wrapper.find('[data-testid="users-create"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="users-no-roles-notice"]').exists()).toBe(true);
    });

    it('offers a Super Admin every role (§3.11: "✅ any role")', async () => {
        const { wrapper } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        await wrapper.find('[data-testid="users-create"]').trigger('click');
        await flushPromises();

        const options = wrapper.findAll('[data-testid="user-form-role"] option')
            .map((option) => option.text())
            .filter((text) => text !== 'Choose a role');

        expect(options).toHaveLength(ROLES.length);
    });

    it('refuses a password that does not meet D-28 before sending it', async () => {
        const { wrapper, fetchMock } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        await wrapper.find('[data-testid="users-create"]').trigger('click');
        await flushPromises();

        await wrapper.find('[data-testid="user-form-name"]').setValue('New Person');
        await wrapper.find('[data-testid="user-form-email"]').setValue('new@example.test');
        await wrapper.find('[data-testid="user-form-role"]').setValue('01a0-role-indoor');
        await wrapper.find('[data-testid="user-form-password"]').setValue('short1');
        await wrapper.find('[data-testid="user-form-save"]').trigger('submit');
        await flushPromises();

        const posted = fetchMock.mock.calls.filter(
            (call) => String(call[0]).endsWith('/users')
                && (call[1] as { method?: string } | undefined)?.method === 'POST',
        );

        expect(posted).toHaveLength(0);
        expect(wrapper.text()).toContain('at least 8 characters');
    });

    it('posts a valid form and reloads the list', async () => {
        const { wrapper, fetchMock } = await mountUsers(SUPER_ADMIN, [
            { match: /\/users$/, method: 'POST', response: () => json(201, { data: user({ id: 'new' }) }) },
            LIST_OK,
            ROLES_OK,
        ]);

        const before = fetchMock.mock.calls.filter((call) => String(call[0]).includes('/users')).length;

        await wrapper.find('[data-testid="users-create"]').trigger('click');
        await flushPromises();

        await wrapper.find('[data-testid="user-form-name"]').setValue('New Person');
        await wrapper.find('[data-testid="user-form-email"]').setValue('new@example.test');
        await wrapper.find('[data-testid="user-form-role"]').setValue('01a0-role-indoor');
        await wrapper.find('[data-testid="user-form-password"]').setValue('Passw0rd123');
        await wrapper.find('[data-testid="user-form-save"]').trigger('submit');
        await flushPromises();

        const after = fetchMock.mock.calls.filter((call) => String(call[0]).includes('/users')).length;

        expect(after).toBeGreaterThan(before + 1);
        expect(wrapper.find('[data-testid="user-form-modal"]').exists()).toBe(false);
    });

    it('puts a 422 on the field that caused it (OpenAPI §5.1)', async () => {
        const { wrapper } = await mountUsers(SUPER_ADMIN, [
            { match: /\/users$/, method: 'POST', response: () => json(422, {
                error: {
                    code: 'validation_failed',
                    message: 'x',
                    details: [{ field: 'email', code: 'email_already_taken', message: 'x' }],
                },
            }) },
            LIST_OK,
            ROLES_OK,
        ]);

        await wrapper.find('[data-testid="users-create"]').trigger('click');
        await flushPromises();

        await wrapper.find('[data-testid="user-form-name"]').setValue('New Person');
        await wrapper.find('[data-testid="user-form-email"]').setValue('taken@example.test');
        await wrapper.find('[data-testid="user-form-role"]').setValue('01a0-role-indoor');
        await wrapper.find('[data-testid="user-form-password"]').setValue('Passw0rd123');
        await wrapper.find('[data-testid="user-form-save"]').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('already belongs to an account');
        expect(wrapper.find('[data-testid="user-form-modal"]').exists()).toBe(true);
    });

    it('offers no password field when editing (§9 Flow 0 owns that)', async () => {
        const { wrapper } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        await wrapper.findAll('[data-testid="users-edit"]')[0]?.trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="user-form-password"]').exists()).toBe(false);
    });

    it('says so when the role list could not be read, instead of a form that cannot be sent', async () => {
        // §3.11 gives `admin.manage_roles` to the Super Admin alone, so a
        // Manager's GET /roles is 403 and there is no `role_id` to submit.
        const { wrapper } = await mountUsers(MANAGER, [
            LIST_OK,
            { match: /\/roles/, response: () => json(403, { error: { code: 'permission_denied', message: 'x' } }) },
        ]);

        expect(wrapper.find('[data-testid="users-no-roles-notice"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="users-create"]').attributes('disabled')).toBeDefined();
    });
});

describe('deactivation (D-34, §10.1)', () => {
    it('asks before flipping the switch', async () => {
        const { wrapper, fetchMock } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        await wrapper.findAll('[data-testid="users-toggle-activation"]')[0]?.trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true);

        const patched = fetchMock.mock.calls.filter((call) => (call[1] as { method?: string } | undefined)?.method === 'PATCH');

        expect(patched).toHaveLength(0);
    });

    it('calls deactivate and reloads once confirmed', async () => {
        const { wrapper, fetchMock } = await mountUsers(SUPER_ADMIN, [
            { match: /\/users\/[^/]+\/deactivate$/, method: 'PATCH', response: () => json(200, { data: user({ is_active: false }) }) },
            LIST_OK,
            ROLES_OK,
        ]);

        await wrapper.findAll('[data-testid="users-toggle-activation"]')[0]?.trigger('click');
        await flushPromises();
        await wrapper.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        const patched = fetchMock.mock.calls.filter((call) => String(call[0]).includes('/deactivate'));

        expect(patched).toHaveLength(1);
        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false);
    });

    it('calls reactivate for a suspended account', async () => {
        const { wrapper, fetchMock } = await mountUsers(SUPER_ADMIN, [
            { match: /\/users\/[^/]+\/reactivate$/, method: 'PATCH', response: () => json(200, { data: user({ is_active: true }) }) },
            LIST_OK,
            ROLES_OK,
        ]);

        await wrapper.findAll('[data-testid="users-toggle-activation"]')[1]?.trigger('click');
        await flushPromises();
        await wrapper.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.filter((call) => String(call[0]).includes('/reactivate'))).toHaveLength(1);
    });

    it('cancels without calling anything', async () => {
        const { wrapper, fetchMock } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        await wrapper.findAll('[data-testid="users-toggle-activation"]')[0]?.trigger('click');
        await flushPromises();
        await wrapper.find('[data-testid="confirm-cancel"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false);
        expect(fetchMock.mock.calls.filter((call) => (call[1] as { method?: string } | undefined)?.method === 'PATCH')).toHaveLength(0);
    });
});

describe('Login As (SEC-10)', () => {
    it('is drawn for the Super Admin', async () => {
        const { wrapper } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        expect(wrapper.findAll('[data-testid="users-login-as"]').length).toBeGreaterThan(0);
    });

    it('is not drawn for a Manager', async () => {
        const { wrapper } = await mountUsers(MANAGER, [LIST_OK, ROLES_OK]);

        // §3.11 gives Login As to the Super Admin alone. The server refuses a
        // Manager twice over (ImpersonationTest); this is SEC-09's visual
        // complement, and offering a button that always fails is worse than
        // offering none.
        expect(wrapper.findAll('[data-testid="users-login-as"]')).toHaveLength(0);
    });

    it('is disabled for a deactivated account', async () => {
        // §10.1 blocks that account from signing in, and becoming it would be
        // the way around D-34's switch. StartImpersonation answers 422.
        const { wrapper } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK]);

        const buttons = wrapper.findAll('[data-testid="users-login-as"]');

        expect(buttons[0]?.attributes('disabled')).toBeUndefined();
        expect(buttons[1]?.attributes('disabled')).toBeDefined();
    });

    it('starts the impersonation and leaves for the target\'s screen', async () => {
        const target = { id: '01a0-indoor', name: 'Nadia Indoor', role: 'indoor_sales' };

        const { wrapper, fetchMock, auth, router } = await mountUsers(SUPER_ADMIN, [
            { match: /\/auth\/impersonate\/[^/]+$/, method: 'POST', response: () => json(201, {
                data: {
                    // No `token_type` — checked against the running server;
                    // ImpersonateController does not send one.
                    token: 'b'.repeat(64),
                    impersonating: target,
                    impersonator_id: SUPER_ADMIN.id,
                },
            }) },
            { match: /\/auth\/me$/, response: () => json(200, { data: {
                id: target.id,
                name: target.name,
                email: 'indoor.sales@example.test',
                is_active: true,
                role: { id: '01a0-role-indoor', slug: 'indoor_sales', name: 'Indoor Sales' },
                permissions: ['customer.view.own'],
                unconditional_access: false,
            } }) },
            LIST_OK,
            ROLES_OK,
        ]);

        await wrapper.findAll('[data-testid="users-login-as"]')[0]?.trigger('click');
        await flushPromises();

        expect(auth.isImpersonating.value).toBe(true);
        expect(auth.impersonating.value?.name).toBe('Nadia Indoor');
        // The whole session is the target's now, so the SPA leaves the
        // administration screen their role cannot enter.
        expect(router.currentRoute.value.name).not.toBe('users');

        // The profile refetch runs as the impersonated user, so it is the first
        // call that must carry the new credential. Located by URL rather than
        // by position — the landing redirect makes its own requests after it.
        const profileCall = fetchMock.mock.calls.filter((call) => String(call[0]).endsWith('/auth/me')).at(-1);
        const init = profileCall?.[1] as { headers?: Record<string, string> } | undefined;

        expect(init?.headers?.Authorization).toBe(`Bearer ${'b'.repeat(64)}`);
    });
});

describe('internationalisation', () => {
    it('renders every string from the lang files in both languages', async () => {
        for (const locale of ['en', 'ar'] as const) {
            const { wrapper } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK], locale);

            // vue-i18n renders the key itself when it is missing.
            expect(wrapper.text()).not.toMatch(/users\.(?:title|column|status|filter|pagination)/);
        }
    });

    it('writes no physical inline property that would break RTL', async () => {
        const { wrapper } = await mountUsers(SUPER_ADMIN, [LIST_OK, ROLES_OK], 'ar');

        expect(wrapper.html()).not.toMatch(/class="[^"]*\b(?:ml|mr|pl|pr)-\d/);
        expect(wrapper.html()).not.toMatch(/\btext-(?:left|right)\b/);
    });
});
