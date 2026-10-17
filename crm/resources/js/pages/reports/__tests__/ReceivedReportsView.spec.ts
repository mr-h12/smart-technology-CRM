import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import ReceivedReportsView from '@/pages/reports/ReceivedReportsView.vue';
import { NAVIGATION } from '@/navigation';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 13, slice 2 — "Received reports": the reports sent to the caller
 * (`GET /reports/received`), for the holders of §3.10's *view received*.
 *
 * Not an authorization suite: `ReportReceiveEndpointTest` proves who may list
 * against the server. Proved here is what the screen draws.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const PAGINATION = { page: 1, per_page: 25, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false };

const USER: AuthenticatedUser = {
    id: 'm1', name: 'Mona Manager', email: 'manager@example.test',
    role: { id: 'r2', slug: 'manager', name: 'Manager' },
    permissions: ['report.create.all', 'report.view_received.all', 'report.acknowledge.all', 'report.return_report.all'],
    is_active: true, unconditional_access: false,
};

const RECEIVED = {
    id: 'rep1', code: 'RPT-DY-2026-0001', type: 'daily', status: 'submitted', author_name: 'Test Sales',
    period_start: '2026-10-15', period_end: '2026-10-15', submitted_at: '2026-10-15T09:00:00Z',
};

function list(reports: unknown[]): ReturnType<typeof vi.fn> {
    return vi.fn(async () => json(200, { data: reports, meta: { request_id: 'r', pagination: { ...PAGINATION, total: reports.length } } }));
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
    await router.push('/reports/received');
    await router.isReady();

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(ReceivedReportsView, { global: { plugins: [i18n, router] } });
    await flushPromises();

    return wrapper;
}

describe('ReceivedReportsView — Received reports', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('is a route behind report.view_received, with a sidebar item that names the same permission', () => {
        const route = createAppRouter().getRoutes().find((r) => r.name === 'received-reports');
        expect(route?.path).toBe('/reports/received');
        expect(route?.meta.requiredPermission).toBe('report.view_received');

        const item = NAVIGATION.flatMap((group) => group.items).find((i) => i.name === 'received-reports');
        expect(item?.permission).toBe('report.view_received');
    });

    it('reads GET /reports/received and lists each report with its author, type, day and status, linking to its page', async () => {
        const fetchMock = list([RECEIVED]);
        const wrapper = await render(fetchMock);

        expect(fetchMock.mock.calls.some(([url]) => /\/reports\/received(\?|$)/.test(String(url)))).toBe(true);
        const rows = wrapper.findAll('[data-testid="received-report-row"]');
        expect(rows).toHaveLength(1);
        expect(rows[0]!.text()).toContain('RPT-DY-2026-0001');
        expect(rows[0]!.text()).toContain('Test Sales');
        expect(rows[0]!.text()).toContain(en.reports.type.daily);
        expect(rows[0]!.text()).toContain(en.reports.status.submitted);
        expect(rows[0]!.find('a').attributes('href')).toBe('/reports/received/rep1');
    });

    it('shows the empty state when nothing was sent to the caller', async () => {
        const wrapper = await render(list([]));

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="received-report-row"]').exists()).toBe(false);
    });

    it('shows the error state with a retry when the list fails', async () => {
        const wrapper = await render(vi.fn(async () => json(500, { error: { code: 'server_error' }, meta: { request_id: 'r' } })));

        expect(wrapper.find('[data-testid="error-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="error-retry"]').exists()).toBe(true);
    });

    it('draws its heading and labels in Arabic', async () => {
        const title = (ar.reports as Record<string, unknown>).received_title;
        const wrapper = await render(list([RECEIVED]), 'ar');

        expect(title).toBeTruthy();
        expect(wrapper.text()).toContain(String(title));
        expect(wrapper.get('[data-testid="received-report-row"]').text()).toContain(ar.reports.status.submitted);
    });

    // ── D-135 ──
    it('shows the late label on a report delivered late, and not on one on time', async () => {
        const late = { ...RECEIVED, id: 'rep2', status: 'submitted', submitted_late: true };
        const onTime = { ...RECEIVED, id: 'rep3', status: 'submitted', submitted_late: false };
        const wrapper = await render(list([late, onTime]));

        const rows = wrapper.findAll('[data-testid="received-report-row"]');
        expect(rows[0]!.find('[data-testid="report-late"]').text()).toContain('Delivered late');
        expect(rows[1]!.find('[data-testid="report-late"]').exists()).toBe(false);

        const arabic = await render(list([late]), 'ar');
        expect(arabic.get('[data-testid="report-late"]').text()).toContain('سُلِّم متأخراً');
    });

    it('draws a weekly report\'s range and a daily report\'s single date', async () => {
        const weekly = { ...RECEIVED, id: 'rep6', code: 'RPT-WK-2026-0001', type: 'weekly', period_start: '2026-10-11', period_end: '2026-10-15' };
        const wrapper = await render(list([RECEIVED, weekly]));

        const dates = wrapper.findAll('[data-testid="report-period"]');
        expect(dates[0]!.text()).toMatch(/^15.10.2026$/);
        expect(dates[1]!.text()).toMatch(/^11.10.2026 – 15.10.2026$/);
        // No LTR isolate: ar-EG's right-to-left marks would scramble the date inside one.
        for (const date of dates) {
            expect(date.element.closest('[dir]')).toBeNull();
            expect(date.find('[dir], bdi').exists()).toBe(false);
        }
    });

    it('shows a received weekly report as Weekly report, with the Not reviewed label only on a flagged one (D-136)', async () => {
        const weekly = { ...RECEIVED, id: 'rep4', code: 'RPT-WK-2026-0001', type: 'weekly', submitted_late: false, not_reviewed: false };
        const flagged = { ...weekly, id: 'rep5', not_reviewed: true };
        const wrapper = await render(list([weekly, flagged]));

        const rows = wrapper.findAll('[data-testid="received-report-row"]');
        expect(rows[0]!.text()).toContain('Weekly report');
        expect(rows[0]!.find('[data-testid="report-not-reviewed"]').exists()).toBe(false);
        expect(rows[1]!.get('[data-testid="report-not-reviewed"]').text()).toContain('Not reviewed');

        const arabic = await render(list([flagged]), 'ar');
        expect(arabic.get('[data-testid="report-not-reviewed"]').text()).toContain('لم يُراجَع');
    });
});

/**
 * Module 13, slice 7 · 7.4 — archived reports (`J-08`, `D-127`): "Received reports" hides them
 * until the recipient ticks «إظهار المؤرشف», which reads the list again with `include_archived=1`.
 */
describe('ReceivedReportsView — the archived reports (7.4)', () => {
    const ARCHIVED = {
        id: 'rep7', code: 'RPT-DY-2026-0007', type: 'daily', status: 'archived', author_name: 'Test Sales',
        period_start: '2026-04-10', period_end: '2026-04-10', submitted_at: '2026-04-10T09:00:00Z',
    };

    /** What the server answers: the archived row only when the flag is on the request. */
    function withFlag(): ReturnType<typeof vi.fn> {
        return vi.fn(async (input: string) => {
            const rows = /include_archived=1/.test(String(input)) ? [RECEIVED, ARCHIVED] : [RECEIVED];

            return json(200, { data: rows, meta: { request_id: 'r', pagination: { ...PAGINATION, total: rows.length } } });
        });
    }

    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('offers an unchecked «show archived» box and asks for no archived report by default', async () => {
        const fetchMock = withFlag();
        const wrapper = await render(fetchMock);

        const box = wrapper.get('[data-testid="reports-show-archived"]');
        expect((box.element as HTMLInputElement).checked).toBe(false);
        expect(wrapper.text()).toContain(en.reports.show_archived);
        expect(fetchMock.mock.calls.some(([url]) => /include_archived/.test(String(url)))).toBe(false);
        expect(wrapper.findAll('[data-testid="received-report-row"]')).toHaveLength(1);
    });

    it('reads GET /reports/received again with include_archived=1 when ticked, draws the archived row linking to its page, and drops it when unticked', async () => {
        const fetchMock = withFlag();
        const wrapper = await render(fetchMock);

        await wrapper.get('[data-testid="reports-show-archived"]').setValue(true);
        await flushPromises();

        expect(String(fetchMock.mock.calls.at(-1)![0])).toMatch(/\/reports\/received\?.*include_archived=1/);
        const rows = wrapper.findAll('[data-testid="received-report-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[1]!.text()).toContain('RPT-DY-2026-0007');
        expect(rows[1]!.text()).toContain(en.reports.status.archived);
        expect(rows[1]!.find('a').attributes('href')).toBe('/reports/received/rep7');

        await wrapper.get('[data-testid="reports-show-archived"]').setValue(false);
        await flushPromises();

        expect(String(fetchMock.mock.calls.at(-1)![0])).not.toMatch(/include_archived=1/);
        expect(wrapper.findAll('[data-testid="received-report-row"]')).toHaveLength(1);
    });

    it('keeps the box on screen over the empty state, so archived reports can still be asked for', async () => {
        const fetchMock = vi.fn(async (input: string) => {
            const rows = /include_archived=1/.test(String(input)) ? [ARCHIVED] : [];

            return json(200, { data: rows, meta: { request_id: 'r', pagination: { ...PAGINATION, total: rows.length } } });
        });
        const wrapper = await render(fetchMock);

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="reports-show-archived"]').exists()).toBe(true);
        await wrapper.get('[data-testid="reports-show-archived"]').setValue(true);
        await flushPromises();

        expect(wrapper.findAll('[data-testid="received-report-row"]')).toHaveLength(1);
    });

    it('words the box and the archived status in Arabic (إظهار المؤرشف)', async () => {
        const wrapper = await render(withFlag(), 'ar');

        expect(wrapper.text()).toContain(ar.reports.show_archived);
        await wrapper.get('[data-testid="reports-show-archived"]').setValue(true);
        await flushPromises();
        expect(wrapper.findAll('[data-testid="received-report-row"]')[1]!.text()).toContain(ar.reports.status.archived);
    });
});
