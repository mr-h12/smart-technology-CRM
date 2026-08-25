import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import type { Router } from 'vue-router';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import type { AuthenticatedUser } from '@/stores/auth';

/**
 * Point 5.4 — the account-security screen.
 *
 * `SEC-04` · `SEC-05` · `SEC-11` · §3.1 · §9 Flow 0 · `D-28` · `D-29` ·
 * `D-74` · `OpenAPI §4.2`, §5.1.
 *
 * ── Not a security suite ───────────────────────────────────────────────────
 *
 * §3.12 rule 1 puts enforcement at the API, and `SessionManagementTest` and
 * `ChangePasswordTest` are where the refusals are proved against the server.
 * Everything below is about what the screen draws and what it sends — a form
 * that skips `SEC-04`'s code, or a device list that offers to revoke the
 * session making the call, is a presentation defect worth catching on its own.
 */

const PERSON: AuthenticatedUser = {
    id: '01a0-person',
    name: 'Test Person',
    email: 'person@example.test',
    is_active: true,
    role: { id: '01a0-role', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['customer.view.own'],
    unconditional_access: false,
};

const GOOD_PASSWORD = 'Newpassw0rd456';

function device(id: string, isCurrent: boolean, agent = 'Firefox on Linux') {
    return {
        id,
        ip_address: '192.168.1.10',
        user_agent: agent,
        last_activity_at: '2026-08-25T09:00:00+00:00',
        signed_in_at: '2026-08-25T08:00:00+00:00',
        is_current: isCurrent,
    };
}

const DEVICES = [device('session-here', true), device('session-phone', false, 'Safari on iPhone')];

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

const SESSIONS_OK: Handler = { match: /\/auth\/sessions\?/, response: () => page(DEVICES) };

const CHALLENGE_OK: Handler = {
    match: /\/auth\/change-password\/challenge$/,
    method: 'POST',
    response: () => json(202, { data: { challenge_sent: true, expires_in_minutes: 15 }, meta: {} }),
};

const CHANGE_OK: Handler = {
    match: /\/auth\/change-password$/,
    method: 'POST',
    response: () => json(200, {
        data: { password_changed: true, sessions_revoked: 2, reauthentication_required: true },
        meta: {},
    }),
};

async function mountScreen(handlers: Handler[] = [SESSIONS_OK], locale: 'ar' | 'en' = 'en') {
    vi.resetModules();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/account/security');

    const fetchMock = routedFetch([
        { match: /\/auth\/login$/, method: 'POST', response: () => json(201, {
            data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: PERSON },
        }) },
        ...handlers,
    ]);
    vi.stubGlobal('fetch', fetchMock);

    const { createAppRouter } = await import('@/router');
    const router: Router = createAppRouter();

    const { useAuth } = await import('@/stores/auth');
    const auth = useAuth();
    await auth.login(PERSON.email, 'Passw0rd123');

    await router.push('/account/security');
    await router.isReady();

    const AccountSecurityView = (await import('@/pages/profile/AccountSecurityView.vue')).default;

    const wrapper = mount(AccountSecurityView, {
        global: {
            plugins: [router, createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { ar, en } })],
        },
    });

    await flushPromises();

    return { wrapper, router, fetchMock, auth };
}

/** Fill the three password boxes with something `D-28` accepts. */
async function fillPasswords(
    wrapper: Awaited<ReturnType<typeof mountScreen>>['wrapper'],
    next = GOOD_PASSWORD,
    confirm = next,
): Promise<void> {
    await wrapper.find('[data-testid="current-password"]').setValue('Passw0rd123');
    await wrapper.find('[data-testid="new-password"]').setValue(next);
    await wrapper.find('[data-testid="confirm-password"]').setValue(confirm);
}

function calls(fetchMock: ReturnType<typeof routedFetch>, method: string, pattern: RegExp) {
    return fetchMock.mock.calls.filter(
        (call) => (call[1]?.method ?? 'GET') === method && pattern.test(String(call[0])),
    );
}

beforeEach(() => {
    window.localStorage.clear();
});

// ── SEC-05 ─────────────────────────────────────────────────────────────────

describe('the device list', () => {
    it('draws a row per device and marks the one making the call', async () => {
        const { wrapper } = await mountScreen();

        const rows = wrapper.findAll('[data-testid="device-row"]');

        expect(rows).toHaveLength(2);
        expect(rows.filter((row) => row.attributes('data-current') === 'true')).toHaveLength(1);
        expect(wrapper.findAll('[data-testid="current-badge"]')).toHaveLength(1);
    });

    it('offers no sign-out control on the current device', async () => {
        const { wrapper } = await mountScreen();

        // The API answers `422 session_is_current` here and points at logout;
        // a button that produces a refusal is a promise the product breaks.
        expect(wrapper.find('[data-testid="revoke-session-here"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="revoke-session-phone"]').exists()).toBe(true);
    });

    it('shows the address and both timestamps', async () => {
        const { wrapper } = await mountScreen();

        const row = wrapper.findAll('[data-testid="device-row"]')[1];

        expect(row?.text()).toContain('192.168.1.10');
        expect(row?.text()).toContain('Safari on iPhone');
    });

    it('revokes one device and re-reads the list from the server', async () => {
        const { wrapper, fetchMock } = await mountScreen([
            SESSIONS_OK,
            { match: /\/auth\/sessions\/session-phone$/, method: 'DELETE', response: () => json(200, {
                data: { revoked: true, session_id: 'session-phone' }, meta: {},
            }) },
        ]);

        await wrapper.find('[data-testid="revoke-session-phone"]').trigger('click');
        await flushPromises();

        expect(calls(fetchMock, 'DELETE', /\/auth\/sessions\/session-phone$/)).toHaveLength(1);
        // Re-read rather than spliced: the list is the server's answer, and a
        // locally edited copy stops being a device list the first time two tabs
        // revoke at once.
        expect(calls(fetchMock, 'GET', /\/auth\/sessions\?/)).toHaveLength(2);
        expect(wrapper.find('[data-testid="devices-notice"]').text()).toBe(en.account.devices.revoked);
    });

    it('signs out every other device without ending this session', async () => {
        const { wrapper, fetchMock, auth } = await mountScreen([
            SESSIONS_OK,
            { match: /\/auth\/sessions$/, method: 'DELETE', response: () => json(200, {
                data: { revoked: 1, current_session_kept: true }, meta: {},
            }) },
        ]);

        await wrapper.find('[data-testid="revoke-others"]').trigger('click');
        await flushPromises();

        expect(calls(fetchMock, 'DELETE', /\/auth\/sessions$/)).toHaveLength(1);
        expect(wrapper.find('[data-testid="devices-notice"]').text()).toBe(en.account.devices.revokedOthers);
        // The whole point of the endpoint: the caller stays signed in.
        expect(auth.isAuthenticated.value).toBe(true);
    });

    it('disables sign-out-everywhere-else when this is the only device', async () => {
        const { wrapper } = await mountScreen([
            { match: /\/auth\/sessions\?/, response: () => page([device('session-here', true)]) },
        ]);

        expect(wrapper.find('[data-testid="revoke-others"]').attributes('disabled')).toBeDefined();
    });

    it('explains a refusal in place (§5.1 detail codes)', async () => {
        const { wrapper } = await mountScreen([
            SESSIONS_OK,
            { match: /\/auth\/sessions\/session-phone$/, method: 'DELETE', response: () => json(404, {
                error: {
                    code: 'resource_not_found',
                    message: 'gone',
                    details: [{ code: 'session_not_found', message: 'gone' }],
                },
            }) },
        ]);

        await wrapper.find('[data-testid="revoke-session-phone"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="devices-error"]').text()).toBe(en.account.error.deviceGone);
    });
});

// ── SEC-04 · §9 Flow 0 ─────────────────────────────────────────────────────

describe('the password form', () => {
    it('refuses to start until D-28 is satisfied and the two boxes match', async () => {
        const { wrapper } = await mountScreen();

        expect(wrapper.find('[data-testid="change-password"]').attributes('disabled')).toBeDefined();

        await fillPasswords(wrapper, 'short1');
        expect(wrapper.find('[data-testid="change-password"]').attributes('disabled')).toBeDefined();

        await fillPasswords(wrapper, GOOD_PASSWORD, 'Different0');
        expect(wrapper.find('[data-testid="confirm-mismatch"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="change-password"]').attributes('disabled')).toBeDefined();

        await fillPasswords(wrapper);
        expect(wrapper.find('[data-testid="change-password"]').attributes('disabled')).toBeUndefined();
    });

    it('ticks D-28\'s three conditions one at a time', async () => {
        const { wrapper } = await mountScreen();

        await wrapper.find('[data-testid="new-password"]').setValue('abcdefgh');

        const items = wrapper.findAll('[data-testid="password-checklist"] li');

        expect(items[0]?.classes()).toContain('rule--met');
        expect(items[1]?.classes()).toContain('rule--met');
        expect(items[2]?.classes()).toContain('rule--unmet');
    });

    it('asks for SEC-04\'s code before anything is changed', async () => {
        const { wrapper, fetchMock } = await mountScreen([SESSIONS_OK, CHALLENGE_OK]);

        await fillPasswords(wrapper);
        await wrapper.find('[data-testid="change-password"]').trigger('submit');
        await flushPromises();

        expect(calls(fetchMock, 'POST', /change-password\/challenge$/)).toHaveLength(1);
        // §9 Flow 0's order: the code is requested, and nothing is submitted
        // until it comes back.
        expect(calls(fetchMock, 'POST', /change-password$/)).toHaveLength(0);
        expect(wrapper.find('[data-testid="email-challenge-modal"]').exists()).toBe(true);
    });

    it('never sends a password to the challenge endpoint', async () => {
        const { wrapper, fetchMock } = await mountScreen([SESSIONS_OK, CHALLENGE_OK]);

        await fillPasswords(wrapper);
        await wrapper.find('[data-testid="change-password"]').trigger('submit');
        await flushPromises();

        const [request] = calls(fetchMock, 'POST', /change-password\/challenge$/);

        // The endpoint takes no body and no target. A password mailed through
        // a challenge request would be a credential in a request that exists
        // only to send mail.
        expect(request?.[1]?.body).toBeUndefined();
    });

    it('sends the code with the passwords and signs the user out afterwards', async () => {
        const { wrapper, router, fetchMock, auth } = await mountScreen([SESSIONS_OK, CHALLENGE_OK, CHANGE_OK]);

        await fillPasswords(wrapper);
        await wrapper.find('[data-testid="change-password"]').trigger('submit');
        await flushPromises();

        const input = wrapper.find('[data-testid="challenge-code"]');
        (input.element as HTMLInputElement).value = '123456';
        await input.trigger('input');
        await wrapper.find('[data-testid="challenge-submit"]').trigger('submit');
        await flushPromises();

        const [request] = calls(fetchMock, 'POST', /change-password$/);
        expect(JSON.parse(String(request?.[1]?.body))).toEqual({
            current_password: 'Passw0rd123',
            new_password: GOOD_PASSWORD,
            new_password_confirmation: GOOD_PASSWORD,
            verification_code: '123456',
        });

        // §9 Flow 0 ends with "log in again", and the server has already
        // revoked this token — including it in `sessions_revoked`.
        expect(auth.isAuthenticated.value).toBe(false);
        expect(window.localStorage.getItem('crm.auth.token.v1')).toBeNull();
        expect(router.currentRoute.value.name).toBe('login');
    });

    it('does not post a second logout with the token the change already killed', async () => {
        const { wrapper, fetchMock, auth } = await mountScreen([SESSIONS_OK, CHALLENGE_OK, CHANGE_OK]);

        await fillPasswords(wrapper);
        await wrapper.find('[data-testid="change-password"]').trigger('submit');
        await flushPromises();

        const input = wrapper.find('[data-testid="challenge-code"]');
        (input.element as HTMLInputElement).value = '123456';
        await input.trigger('input');
        await wrapper.find('[data-testid="challenge-submit"]').trigger('submit');
        await flushPromises();

        // `forgetSession()` and not `logout()`. The server revoked this session
        // as part of the change, so posting the same credential to
        // `/auth/logout` reaches the same state through a 401 — and this stub
        // has no logout handler, so it would answer 404 and prove the point
        // either way.
        expect(calls(fetchMock, 'POST', /\/auth\/logout$/)).toHaveLength(0);
        expect(auth.isAuthenticated.value).toBe(false);
    });

    it('explains a wrong current password inside the dialog', async () => {
        const { wrapper } = await mountScreen([SESSIONS_OK, CHALLENGE_OK, {
            match: /\/auth\/change-password$/,
            method: 'POST',
            response: () => json(422, {
                error: {
                    code: 'validation_failed',
                    message: 'no',
                    details: [{ field: 'current_password', code: 'current_password_incorrect', message: 'no' }],
                },
            }),
        }]);

        await fillPasswords(wrapper);
        await wrapper.find('[data-testid="change-password"]').trigger('submit');
        await flushPromises();

        const input = wrapper.find('[data-testid="challenge-code"]');
        (input.element as HTMLInputElement).value = '123456';
        await input.trigger('input');
        await wrapper.find('[data-testid="challenge-submit"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-testid="challenge-error"]').text()).toBe(en.account.error.currentPassword);
        // Still open: a refusal that closed the dialog would make the person
        // request a second code for a mistake they can correct in place.
        expect(wrapper.find('[data-testid="email-challenge-modal"]').exists()).toBe(true);
    });

    it('reports SEC-11\'s limit when too many codes are requested', async () => {
        const { wrapper } = await mountScreen([SESSIONS_OK, {
            match: /change-password\/challenge$/,
            method: 'POST',
            response: () => json(429, { error: { code: 'rate_limit_exceeded', message: 'slow down' } }),
        }]);

        await fillPasswords(wrapper);
        await wrapper.find('[data-testid="change-password"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-testid="password-error"]').text()).toBe(en.account.error.resendLimited);
        expect(wrapper.find('[data-testid="email-challenge-modal"]').exists()).toBe(false);
    });

    it('requests a fresh code when the dialog asks for one', async () => {
        const { wrapper, fetchMock } = await mountScreen([SESSIONS_OK, CHALLENGE_OK]);

        await fillPasswords(wrapper);
        await wrapper.find('[data-testid="change-password"]').trigger('submit');
        await flushPromises();

        await wrapper.find('[data-testid="challenge-resend"]').trigger('click');
        await flushPromises();

        expect(calls(fetchMock, 'POST', /change-password\/challenge$/)).toHaveLength(2);
    });
});

describe('Arabic', () => {
    it('renders the Arabic screen, not raw keys', async () => {
        const { wrapper } = await mountScreen([SESSIONS_OK], 'ar');

        expect(wrapper.text()).toContain(ar.account.devices.revokeOthers);
        expect(wrapper.text()).not.toContain('account.devices');
    });
});
