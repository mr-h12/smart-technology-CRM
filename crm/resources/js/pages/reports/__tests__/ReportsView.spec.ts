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
            : (fetchMock as unknown as typeof fetch)(input, init));
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
        expect(rows[0]!.text()).toContain('RPT-DY-2026-0001');
        expect(rows[0]!.text()).toContain(en.reports.status.draft);
        expect(rows[0]!.text()).toContain(en.reports.type.daily);
        expect(rows[0]!.find('a').attributes('href')).toBe('/reports/rep1');
    });

    it('draws a weekly report\'s range and a daily report\'s single date', async () => {
        const weekly = { ...REPORT, id: 'rep9', code: 'RPT-WK-2026-0001', type: 'weekly', period_start: '2026-10-11', period_end: '2026-10-15' };
        const { wrapper } = await render(list([REPORT, weekly]));

        const dates = wrapper.findAll('[data-testid="report-period"]');
        expect(dates[0]!.text()).toMatch(/^15.10.2026$/);
        expect(dates[1]!.text()).toMatch(/^11.10.2026 – 15.10.2026$/);
        // No LTR isolate: ar-EG's right-to-left marks would scramble the date inside one.
        for (const date of dates) {
            expect(date.element.closest('[dir]')).toBeNull();
            expect(date.find('[dir], bdi').exists()).toBe(false);
        }
    });

    it('names a monthly report by its month and year, not a date range (the owner, 2026-10-20)', async () => {
        const monthly = { ...REPORT, id: 'rep8', code: 'RPT-MN-2026-0001', type: 'monthly', period_start: '2026-09-01', period_end: '2026-09-30' };

        expect((await render(list([monthly]))).wrapper.get('[data-testid="report-period"]').text()).toBe('September 2026');
        expect((await render(list([monthly]), 'ar')).wrapper.get('[data-testid="report-period"]').text()).toBe('سبتمبر 2026');
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

    // ── D-135 ──
    it('shows a missed report as Missed (فائت in Arabic), and the late label only on a late one', async () => {
        const missed = { ...REPORT, id: 'rep3', code: 'RPT-DY-2026-0003', status: 'missed', submitted_late: false };
        const late = { ...REPORT, id: 'rep4', code: 'RPT-DY-2026-0004', status: 'submitted', submitted_late: true };

        const { wrapper } = await render(list([missed, late]));
        const rows = wrapper.findAll('[data-testid="report-row"]');
        expect(rows[0]!.text()).toContain('Missed');
        expect(rows[0]!.find('[data-testid="report-late"]').exists()).toBe(false);
        expect(rows[1]!.find('[data-testid="report-late"]').text()).toContain('Delivered late');

        const { wrapper: arabic } = await render(list([missed, late]), 'ar');
        const arRows = arabic.findAll('[data-testid="report-row"]');
        expect(arRows[0]!.text()).toContain('فائت');
        expect(arRows[1]!.find('[data-testid="report-late"]').text()).toContain('سُلِّم متأخراً');
    });

    // ── D-136 ──
    it('shows a weekly report as Weekly report (تقرير أسبوعي), and the Not reviewed label only on a flagged one', async () => {
        const weekly = { ...REPORT, id: 'rep5', code: 'RPT-WK-2026-0001', type: 'weekly', status: 'submitted', submitted_late: false, not_reviewed: false };
        const flagged = { ...weekly, id: 'rep6', code: 'RPT-WK-2026-0002', not_reviewed: true };

        const { wrapper } = await render(list([weekly, flagged]));
        const rows = wrapper.findAll('[data-testid="report-row"]');
        expect(rows[0]!.text()).toContain('Weekly report');
        expect(rows[0]!.find('[data-testid="report-not-reviewed"]').exists()).toBe(false);
        expect(rows[1]!.find('[data-testid="report-not-reviewed"]').text()).toContain('Not reviewed');

        const { wrapper: arabic } = await render(list([weekly, flagged]), 'ar');
        const arRows = arabic.findAll('[data-testid="report-row"]');
        expect(arRows[0]!.text()).toContain('تقرير أسبوعي');
        expect(arRows[1]!.find('[data-testid="report-not-reviewed"]').text()).toContain('لم يُراجَع');
    });

    // ── Module 13 · 7.3 — the duplicate-period warning and its choice (§3.10, `D-127`) ──
    /**
     * The server answers the first POST `409` with `details.0.code = report_duplicate_period` and the
     * existing report (id, code). The page asks; it never decides. «افتح الموجود» opens that report;
     * «أنشئ تقريراً آخر» POSTs once more with `confirm_duplicate` and a NEW `Idempotency-Key` — the
     * same key with a changed body would be `idempotency_conflict` (`OpenAPI §5.1`).
     */
    const DUPLICATE = {
        error: {
            code: 'state_transition_invalid',
            message: 'A daily report for this day already exists.',
            details: [{ field: 'type', code: 'report_duplicate_period', message: 'A daily report for this day already exists.', existing_report: { id: 'rep1', code: 'RPT-DY-2026-0001' } }],
        },
        meta: { request_id: 'r' },
    };

    function duplicating(): ReturnType<typeof vi.fn> {
        return vi.fn(async (_input: string, init?: RequestInit) => {
            if (init?.method === 'POST') {
                return JSON.parse(init.body as string).confirm_duplicate === true
                    ? json(201, { data: { id: 'rep2', code: 'RPT-DY-2026-0002' }, meta: { request_id: 'r' } })
                    : json(409, DUPLICATE);
            }

            return json(200, { data: [REPORT], meta: { request_id: 'r', pagination: PAGINATION } });
        });
    }

    function posts(fetchMock: ReturnType<typeof vi.fn>): RequestInit[] {
        return fetchMock.mock.calls
            .filter(([, init]) => (init as RequestInit | undefined)?.method === 'POST')
            .map(([, init]) => init as RequestInit);
    }

    it('opens a dialog naming the existing report when the server warns of a duplicate day', async () => {
        const fetchMock = duplicating();
        const { wrapper, router } = await render(fetchMock);

        expect(wrapper.find('[data-testid="report-duplicate-dialog"]').exists()).toBe(false);
        await wrapper.get('[data-testid="reports-new"]').trigger('click');
        await flushPromises();

        const dialog = wrapper.get('[data-testid="report-duplicate-dialog"]');
        expect(dialog.text()).toContain('RPT-DY-2026-0001');
        expect(dialog.get('[data-testid="report-duplicate-open"]').text()).toContain(en.reports.duplicate_open);
        expect(dialog.get('[data-testid="report-duplicate-create"]').text()).toContain(en.reports.duplicate_create);
        expect(posts(fetchMock)).toHaveLength(1);
        expect(JSON.parse(posts(fetchMock)[0]!.body as string)).toEqual({ type: 'daily' });
        expect(router.currentRoute.value.fullPath).toBe('/reports');
        // The warning is not the generic failure.
        expect(wrapper.text()).not.toContain(en.reports.error.create);
    });

    it('opens the existing report, and creates nothing, on «افتح الموجود»', async () => {
        const fetchMock = duplicating();
        const { wrapper, router } = await render(fetchMock);

        await wrapper.get('[data-testid="reports-new"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="report-duplicate-open"]').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.fullPath).toBe('/reports/rep1');
        expect(posts(fetchMock)).toHaveLength(1);
    });

    it('creates another report on «أنشئ تقريراً آخر»: a second POST with confirm_duplicate and a new key, then opens it', async () => {
        const fetchMock = duplicating();
        const { wrapper, router } = await render(fetchMock);

        await wrapper.get('[data-testid="reports-new"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="report-duplicate-create"]').trigger('click');
        await flushPromises();

        const [first, second] = posts(fetchMock);
        expect(second).toBeDefined();
        expect(JSON.parse(second!.body as string)).toEqual({ type: 'daily', confirm_duplicate: true });
        const firstKey = new Headers(first!.headers).get('Idempotency-Key');
        const secondKey = new Headers(second!.headers).get('Idempotency-Key');
        expect(secondKey).toMatch(/^[0-9a-f-]{36}$/);
        expect(secondKey).not.toBe(firstKey);
        expect(router.currentRoute.value.fullPath).toBe('/reports/rep2');
    });

    it('draws the duplicate dialog in Arabic with the owner\'s two choices, and has English text for both', async () => {
        const { wrapper } = await render(duplicating(), 'ar');

        await wrapper.get('[data-testid="reports-new"]').trigger('click');
        await flushPromises();

        const labels = ar.reports as unknown as Record<string, string>;
        expect(labels.duplicate_open).toBe('افتح الموجود');
        expect(labels.duplicate_create).toBe('أنشئ تقريراً آخر');
        for (const key of ['duplicate_title', 'duplicate_open', 'duplicate_create']) {
            expect(labels[key], `ar.reports.${key}`).toMatch(/[؀-ۿ]/);
            expect((en.reports as unknown as Record<string, string>)[key], `en.reports.${key}`).toBeTruthy();
        }
        const dialog = wrapper.get('[data-testid="report-duplicate-dialog"]');
        expect(dialog.get('[data-testid="report-duplicate-open"]').text()).toBe('افتح الموجود');
        expect(dialog.get('[data-testid="report-duplicate-create"]').text()).toBe('أنشئ تقريراً آخر');
        expect(dialog.text()).toContain(labels.duplicate_title);
    });
});

/**
 * Module 13, slice 7 · 7.4 — archived reports (`J-08`, `D-127`): "My reports" hides them
 * until the author ticks «إظهار المؤرشف», which reads the list again with `include_archived=1`.
 * The server decides what is archived and what the flag reveals.
 */
describe('ReportsView — the archived reports (7.4)', () => {
    const ARCHIVED = {
        id: 'rep7', code: 'RPT-DY-2026-0007', type: 'daily', status: 'archived',
        period_start: '2026-04-10', period_end: '2026-04-10', submitted_at: '2026-04-10T09:00:00Z',
    };

    /** What the server answers: the archived row only when the flag is on the request. */
    function withFlag(): ReturnType<typeof vi.fn> {
        return vi.fn(async (input: string) => {
            const rows = /include_archived=1/.test(String(input)) ? [REPORT, ARCHIVED] : [REPORT];

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
        const { wrapper } = await render(fetchMock);

        const box = wrapper.get('[data-testid="reports-show-archived"]');
        expect((box.element as HTMLInputElement).checked).toBe(false);
        expect(wrapper.text()).toContain(en.reports.show_archived);
        expect(fetchMock.mock.calls.some(([url]) => /include_archived/.test(String(url)))).toBe(false);
        expect(wrapper.findAll('[data-testid="report-row"]')).toHaveLength(1);
    });

    it('reads the list again with include_archived=1 when ticked, draws the archived row, and drops it when unticked', async () => {
        const fetchMock = withFlag();
        const { wrapper } = await render(fetchMock);

        await wrapper.get('[data-testid="reports-show-archived"]').setValue(true);
        await flushPromises();

        expect(String(fetchMock.mock.calls.at(-1)![0])).toMatch(/\/reports\?.*include_archived=1/);
        const rows = wrapper.findAll('[data-testid="report-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[1]!.text()).toContain('RPT-DY-2026-0007');
        expect(rows[1]!.text()).toContain(en.reports.status.archived);

        await wrapper.get('[data-testid="reports-show-archived"]').setValue(false);
        await flushPromises();

        expect(String(fetchMock.mock.calls.at(-1)![0])).not.toMatch(/include_archived=1/);
        expect(wrapper.findAll('[data-testid="report-row"]')).toHaveLength(1);
    });

    it('keeps the box on screen over the empty state, so archived reports can still be asked for', async () => {
        const fetchMock = vi.fn(async (input: string) => {
            const rows = /include_archived=1/.test(String(input)) ? [ARCHIVED] : [];

            return json(200, { data: rows, meta: { request_id: 'r', pagination: { ...PAGINATION, total: rows.length } } });
        });
        const { wrapper } = await render(fetchMock);

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="reports-show-archived"]').exists()).toBe(true);
        await wrapper.get('[data-testid="reports-show-archived"]').setValue(true);
        await flushPromises();

        expect(wrapper.findAll('[data-testid="report-row"]')).toHaveLength(1);
    });

    it('words the box and the archived status in Arabic (إظهار المؤرشف) and in English', async () => {
        expect(ar.reports.show_archived).toBe('إظهار المؤرشف');
        expect(en.reports.show_archived.length).toBeGreaterThan(0);
        expect(/[؀-ۿ]/.test(ar.reports.status.archived)).toBe(true);
        expect(en.reports.status.archived).toBeTruthy();

        const { wrapper } = await render(withFlag(), 'ar');
        expect(wrapper.text()).toContain(ar.reports.show_archived);
        await wrapper.get('[data-testid="reports-show-archived"]').setValue(true);
        await flushPromises();
        expect(wrapper.findAll('[data-testid="report-row"]')[1]!.text()).toContain(ar.reports.status.archived);
    });
});
