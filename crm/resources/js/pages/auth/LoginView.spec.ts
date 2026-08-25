import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import type { Router } from 'vue-router';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import type { AuthenticatedUser } from '@/stores/auth';

/**
 * Point 5.1 — §9 Flow 0's login screen.
 *
 * `SEC-01` · `SEC-03` · `D-28` · §10.1 · Coding Standards §11.
 *
 * The real locale files are loaded rather than a stub dictionary, so a key the
 * template asks for and the JSON does not carry fails here instead of rendering
 * the raw key in front of a user.
 */

const PROFILE: AuthenticatedUser = {
    id: '01a0-user',
    name: 'Test Indoor Sales',
    email: 'indoor.sales@example.test',
    is_active: true,
    role: { id: '01a0-role', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['customer.view.own'],
    unconditional_access: false,
};

function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

async function mountLogin(response: Response, locale: 'ar' | 'en' = 'en') {
    vi.resetModules();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/login');

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response));

    const { createAppRouter } = await import('@/router');
    const router: Router = createAppRouter();
    await router.push('/login');
    await router.isReady();

    const LoginView = (await import('@/pages/auth/LoginView.vue')).default;

    const wrapper = mount(LoginView, {
        global: {
            plugins: [router, createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { ar, en } })],
        },
    });

    return { wrapper, router };
}

async function submit(wrapper: Awaited<ReturnType<typeof mountLogin>>['wrapper'], email: string, password: string) {
    await wrapper.find('[data-testid="login-email"]').setValue(email);
    await wrapper.find('[data-testid="login-password"]').setValue(password);
    await wrapper.find('[data-testid="login-form"]').trigger('submit');
    await new Promise((resolve) => setTimeout(resolve, 0));
    await wrapper.vm.$nextTick();
}

beforeEach(() => {
    window.localStorage.clear();
});

describe('the login screen', () => {
    it('signs in and leaves for the landing screen', async () => {
        const { wrapper, router } = await mountLogin(jsonResponse(201, {
            data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: PROFILE },
        }));

        await submit(wrapper, 'indoor.sales@example.test', 'Passw0rd123');

        expect(router.currentRoute.value.name).not.toBe('login');
        expect(wrapper.find('[data-testid="login-error"]').exists()).toBe(false);
    });

    it('shows §10.1\'s suspended sentence word for word', async () => {
        const { wrapper } = await mountLogin(jsonResponse(403, {
            error: {
                code: 'permission_denied',
                message: 'from the server',
                details: [{ code: 'account_suspended', message: 'from the server' }],
            },
        }));

        await submit(wrapper, 'someone@example.test', 'Passw0rd123');

        // §10.1: Login → Blocked — "Account suspended, please contact
        // administration". The wording is an acceptance criterion, not a
        // stylistic choice.
        expect(wrapper.find('[data-testid="login-error"]').text())
            .toBe('Account suspended, please contact administration.');
    });

    it('tells a locked account apart from a wrong password (SEC-03)', async () => {
        const { wrapper } = await mountLogin(jsonResponse(423, {
            error: { code: 'account_locked', message: 'x', details: [{ code: 'account_locked', message: 'x' }] },
        }));

        await submit(wrapper, 'someone@example.test', 'Passw0rd123');

        expect(wrapper.find('[data-testid="login-error"]').text()).toContain('locked');
    });

    it('clears the password field after a refusal', async () => {
        const { wrapper } = await mountLogin(jsonResponse(401, {
            error: { code: 'authentication_required', message: 'x', details: [{ code: 'invalid_credentials', message: 'x' }] },
        }));

        await submit(wrapper, 'someone@example.test', 'Passw0rd123');

        const field = wrapper.find<HTMLInputElement>('[data-testid="login-password"]');

        expect(field.element.value).toBe('');
    });

    it('does not submit an empty form', async () => {
        const { wrapper } = await mountLogin(jsonResponse(201, { data: {} }));

        await wrapper.find('[data-testid="login-form"]').trigger('submit');
        await wrapper.vm.$nextTick();

        expect(vi.mocked(fetch)).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="login-submit"]').attributes('disabled')).toBeDefined();
    });

    it('offers no sign-up affordance (SEC-01)', async () => {
        const { wrapper } = await mountLogin(jsonResponse(201, { data: {} }));

        const html = wrapper.html().toLowerCase();

        // §9 Flow 0: "No sign-up. Accounts are created by the Manager or Super
        // Admin only." A reset link would be the same defect wearing a
        // different label — §9 Flow 0's reset is an authenticated flow.
        expect(html).not.toContain('href="/register"');
        expect(html).not.toContain('forgot');
        expect(wrapper.findAll('a')).toHaveLength(0);
    });

    it('renders every string from the lang files in both languages', async () => {
        for (const locale of ['en', 'ar'] as const) {
            const { wrapper } = await mountLogin(jsonResponse(201, { data: {} }), locale);

            // vue-i18n renders the key itself when it is missing, so a template
            // asking for something the JSON does not carry shows up as a dotted
            // key in the output.
            expect(wrapper.text()).not.toMatch(/auth\.login\./);
        }
    });

    it('writes no physical inline property that would break RTL', async () => {
        const { wrapper } = await mountLogin(jsonResponse(201, { data: {} }), 'ar');

        // LogicalPropertiesTest scans the source; this checks the rendered
        // class list, which is what a browser actually applies.
        expect(wrapper.html()).not.toMatch(/class="[^"]*\b(?:ml|mr|pl|pr)-\d/);
        expect(wrapper.html()).not.toMatch(/\btext-(?:left|right)\b/);
    });
});
