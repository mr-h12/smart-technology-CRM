import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';

/**
 * Point 5.2 — `SEC-10`'s banner.
 *
 * The most important component of the Login As feature, and not because it is
 * complicated. An impersonation session runs with the target's id, role and
 * permissions, so every screen looks exactly as it does for that employee. The
 * audit trail records the truth either way (Point 3.4's
 * `impersonated_user_id`); this is what keeps the Super Admin from being the
 * last to know.
 */

const TARGET = { id: '01a0-indoor', name: 'Nadia Indoor', role: 'indoor_sales' };

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

async function mountBanner(options: { impersonating: boolean; leaveStatus?: number; locale?: 'ar' | 'en' }) {
    vi.resetModules();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/');

    const fetchMock = vi.fn((input: string, _init?: RequestInit) => {
        if (String(input).endsWith('/auth/impersonate/leave')) {
            const status = options.leaveStatus ?? 200;

            return Promise.resolve(status === 200
                ? json(200, { data: { impersonation_ended: true, resume_with_original_token: true } })
                : json(status, { error: { code: 'business_rule_blocked', message: 'x' } }));
        }

        if (String(input).endsWith('/auth/me')) {
            return Promise.resolve(json(200, { data: {
                id: '01a0-sa',
                name: 'Test Super Admin',
                email: 'super.admin@example.test',
                is_active: true,
                role: { id: '01a0-role-sa', slug: 'super_admin', name: 'Super Admin' },
                permissions: [],
                unconditional_access: true,
            } }));
        }

        return Promise.resolve(json(200, { data: {} }));
    });
    vi.stubGlobal('fetch', fetchMock);

    const auth = await import('@/stores/auth');

    if (options.impersonating) {
        // Seeded through the documented storage keys rather than by calling
        // startImpersonation(), so the test also covers the reload path: a page
        // refresh mid-impersonation must still draw the banner, or the Super
        // Admin loses the only signal and the parked token with it.
        window.localStorage.setItem(auth.TOKEN_STORAGE_KEY, 'b'.repeat(64));
        window.localStorage.setItem(auth.IMPERSONATOR_TOKEN_STORAGE_KEY, 'a'.repeat(64));
        window.localStorage.setItem(auth.IMPERSONATION_STORAGE_KEY, JSON.stringify(TARGET));
        vi.resetModules();
    }

    const { createAppRouter } = await import('@/router');
    const router = createAppRouter();
    await router.push('/');
    await router.isReady();

    const store = await import('@/stores/auth');
    const Banner = (await import('@/components/identity/ImpersonationBanner.vue')).default;

    const wrapper = mount(Banner, {
        global: {
            plugins: [
                router,
                createI18n({ legacy: false, locale: options.locale ?? 'en', fallbackLocale: 'en', messages: { ar, en } }),
            ],
        },
    });

    await flushPromises();

    return { wrapper, fetchMock, auth: store.useAuth(), store, router };
}

beforeEach(() => {
    window.localStorage.clear();
});

describe('the impersonation banner', () => {
    it('draws nothing on an ordinary session', async () => {
        const { wrapper } = await mountBanner({ impersonating: false });

        expect(wrapper.find('[data-testid="impersonation-banner"]').exists()).toBe(false);
    });

    it('appears during a Login As and names the person and the role', async () => {
        const { wrapper } = await mountBanner({ impersonating: true });

        const banner = wrapper.find('[data-testid="impersonation-banner"]');

        expect(banner.exists()).toBe(true);
        expect(banner.text()).toContain('Nadia Indoor');
        expect(banner.text()).toContain('indoor_sales');
    });

    it('survives a page reload, because the state is in storage and not only in memory', async () => {
        // Without this, refreshing mid-impersonation loses both the banner and
        // the parked Super Admin token, and the only way out is D-29's eight
        // idle hours.
        const { auth } = await mountBanner({ impersonating: true });

        expect(auth.isImpersonating.value).toBe(true);
    });

    it('cannot be dismissed', async () => {
        // A banner with a close button is a banner that is closed, and the
        // hazard lasts until the session does.
        const { wrapper } = await mountBanner({ impersonating: true });

        const buttons = wrapper.findAll('button');

        expect(buttons).toHaveLength(1);
        expect(buttons[0]?.attributes('data-testid')).toBe('impersonation-leave');
    });

    it('is announced, not only coloured', async () => {
        // §9.5: a state must be legible without relying on colour alone.
        const { wrapper } = await mountBanner({ impersonating: true });

        const banner = wrapper.find('[data-testid="impersonation-banner"]');

        expect(banner.attributes('role')).toBe('status');
        expect(banner.attributes('aria-live')).toBe('polite');
    });

    it('leaves on the impersonation token and restores the parked one', async () => {
        const { wrapper, fetchMock, auth, store } = await mountBanner({ impersonating: true });

        await wrapper.find('[data-testid="impersonation-leave"]').trigger('click');
        await flushPromises();

        const leaveCall = fetchMock.mock.calls.find((call) => String(call[0]).endsWith('/auth/impersonate/leave'));
        const init = leaveCall?.[1] as { headers?: Record<string, string> } | undefined;

        // The request must go out as the impersonation, because that is the
        // session the endpoint revokes. Sending it as the Super Admin would be
        // 422 not_impersonating.
        expect(init?.headers?.Authorization).toBe(`Bearer ${'b'.repeat(64)}`);

        expect(auth.isImpersonating.value).toBe(false);
        expect(window.localStorage.getItem(store.TOKEN_STORAGE_KEY)).toBe('a'.repeat(64));
        expect(window.localStorage.getItem(store.IMPERSONATOR_TOKEN_STORAGE_KEY)).toBeNull();
        expect(window.localStorage.getItem(store.IMPERSONATION_STORAGE_KEY)).toBeNull();
    });

    it('restores the session even when the leave call fails', async () => {
        // Same reasoning as logout: a leave that fails must not trap somebody
        // inside another person's account.
        const { wrapper, auth, store } = await mountBanner({ impersonating: true, leaveStatus: 422 });

        await wrapper.find('[data-testid="impersonation-leave"]').trigger('click');
        await flushPromises();

        expect(auth.isImpersonating.value).toBe(false);
        expect(window.localStorage.getItem(store.TOKEN_STORAGE_KEY)).toBe('a'.repeat(64));
    });

    it('renders its strings in both languages', async () => {
        for (const locale of ['en', 'ar'] as const) {
            const { wrapper } = await mountBanner({ impersonating: true, locale });

            expect(wrapper.text()).not.toMatch(/impersonation\./);
        }
    });
});
