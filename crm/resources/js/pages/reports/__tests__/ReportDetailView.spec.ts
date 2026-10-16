import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import ReportDetailView from '@/pages/reports/ReportDetailView.vue';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 13, first slice — one daily report (RPT-DY): the author writes notes,
 * sees the day's records grouped by kind (`D-126`; found through the audit
 * log but never showing it, `D-130`), chooses recipients (default preselected, `D-129`) and submits.
 *
 * The screen displays what the server returns and sends what the author typed;
 * it computes no count and decides no recipient (`D-67`, `AP-07`).
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function envelope(data: unknown): Response {
    return json(200, { data, meta: { request_id: 'r' } });
}

const USER: AuthenticatedUser = {
    id: 'u1', name: 'Test Sales', email: 'indoor.sales@example.test',
    role: { id: 'r1', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['report.create.own'],
    is_active: true, unconditional_access: false,
};

const SECTIONS = {
    deals_created: [
        { deal_id: 'd1', code: 'DL-2026-0001', customer_name: 'Nile Trading', status: 'contacted' },
        { deal_id: 'd2', code: 'DL-2026-0002', customer_name: 'Delta Foods', status: 'lead' },
    ],
    deal_status_moves: [
        { deal_id: 'd1', code: 'DL-2026-0001', customer_name: 'Nile Trading', from_status: 'lead', to_status: 'contacted' },
    ],
    quotations_created: [
        { quotation_id: 'q1', code: 'QT-2026-0001', version: 1, customer_name: 'Nile Trading', status: 'draft', final_total: '1250.000000', currency: 'EGP' },
    ],
    quotations_sent: [] as unknown[],
    customer_responses: [
        { quotation_id: 'q2', code: 'QT-2026-0002', version: 2, customer_name: 'Delta Foods', response: 'accepted', status: 'accepted', final_total: '3000.000000', currency: 'EGP' },
        { quotation_id: 'q3', code: 'QT-2026-0003', version: 1, customer_name: 'Nile Trading', response: 'countered', status: 'counter', final_total: '410.000000', currency: 'EGP' },
    ],
    purchase_orders: [
        { purchase_order_id: 'p1', po_number: 'PO-2026-0001', po_date: '2026-10-15', quotation_id: 'q2', quotation_code: 'QT-2026-0002', customer_name: 'Delta Foods', final_total: '3000.000000', currency: 'EGP' },
    ],
};

const COUNTS = Object.fromEntries(Object.entries(SECTIONS).map(([key, rows]) => [key, rows.length])) as Record<keyof typeof SECTIONS, number>;

const EMPTY_SECTIONS = Object.fromEntries(Object.keys(SECTIONS).map((key) => [key, []]));

const DRAFT = {
    id: 'rep1', code: 'RPT-DY-2026-0001', type: 'daily', status: 'draft',
    period_start: '2026-10-15', period_end: '2026-10-15', submitted_at: null,
    notes: 'Morning calls.', etag: 'report:rep1:1',
    // `D-130`: the records themselves, grouped by kind — never the audit events.
    activity: { counts: COUNTS, sections: SECTIONS },
    recipients: [],
};

const CANDIDATES = [
    { id: 'm1', name: 'Mona Manager', is_default: true },
    { id: 'c1', name: 'Chris CEO', is_default: false },
];

const SUBMITTED = {
    ...DRAFT, status: 'submitted', etag: 'report:rep1:3', submitted_at: '2026-10-15T09:00:00Z',
    recipients: [{ id: 'm1', name: 'Mona Manager' }],
};

interface Options {
    report?: unknown;
    status?: number;
    patch?: (url: string, init: RequestInit) => Response;
}

function server(options: Options = {}): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string, init?: RequestInit) => {
        const url = String(input);
        if (init?.method === 'PATCH') {
            return options.patch ? options.patch(url, init) : envelope(DRAFT);
        }
        if (url.endsWith('/recipient-candidates')) {
            return envelope(CANDIDATES);
        }
        if ((options.status ?? 200) !== 200) {
            return json(options.status!, { error: { code: 'resource_not_found' }, meta: { request_id: 'r' } });
        }

        return envelope(options.report ?? DRAFT);
    });
}

async function render(fetchMock: ReturnType<typeof vi.fn>, locale = 'en', user = USER, path = '/reports/rep1') {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user },
            }))
            : (fetchMock as unknown as typeof fetch)(input, init));
    await useAuth().login(user.email, 'Passw0rd123');
    vi.stubGlobal('fetch', fetchMock);

    const router = createAppRouter();
    await router.push(path);
    await router.isReady();

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(ReportDetailView, { global: { plugins: [i18n, router] } });
    await flushPromises();

    return wrapper;
}

function patches(fetchMock: ReturnType<typeof vi.fn>): Array<[string, RequestInit]> {
    return fetchMock.mock.calls
        .filter(([, init]) => (init as RequestInit | undefined)?.method === 'PATCH')
        .map(([url, init]) => [String(url), init as RequestInit]);
}

describe('ReportDetailView — a daily report', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('shows the code, the status and the server\'s six activity counts', async () => {
        const view = await render(server());

        expect(view.text()).toContain('RPT-DY-2026-0001');
        expect(view.get('[data-testid="report-status"]').text()).toContain(en.reports.status.draft);
        for (const [key, value] of Object.entries(COUNTS)) {
            const tile = view.get(`[data-testid="activity-count-${key}"]`);
            expect(tile.text()).toContain(String(value));
            expect(tile.text()).toContain(en.reports.activity[key as keyof typeof en.reports.activity]);
        }
    });

    it('shows one section per kind, its rows in the order the server gives them', async () => {
        const view = await render(server());

        for (const [key, rows] of Object.entries(SECTIONS)) {
            const section = view.get(`[data-testid="activity-section-${key}"]`);
            expect(section.attributes('id')).toBe(`activity-section-${key}`);
            expect(section.findAll('[data-testid="activity-row"]')).toHaveLength(rows.length);
        }
        const deals = view.get('[data-testid="activity-section-deals_created"]').findAll('[data-testid="activity-row"]');
        expect(deals[0]!.text()).toContain('DL-2026-0001');
        expect(deals[0]!.text()).toContain('Nile Trading');
        expect(deals[1]!.text()).toContain('DL-2026-0002');
        expect(deals[1]!.text()).toContain('Delta Foods');
    });

    it('links each count tile to its section', async () => {
        const view = await render(server());

        for (const key of Object.keys(SECTIONS)) {
            const tile = view.get(`[data-testid="activity-count-${key}"]`);
            const link = tile.element.tagName === 'A' ? tile : tile.get('a');
            expect(link.attributes('href')).toBe(`#activity-section-${key}`);
        }
    });

    it('links deal codes to the deal page and quotation codes to the quotation page', async () => {
        const view = await render(server());
        const router = createAppRouter();
        const href = (name: string, id: string) => router.resolve({ name, params: { id } }).href;

        const dealLink = view.get('[data-testid="activity-section-deals_created"]').findAll('a').find((a) => a.text().includes('DL-2026-0001'));
        expect(dealLink?.attributes('href')).toBe(href('deal-detail', 'd1'));

        const moveLink = view.get('[data-testid="activity-section-deal_status_moves"]').findAll('a').find((a) => a.text().includes('DL-2026-0001'));
        expect(moveLink?.attributes('href')).toBe(href('deal-detail', 'd1'));

        const quotationLink = view.get('[data-testid="activity-section-quotations_created"]').findAll('a').find((a) => a.text().includes('QT-2026-0001'));
        expect(quotationLink?.attributes('href')).toBe(href('quotation-detail', 'q1'));

        const responseLink = view.get('[data-testid="activity-section-customer_responses"]').findAll('a').find((a) => a.text().includes('QT-2026-0002'));
        expect(responseLink?.attributes('href')).toBe(href('quotation-detail', 'q2'));

        const poLink = view.get('[data-testid="activity-section-purchase_orders"]').findAll('a').find((a) => a.text().includes('QT-2026-0002'));
        expect(poLink?.attributes('href')).toBe(href('quotation-detail', 'q2'));
    });

    it('shows each quotation and PO total with its currency', async () => {
        const view = await render(server());

        const created = view.get('[data-testid="activity-section-quotations_created"]').get('[data-testid="activity-row"]');
        expect(created.text()).toMatch(/1,?250/);
        expect(created.text()).toContain('EGP');

        const po = view.get('[data-testid="activity-section-purchase_orders"]').get('[data-testid="activity-row"]');
        expect(po.text()).toContain('PO-2026-0001');
        expect(po.text()).toMatch(/3,?000/);
        expect(po.text()).toContain('EGP');
    });

    it('shows a kind with no rows as empty, without a table', async () => {
        const view = await render(server());

        const section = view.get('[data-testid="activity-section-quotations_sent"]');
        expect(section.find('[data-testid="activity-section-empty-quotations_sent"]').exists()).toBe(true);
        expect(section.find('table').exists()).toBe(false);
        expect(view.find('[data-testid="activity-section-empty-deals_created"]').exists()).toBe(false);
    });

    it('draws no audit event label — the log is only the index (D-130)', async () => {
        const view = await render(server());

        expect(view.find('[data-testid="activity-empty"]').exists()).toBe(false);
        const events = (en.reports as Record<string, unknown>).event as Record<string, string> | undefined;
        for (const label of Object.values(events ?? {})) {
            expect(view.text()).not.toContain(label);
        }
        expect(view.find('[data-testid="activity-item"]').exists()).toBe(false);
    });

    it('shows an empty activity message, and no section, when the day has none', async () => {
        const quiet = {
            ...DRAFT,
            activity: { counts: Object.fromEntries(Object.keys(COUNTS).map((key) => [key, 0])), sections: EMPTY_SECTIONS },
        };
        const view = await render(server({ report: quiet }));

        expect(view.find('[data-testid="activity-empty"]').exists()).toBe(true);
        expect(view.find('[data-testid^="activity-section-"]').exists()).toBe(false);
    });

    it('saves the notes with If-Match and adopts the new etag', async () => {
        const fetchMock = server({ patch: (_url, init) => envelope({ ...DRAFT, notes: JSON.parse(init.body as string).notes, etag: 'report:rep1:2' }) });
        const view = await render(fetchMock);

        expect((view.get('[data-testid="report-notes"]').element as HTMLTextAreaElement).value).toBe('Morning calls.');
        await view.get('[data-testid="report-notes"]').setValue('Two visits.');
        await view.get('[data-testid="report-save"]').trigger('click');
        await flushPromises();

        const [url, init] = patches(fetchMock)[0]!;
        expect(url).toMatch(/\/reports\/rep1$/);
        expect(JSON.parse(init.body as string)).toEqual({ notes: 'Two visits.' });
        expect(new Headers(init.headers).get('If-Match')).toBe('report:rep1:1');

        await view.get('[data-testid="report-save"]').trigger('click');
        await flushPromises();
        expect(new Headers(patches(fetchMock)[1]![1].headers).get('If-Match')).toBe('report:rep1:2');
    });

    it('says the report changed elsewhere on a 409, and keeps the typed notes', async () => {
        const fetchMock = server({ patch: () => json(409, { error: { code: 'concurrency_conflict', details: [] }, meta: { request_id: 'r' } }) });
        const view = await render(fetchMock);

        await view.get('[data-testid="report-notes"]').setValue('Unsaved.');
        await view.get('[data-testid="report-save"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="report-conflict"]').exists()).toBe(true);
        expect((view.get('[data-testid="report-notes"]').element as HTMLTextAreaElement).value).toBe('Unsaved.');
    });

    it('offers the server\'s candidates with the default already chosen', async () => {
        const view = await render(server());

        const options = view.findAll('[data-testid="recipient-option"]');
        expect(options.map((o) => o.text())).toEqual(['Mona Manager', 'Chris CEO']);
        expect((options[0]!.get('input').element as HTMLInputElement).checked).toBe(true);
        expect((options[1]!.get('input').element as HTMLInputElement).checked).toBe(false);
    });

    it('cannot submit with no recipient chosen', async () => {
        const view = await render(server());

        await view.findAll('[data-testid="recipient-option"]')[0]!.get('input').setValue(false);

        expect(view.get('[data-testid="report-submit"]').attributes('disabled')).toBeDefined();
    });

    it('submits the chosen recipients under If-Match and becomes read-only', async () => {
        const fetchMock = server({ patch: () => envelope(SUBMITTED) });
        const view = await render(fetchMock);

        await view.findAll('[data-testid="recipient-option"]')[1]!.get('input').setValue(true);
        await view.get('[data-testid="report-submit"]').trigger('click');
        await flushPromises();

        const [url, init] = patches(fetchMock)[0]!;
        expect(url).toMatch(/\/reports\/rep1\/submit$/);
        expect(JSON.parse(init.body as string)).toEqual({ recipient_ids: ['m1', 'c1'] });
        expect(new Headers(init.headers).get('If-Match')).toBe('report:rep1:1');

        expect(view.get('[data-testid="report-status"]').text()).toContain(en.reports.status.submitted);
        expect(view.find('[data-testid="report-notes"]').exists()).toBe(false);
        expect(view.find('[data-testid="report-submit"]').exists()).toBe(false);
    });

    it('shows a submitted report\'s notes and recipients without a form', async () => {
        const view = await render(server({ report: SUBMITTED }));

        expect(view.text()).toContain('Morning calls.');
        expect(view.findAll('[data-testid="report-recipient"]').map((r) => r.text())).toEqual(['Mona Manager']);
        expect(view.find('[data-testid="report-notes"]').exists()).toBe(false);
        expect(view.find('[data-testid="recipient-option"]').exists()).toBe(false);
    });

    it('shows the not-found state for a report that is not the author\'s', async () => {
        const view = await render(server({ status: 404 }));

        expect(view.find('[data-testid="not-found-state"]').exists()).toBe(true);
    });

    it('draws its labels in Arabic', async () => {
        const view = await render(server(), 'ar');

        expect(view.get('[data-testid="report-status"]').text()).toContain(ar.reports.status.draft);
        expect(view.get('[data-testid="activity-count-deals_created"]').text()).toContain(ar.reports.activity.deals_created);
        expect(view.get('[data-testid="report-submit"]').text()).toContain(ar.reports.submit);
    });
});

/**
 * Module 13, slice 2 — the same page for a recipient, opened from "Received
 * reports" (§3.10, §11.4, `D-127`). The server decides who may act; the page
 * draws Acknowledge and Return only for a recipient who holds them, and the
 * refusals live in `ReportReceiveEndpointTest`.
 */
const MANAGER: AuthenticatedUser = {
    id: 'm1', name: 'Mona Manager', email: 'manager@example.test',
    role: { id: 'r2', slug: 'manager', name: 'Manager' },
    permissions: ['report.create.all', 'report.view_received.all', 'report.acknowledge.all', 'report.return_report.all', 'report.comment.all'],
    is_active: true, unconditional_access: false,
};

const CEO: AuthenticatedUser = {
    id: 'c1', name: 'Chris CEO', email: 'ceo@example.test',
    role: { id: 'r3', slug: 'ceo', name: 'CEO' },
    permissions: ['report.view_received.all', 'report.comment.all', 'report.export.all'],
    is_active: true, unconditional_access: false,
};

const RECEIVED_PATH = '/reports/received/rep1';

const VIEWED = { ...SUBMITTED, status: 'viewed', etag: 'report:rep1:3', author_name: 'Test Sales' };

const STATUS = en.reports.status as Record<string, string>;

function gets(fetchMock: ReturnType<typeof vi.fn>): string[] {
    return fetchMock.mock.calls
        .filter(([, init]) => ((init as RequestInit | undefined)?.method ?? 'GET') === 'GET')
        .map(([url]) => String(url));
}

describe('ReportDetailView — a received report', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('is a route behind report.view_received', () => {
        const route = createAppRouter().getRoutes().find((r) => r.name === 'received-report-detail');
        expect(route?.path).toBe('/reports/received/:id');
        expect(route?.meta.requiredPermission).toBe('report.view_received');
    });

    it('reads the report through the received endpoint and offers Acknowledge and Return', async () => {
        const fetchMock = server({ report: VIEWED });
        const view = await render(fetchMock, 'en', MANAGER, RECEIVED_PATH);

        expect(gets(fetchMock).some((url) => /\/reports\/received\/rep1$/.test(url))).toBe(true);
        expect(view.text()).toContain('RPT-DY-2026-0001');
        expect(view.find('[data-testid="report-acknowledge"]').exists()).toBe(true);
        expect(view.find('[data-testid="report-return"]').exists()).toBe(true);
        expect(view.find('[data-testid="report-notes"]').exists()).toBe(false);
        expect(view.find('[data-testid="report-submit"]').exists()).toBe(false);
    });

    it('acknowledges under If-Match and then offers nothing more', async () => {
        const fetchMock = server({ report: VIEWED, patch: () => envelope({ ...VIEWED, status: 'acknowledged', etag: 'report:rep1:4' }) });
        const view = await render(fetchMock, 'en', MANAGER, RECEIVED_PATH);

        await view.get('[data-testid="report-acknowledge"]').trigger('click');
        await flushPromises();

        const [url, init] = patches(fetchMock)[0]!;
        expect(url).toMatch(/\/reports\/rep1\/acknowledge$/);
        expect(new Headers(init.headers).get('If-Match')).toBe('report:rep1:3');

        expect(STATUS.acknowledged).toBeTruthy();
        expect(view.get('[data-testid="report-status"]').text()).toContain(STATUS.acknowledged);
        expect(view.find('[data-testid="report-acknowledge"]').exists()).toBe(false);
        expect(view.find('[data-testid="report-return"]').exists()).toBe(false);
    });

    it('will not send a return without a reason', async () => {
        const fetchMock = server({ report: VIEWED });
        const view = await render(fetchMock, 'en', MANAGER, RECEIVED_PATH);

        await view.get('[data-testid="report-return"]').trigger('click');
        expect(view.find('[data-testid="report-return-dialog"]').exists()).toBe(true);
        await view.get('[data-testid="report-return-reason"]').setValue('   ');
        await view.get('[data-testid="report-return-dialog"]').trigger('submit');
        await flushPromises();

        expect(patches(fetchMock)).toHaveLength(0);
        const error = view.get('[data-testid="report-return-reason-error"]');
        expect(view.get('[data-testid="report-return-reason"]').attributes('aria-describedby')).toBe(error.attributes('id'));
    });

    it('returns with the reason in the body, under If-Match', async () => {
        const fetchMock = server({ report: VIEWED, patch: () => envelope({ ...VIEWED, status: 'returned', etag: 'report:rep1:4' }) });
        const view = await render(fetchMock, 'en', MANAGER, RECEIVED_PATH);

        await view.get('[data-testid="report-return"]').trigger('click');
        await view.get('[data-testid="report-return-reason"]').setValue('Add the visit to Delta.');
        await view.get('[data-testid="report-return-dialog"]').trigger('submit');
        await flushPromises();

        const [url, init] = patches(fetchMock)[0]!;
        expect(url).toMatch(/\/reports\/rep1\/return$/);
        expect(JSON.parse(init.body as string)).toEqual({ reason: 'Add the visit to Delta.' });
        expect(new Headers(init.headers).get('If-Match')).toBe('report:rep1:3');

        expect(STATUS.returned).toBeTruthy();
        expect(view.get('[data-testid="report-status"]').text()).toContain(STATUS.returned);
        expect(view.find('[data-testid="report-return-dialog"]').exists()).toBe(false);
    });

    it('shows the CEO the report sent to them, without Acknowledge or Return (§3.10)', async () => {
        const fetchMock = server({ report: VIEWED });
        const view = await render(fetchMock, 'en', CEO, RECEIVED_PATH);

        expect(gets(fetchMock).some((url) => /\/reports\/received\/rep1$/.test(url))).toBe(true);
        expect(view.text()).toContain('RPT-DY-2026-0001');
        expect(view.find('[data-testid="report-acknowledge"]').exists()).toBe(false);
        expect(view.find('[data-testid="report-return"]').exists()).toBe(false);
    });

    it('draws Acknowledge and Return in Arabic', async () => {
        const labels = ar.reports as Record<string, unknown>;
        const view = await render(server({ report: VIEWED }), 'ar', MANAGER, RECEIVED_PATH);

        expect(labels.acknowledge).toBeTruthy();
        expect(labels.return).toBeTruthy();
        expect(view.get('[data-testid="report-acknowledge"]').text()).toContain(String(labels.acknowledge));
        expect(view.get('[data-testid="report-return"]').text()).toContain(String(labels.return));
    });

    it('shows the author\'s own submitted report without Acknowledge or Return, even to a Manager author', async () => {
        const view = await render(server({ report: SUBMITTED }), 'en', MANAGER, '/reports/rep1');

        expect(view.text()).toContain('RPT-DY-2026-0001');
        expect(view.find('[data-testid="report-acknowledge"]').exists()).toBe(false);
        expect(view.find('[data-testid="report-return"]').exists()).toBe(false);
    });
});
