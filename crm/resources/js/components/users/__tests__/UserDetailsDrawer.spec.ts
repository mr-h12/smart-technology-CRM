import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';

/**
 * Point 5.5 — §13 screen 2's detail drawer.
 *
 * §3.11 · §3.12 rules 1 and 6 · `SEC-05` · `SEC-09` · `D-34` · `D-78` ·
 * `OpenAPI §4.2`, §5.1.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 puts enforcement at the API, and `AdminSessionInspectionTest`
 * proves the refusals against the server with 20 tests. What is checked here is
 * what the drawer draws and what it sends: a control it offers that the API
 * refuses, or a device it shows that should not exist, are presentation
 * defects worth catching on their own.
 */

const USER = {
    id: 'user-1',
    name: 'Layla Hassan',
    email: 'layla@example.test',
    role_id: 'role-1',
    role: { slug: 'indoor_sales', name: 'Indoor Sales' },
    is_active: true,
    created_at: '2026-08-01T08:00:00+00:00',
    updated_at: '2026-08-20T08:00:00+00:00',
};

function device(id: string, isCurrent: boolean, agent: string, lastActive: string) {
    return {
        id,
        ip_address: '10.0.0.5',
        user_agent: agent,
        last_activity_at: lastActive,
        signed_in_at: '2026-08-25T06:00:00+00:00',
        is_current: isCurrent,
    };
}

const DEVICES = [
    device('sess-laptop', false, 'Firefox on Linux', '2026-08-25T09:30:00+00:00'),
    device('sess-phone', false, 'Safari on iPhone', '2026-08-25T11:15:00+00:00'),
];

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function page(items: unknown[]): Response {
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
            },
        },
    });
}

interface Handler {
    match: RegExp;
    method?: string;
    response: () => Response;
}

function routedFetch(handlers: Handler[]) {
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

const PROFILE_OK: Handler = { match: /\/users\/user-1$/, response: () => json(200, { data: USER, meta: {} }) };
const SESSIONS_OK: Handler = { match: /\/users\/user-1\/sessions\?/, response: () => page(DEVICES) };

async function mountDrawer(handlers: Handler[] = [PROFILE_OK, SESSIONS_OK], props: Record<string, unknown> = {},
    locale: 'ar' | 'en' = 'en') {
    vi.resetModules();

    const fetchMock = routedFetch(handlers);
    vi.stubGlobal('fetch', fetchMock);

    const UserDetailsDrawer = (await import('@/components/users/UserDetailsDrawer.vue')).default;

    const wrapper = mount(UserDetailsDrawer, {
        props: { open: true, userId: 'user-1', canTerminate: true, ...props },
        global: {
            plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { ar, en } })],
        },
    });

    await flushPromises();

    return { wrapper, fetchMock };
}

function calls(fetchMock: ReturnType<typeof routedFetch>, method: string, pattern: RegExp) {
    return fetchMock.mock.calls.filter(
        (call) => (call[1]?.method ?? 'GET') === method && pattern.test(String(call[0])),
    );
}

beforeEach(() => {
    window.localStorage.clear();
});

describe('the drawer', () => {
    it('renders nothing at all while closed', async () => {
        const { wrapper } = await mountDrawer([PROFILE_OK, SESSIONS_OK], { open: false });

        expect(wrapper.find('[data-testid="user-details-drawer"]').exists()).toBe(false);
    });

    it('reads the profile and the devices, and joins nothing itself', async () => {
        const { wrapper, fetchMock } = await mountDrawer();

        expect(calls(fetchMock, 'GET', /\/users\/user-1$/)).toHaveLength(1);
        expect(calls(fetchMock, 'GET', /\/users\/user-1\/sessions\?/)).toHaveLength(1);
        expect(wrapper.find('[data-testid="details-profile"]').text()).toContain('Layla Hassan');
        expect(wrapper.find('[data-testid="details-profile"]').text()).toContain('Indoor Sales');
    });

    it('states the account status as a word, not only a colour (§9.5)', async () => {
        const { wrapper } = await mountDrawer();

        expect(wrapper.find('[data-testid="details-status"]').text()).toBe(en.users.status.active);
    });

    it('reports the newest activity across the devices, not the first row', async () => {
        const { wrapper } = await mountDrawer();

        // 11:15 is the phone, which is the *second* row. A drawer that read
        // `sessions[0]` would report a stale time and look right.
        expect(wrapper.find('[data-testid="details-last-activity"]').text()).toContain('11:15');
    });

    it('says so when the employee is signed in nowhere', async () => {
        const { wrapper } = await mountDrawer([
            PROFILE_OK,
            { match: /\/users\/user-1\/sessions\?/, response: () => page([]) },
        ]);

        // §8's empty state: "no devices" and "the list failed" must not look
        // the same, and neither may be a blank panel.
        expect(wrapper.find('[data-testid="details-no-devices"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="details-last-activity"]').text()).toBe(en.users.details.neverActive);
    });

    it('renders one row per device with its address', async () => {
        const { wrapper } = await mountDrawer();

        const rows = wrapper.findAll('[data-testid="details-device"]');

        expect(rows).toHaveLength(2);
        expect(rows[0]?.text()).toContain('Firefox on Linux');
        expect(rows[0]?.text()).toContain('10.0.0.5');
    });

    it('shows the error state when the read fails', async () => {
        const { wrapper } = await mountDrawer([
            { match: /\/users\/user-1$/, response: () => json(500, { error: { code: 'server_error', message: 'no' } }) },
        ]);

        expect(wrapper.find('[data-testid="details-devices"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="details-device"]').exists()).toBe(false);
    });
});

describe('the force logout', () => {
    it('asks before it ends anything', async () => {
        const { wrapper, fetchMock } = await mountDrawer();

        await wrapper.find('[data-testid="details-revoke-sess-phone"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true);
        // Nothing has been sent yet: the dialog is the question, not the
        // outcome.
        expect(calls(fetchMock, 'DELETE', /sessions/)).toHaveLength(0);
    });

    it('names the employee in the confirmation', async () => {
        const { wrapper } = await mountDrawer();

        await wrapper.find('[data-testid="details-revoke-sess-phone"]').trigger('click');

        expect(wrapper.find('#confirm-dialog-message').text()).toContain('Layla Hassan');
    });

    it('sends the termination to the administrative endpoint and re-reads', async () => {
        const { wrapper, fetchMock } = await mountDrawer([
            PROFILE_OK,
            SESSIONS_OK,
            { match: /\/users\/user-1\/sessions\/sess-phone$/, method: 'DELETE', response: () => json(200, {
                data: { revoked: true, session_id: 'sess-phone' }, meta: {},
            }) },
        ]);

        await wrapper.find('[data-testid="details-revoke-sess-phone"]').trigger('click');
        await wrapper.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        expect(calls(fetchMock, 'DELETE', /\/users\/user-1\/sessions\/sess-phone$/)).toHaveLength(1);
        // Re-read rather than spliced: the list is the server's answer.
        expect(calls(fetchMock, 'GET', /\/users\/user-1\/sessions\?/)).toHaveLength(2);
        expect(wrapper.find('[data-testid="details-notice"]').text()).toBe(en.users.details.terminated);
    });

    it('sends nothing when the confirmation is dismissed', async () => {
        const { wrapper, fetchMock } = await mountDrawer();

        await wrapper.find('[data-testid="details-revoke-sess-phone"]').trigger('click');
        await wrapper.find('[data-testid="confirm-cancel"]').trigger('click');
        await flushPromises();

        expect(calls(fetchMock, 'DELETE', /sessions/)).toHaveLength(0);
    });

    it('offers no control at all without §3.11\'s deactivate row', async () => {
        const { wrapper } = await mountDrawer([PROFILE_OK, SESSIONS_OK], { canTerminate: false });

        // SEC-09: the visual complement of a refusal the API makes anyway.
        expect(wrapper.find('[data-testid="details-revoke-sess-phone"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="details-device"]')).toHaveLength(2);
    });

    it('offers no control on the administrator\'s own current device', async () => {
        const { wrapper } = await mountDrawer([
            PROFILE_OK,
            { match: /\/users\/user-1\/sessions\?/, response: () => page([
                device('sess-mine', true, 'Chrome on macOS', '2026-08-25T12:00:00+00:00'),
                DEVICES[1],
            ]) },
        ]);

        // The API answers `422 session_is_current` and points at logout; a
        // button here would be an offer of a refusal.
        expect(wrapper.find('[data-testid="details-revoke-sess-mine"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="details-revoke-sess-phone"]').exists()).toBe(true);
    });

    it('explains §5.1\'s stable codes in place', async () => {
        const { wrapper } = await mountDrawer([
            PROFILE_OK,
            SESSIONS_OK,
            { match: /\/users\/user-1\/sessions\/sess-phone$/, method: 'DELETE', response: () => json(404, {
                error: {
                    code: 'resource_not_found',
                    message: 'gone',
                    details: [{ code: 'session_not_found', message: 'gone' }],
                },
            }) },
        ]);

        await wrapper.find('[data-testid="details-revoke-sess-phone"]').trigger('click');
        await wrapper.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="details-error"]').text()).toBe(en.users.details.error.gone);
    });
});

describe('Arabic', () => {
    it('renders the Arabic drawer, not raw keys', async () => {
        const { wrapper } = await mountDrawer([PROFILE_OK, SESSIONS_OK], {}, 'ar');

        expect(wrapper.find('#user-details-title').text()).toBe(ar.users.details.title);
        expect(wrapper.text()).not.toContain('users.details');
    });
});
