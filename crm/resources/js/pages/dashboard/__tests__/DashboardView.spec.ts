import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 14, slice 1 · 1.1 — the dashboard opens per role, no figures yet
 * (`D-145`, §12.1).
 *
 * Not an authorization suite: `DashboardMetaEndpointTest` proves which tabs a
 * role receives. Proved here is what the screen draws from `GET /dashboard/meta`:
 * only the tabs it returned, Overview first, the empty Procurement and Outdoor
 * tabs, and a period that opens on the current month and is remembered per device.
 */

// Resolved per test rather than imported, so that before the page exists every
// test fails on its own line instead of the whole file failing to load.
const pages = import.meta.glob<{ default: object }>('../DashboardView.vue');

async function dashboardView(): Promise<object> {
    const load = pages['../DashboardView.vue'];
    if (load === undefined) {
        throw new Error('resources/js/pages/dashboard/DashboardView.vue does not exist yet');
    }

    return (await load()).default;
}

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const USER: AuthenticatedUser = {
    id: 'm1', name: 'Mona Manager', email: 'manager@example.test',
    role: { id: 'r2', slug: 'manager', name: 'Manager' },
    permissions: ['dashboard.view.all', 'dashboard.drill_down.all', 'quotation.view_cost_and_margin.all'],
    is_active: true, unconditional_access: false,
};

const MANAGER_META = {
    scope: 'all', tabs: ['overview', 'sales', 'finance', 'procurement', 'outdoor'],
    drill_down: true, money: true, today: '2026-10-21', last_updated_at: null,
};

function meta(data: unknown = MANAGER_META): ReturnType<typeof vi.fn> {
    return vi.fn(async () => json(200, { data, meta: { request_id: 'r' } }));
}

async function render(fetchMock: ReturnType<typeof vi.fn>, locale = 'en') {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: USER },
            }))
            : (fetchMock as unknown as typeof fetch)(input, init));
    await useAuth().login(USER.email, 'Passw0rd123');
    vi.stubGlobal('fetch', fetchMock);

    const router = createAppRouter();
    await router.push('/dashboard');
    await router.isReady();

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(await dashboardView(), { global: { plugins: [i18n, router] } });
    await flushPromises();

    return wrapper;
}

const tabNames = (wrapper: Awaited<ReturnType<typeof render>>) =>
    wrapper.findAll('[data-testid^="dashboard-tab-"]').map((tab) => tab.attributes('data-testid')!.replace('dashboard-tab-', ''));

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-21T09:00:00Z'));
    localStorage.clear();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('the dashboard route', () => {
    it('exists and needs dashboard.view', () => {
        const router = createAppRouter();

        expect(router.hasRoute('dashboard')).toBe(true);
        expect(router.resolve({ name: 'dashboard' }).meta.requiredPermission).toBe('dashboard.view');
    });
});

describe('DashboardView', () => {
    it('reads its tabs from GET /dashboard/meta', async () => {
        const fetchMock = meta();
        await render(fetchMock);

        expect(fetchMock.mock.calls.some(([input]) => /\/api\/v1\/dashboard\/meta$/.test(String(input)))).toBe(true);
    });

    it('draws only the tabs the API returned, Overview open first', async () => {
        const wrapper = await render(meta({ ...MANAGER_META, scope: 'team', tabs: ['overview', 'sales', 'outdoor'], money: false }));

        expect(tabNames(wrapper)).toEqual(['overview', 'sales', 'outdoor']);
        expect(wrapper.get('[data-testid="dashboard-tab-overview"]').attributes('aria-selected')).toBe('true');
    });

    it('shows Procurement as an empty state naming its module, never a number', async () => {
        const wrapper = await render(meta());
        await wrapper.get('[data-testid="dashboard-tab-procurement"]').trigger('click');

        const empty = wrapper.get('[data-testid="dashboard-empty-procurement"]');
        expect(empty.text()).toContain('Available once Procurement is live');
        expect(empty.text()).not.toMatch(/\d/);
    });

    it('shows Outdoor as an empty state in Arabic too', async () => {
        const wrapper = await render(meta(), 'ar');
        await wrapper.get('[data-testid="dashboard-tab-outdoor"]').trigger('click');

        expect(wrapper.get('[data-testid="dashboard-empty-outdoor"]').text()).toContain('سيتوفر بعد تفعيل وحدة الزيارات');
    });

    it('opens on the current month in company time', async () => {
        const wrapper = await render(meta());

        expect((wrapper.get('[data-testid="dashboard-period-kind"]').element as HTMLSelectElement).value).toBe('month');
        expect(wrapper.get('[data-testid="dashboard-period-range"]').attributes('data-from')).toBe('2026-10-01');
        expect(wrapper.get('[data-testid="dashboard-period-range"]').attributes('data-to')).toBe('2026-10-31');
    });

    it('remembers the chosen period on this device', async () => {
        const first = await render(meta());
        await first.get('[data-testid="dashboard-period-kind"]').setValue('year');
        first.unmount();

        const second = await render(meta());

        expect((second.get('[data-testid="dashboard-period-kind"]').element as HTMLSelectElement).value).toBe('year');
        expect(second.get('[data-testid="dashboard-period-range"]').attributes('data-from')).toBe('2026-01-01');
    });

    it('still opens on the current month when browser storage throws', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new DOMException('denied', 'SecurityError'); });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new DOMException('denied', 'SecurityError'); });

        const wrapper = await render(meta());

        expect((wrapper.get('[data-testid="dashboard-period-kind"]').element as HTMLSelectElement).value).toBe('month');
    });

    it('shows an error state when the meta request fails', async () => {
        const wrapper = await render(vi.fn(async () => json(500, { error: { code: 'server_error', message: 'x' } })));

        expect(wrapper.find('[data-testid="dashboard-error"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid^="dashboard-tab-"]')).toHaveLength(0);
    });
});
