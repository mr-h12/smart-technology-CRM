import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import ReportsView from '@/pages/reports/ReportsView.vue';
import { NAVIGATION } from '@/navigation';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 13, first slice — "My reports": the author's own reports and the
 * button that starts today's daily report (RPT-DY).
 *
 * Not an authorization suite: `ReportWriteEndpointTest` proves who may list and
 * create against the server. Proved here is what the screen draws and sends.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const PAGINATION = { page: 1, per_page: 25, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false };

const USER: AuthenticatedUser = {
    id: 'u1', name: 'Test Sales', email: 'indoor.sales@example.test',
    role: { id: 'r1', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['report.create.own'],
    is_active: true, unconditional_access: false,
};

const REPORT = {
    id: 'rep1', code: 'RPT-DY-2026-0001', type: 'daily', status: 'draft',
    period_start: '2026-10-15', period_end: '2026-10-15', submitted_at: null,
};

function list(reports: unknown[]): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string, init?: RequestInit) => {
        if (init?.method === 'POST') {
            return json(201, { data: { id: 'rep2', code: 'RPT-DY-2026-0002' }, meta: { request_id: 'r' } });
        }
        if (/\/reports\/rep2$/.test(String(input))) {
            return json(404, { error: { code: 'resource_not_found' }, meta: { request_id: 'r' } });
        }

        return json(200, { data: reports, meta: { request_id: 'r', pagination: { ...PAGINATION, total: reports.length } } });
    });
}

async function render(fetchMock: ReturnType<typeof vi.fn>, locale = 'en') {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: USER },
            }))
            : fetchMock(input, init));
    await useAuth().login(USER.email, 'Passw0rd123');
    vi.stubGlobal('fetch', fetchMock);

    const router = createAppRouter();
    await router.push('/reports');
    await router.isReady();

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(ReportsView, { global: { plugins: [i18n, router] } });
    await flushPromises();

    return { wrapper, router };
}

describe('ReportsView — My reports', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('is a route behind report.create, with a sidebar item that names the same permission', () => {
        const route = createAppRouter().getRoutes().find((r) => r.name === 'reports');
        expect(route?.path).toBe('/reports');
        expect(route?.meta.requiredPermission).toBe('report.create');

        const detail = createAppRouter().getRoutes().find((r) => r.name === 'report-detail');
        expect(detail?.path).toBe('/reports/:id');
        expect(detail?.meta.requiredPermission).toBe('report.create');

        const item = NAVIGATION.flatMap((group) => group.items).find((i) => i.name === 'reports');
        expect(item?.permission).toBe('report.create');
    });

    it('lists the author\'s reports with their code, status and day, each linking to its page', async () => {
        const { wrapper } = await render(list([REPORT]));

        const rows = wrapper.findAll('[data-testid="report-row"]');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('RPT-DY-2026-0001');
        expect(rows[0].text()).toContain(en.reports.status.draft);
        expect(rows[0].text()).toContain(en.reports.type.daily);
        expect(rows[0].find('a').attributes('href')).toBe('/reports/rep1');
    });

    it('shows the empty state when the author has no report yet', async () => {
        const { wrapper } = await render(list([]));

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="report-row"]').exists()).toBe(false);
    });

    it('shows the error state with a retry when the list fails', async () => {
        const fetchMock = vi.fn(async () => json(500, { error: { code: 'server_error' }, meta: { request_id: 'r' } }));
        const { wrapper } = await render(fetchMock);

        expect(wrapper.find('[data-testid="error-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="error-retry"]').exists()).toBe(true);
    });

    it('starts today\'s daily report with an Idempotency-Key and opens it', async () => {
        const fetchMock = list([]);
        const { wrapper, router } = await render(fetchMock);

        await wrapper.get('[data-testid="reports-new"]').trigger('click');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'POST');
        expect(post).toBeDefined();
        expect(String(post![0])).toMatch(/\/reports$/);
        const init = post![1] as RequestInit;
        expect(JSON.parse(init.body as string)).toEqual({ type: 'daily' });
        expect(new Headers(init.headers).get('Idempotency-Key')).toMatch(/^[0-9a-f-]{36}$/);
        expect(router.currentRoute.value.fullPath).toBe('/reports/rep2');
    });

    it('draws its heading and labels in Arabic', async () => {
        const { wrapper } = await render(list([REPORT]), 'ar');

        expect(wrapper.text()).toContain(ar.reports.title);
        expect(wrapper.get('[data-testid="report-row"]').text()).toContain(ar.reports.status.draft);
        expect(wrapper.get('[data-testid="reports-new"]').text()).toContain(ar.reports.new_daily);
    });
});
