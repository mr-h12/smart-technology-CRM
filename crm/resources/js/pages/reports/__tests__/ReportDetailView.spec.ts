import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import ReportDetailView from '@/pages/reports/ReportDetailView.vue';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 13, first slice — one daily report (RPT-DY): the author writes notes
 * (several since `D-131`), sees the day's records grouped by kind (`D-126`; found through the audit
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
    follow_ups: [], notes: [{ id: 'n1', body: 'Morning calls.' }], etag: 'report:rep1:1',
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

    it('saves a note through its own route, without If-Match, and Submit keeps the report\'s etag (D-131)', async () => {
        const fetchMock = server({ patch: (url, init) => /\/notes\//.test(url) ? envelope({ id: 'n1', body: JSON.parse(init.body as string).body }) : envelope(SUBMITTED) });
        const view = await render(fetchMock);

        expect((view.get('[data-testid="report-note-body"]').element as HTMLTextAreaElement).value).toBe('Morning calls.');
        await view.get('[data-testid="report-note-body"]').setValue('Two visits.');
        await view.get('[data-testid="report-note-body"]').trigger('blur');
        await flushPromises();

        const [url, init] = patches(fetchMock)[0]!;
        expect(url).toMatch(/\/reports\/rep1\/notes\/n1$/);
        expect(JSON.parse(init.body as string)).toEqual({ body: 'Two visits.' });
        expect(new Headers(init.headers).get('If-Match')).toBeNull();

        await view.get('[data-testid="report-submit"]').trigger('click');
        await flushPromises();
        expect(new Headers(patches(fetchMock)[1]![1].headers).get('If-Match')).toBe('report:rep1:1');
    });

    it('says a note was not saved when the report changed elsewhere (409), and keeps the typed text', async () => {
        const fetchMock = server({ patch: () => json(409, { error: { code: 'state_transition_invalid', details: [{ code: 'report_not_editable' }] }, meta: { request_id: 'r' } }) });
        const view = await render(fetchMock);

        await view.get('[data-testid="report-note-body"]').setValue('Unsaved.');
        await view.get('[data-testid="report-note-body"]').trigger('blur');
        await flushPromises();

        expect(view.get('[data-testid="report-section-notes"]').get('[data-testid="report-autosave-status"]').text()).toBe(en.reports.autosave.failed);
        expect((view.get('[data-testid="report-note-body"]').element as HTMLTextAreaElement).value).toBe('Unsaved.');
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
        expect(view.find('[data-testid="report-note-body"]').exists()).toBe(false);
        expect(view.find('[data-testid="report-submit"]').exists()).toBe(false);
    });

    it('shows a submitted report\'s notes and recipients without a form', async () => {
        const view = await render(server({ report: SUBMITTED }));

        expect(view.text()).toContain('Morning calls.');
        expect(view.findAll('[data-testid="report-recipient"]').map((r) => r.text())).toEqual(['Mona Manager']);
        expect(view.find('[data-testid="report-note-body"]').exists()).toBe(false);
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
        expect(view.find('[data-testid="report-note-body"]').exists()).toBe(false);
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

/**
 * Module 13, slice 3 — follow-ups («التواصل والمتابعة») and several notes, both
 * written by the author and saved by themselves (`D-131`, spec §5):
 *
 * - the page reads ① summary → ② activity → ③ follow-ups → ④ notes → ⑤ recipients → ⑥ submit;
 * - a row is a customer (`SearchCombobox`, the customers list the server scopes) and a note;
 * - a row or note saves 2 s after typing stops and at once when its box is left
 *   (`D-124`'s pattern, through the shared autosave helper); an empty one is not
 *   sent and says it is required; «حذف» asks first;
 * - after submit both read from the frozen copy, with no add and no remove.
 *
 * The screen sends what was typed; the server decides scope, limits and state (`D-67`).
 */
const FOLLOW_UP = { id: 'f1', customer_id: 'cu1', customer_name: 'Nile Trading', note: 'Wants a visit.' };

const NOTE = { id: 'n1', body: 'Morning calls.' };

const ROWS_DRAFT = { ...DRAFT, follow_ups: [FOLLOW_UP], notes: [NOTE] };

const ROWS_SUBMITTED = {
    ...SUBMITTED,
    follow_ups: [{ customer_id: 'cu1', customer_name: 'Nile Trading', note: 'Wants a visit.' }],
    notes: [{ body: 'Morning calls.' }],
};

const CUSTOMERS = [
    { id: 'cu1', name: 'Nile Trading', region: null, contact_person: null, phone: null },
    { id: 'cu2', name: 'Delta Foods', region: null, contact_person: null, phone: null },
];

const SECTION_ORDER = ['summary', 'activity', 'follow-ups', 'notes', 'recipients', 'submit'];

function rowsServer(report: unknown = ROWS_DRAFT): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string, init?: RequestInit) => {
        const url = String(input);
        const method = init?.method ?? 'GET';
        const body = typeof init?.body === 'string' ? JSON.parse(init.body) as Record<string, unknown> : {};

        if (method === 'DELETE') {
            return new Response(null, { status: 204 });
        }
        if (/\/follow-ups(\/[^/?]+)?$/.test(url) && method !== 'GET') {
            const customer = CUSTOMERS.find((c) => c.id === body.customer_id);

            return json(method === 'POST' ? 201 : 200, {
                data: { ...FOLLOW_UP, id: method === 'POST' ? 'f2' : 'f1', ...body, customer_name: customer?.name ?? FOLLOW_UP.customer_name },
                meta: { request_id: 'r' },
            });
        }
        if (/\/notes(\/[^/?]+)?$/.test(url) && method !== 'GET') {
            return json(method === 'POST' ? 201 : 200, { data: { ...NOTE, id: method === 'POST' ? 'n2' : 'n1', ...body }, meta: { request_id: 'r' } });
        }
        if (/\/customers(\?|$)/.test(url)) {
            return json(200, {
                data: CUSTOMERS,
                meta: {
                    request_id: 'r',
                    pagination: { page: 1, per_page: 20, total: CUSTOMERS.length, total_pages: 1, has_next_page: false, has_previous_page: false },
                },
            });
        }
        if (url.endsWith('/recipient-candidates')) {
            return envelope(CANDIDATES);
        }

        return envelope(report);
    });
}

function calls(fetchMock: ReturnType<typeof vi.fn>, method: string, path: RegExp): Array<[string, Record<string, unknown>]> {
    return fetchMock.mock.calls
        .filter(([url, init]) => ((init as RequestInit | undefined)?.method ?? 'GET') === method && path.test(String(url)))
        .map(([url, init]) => {
            const body = (init as RequestInit | undefined)?.body;

            return [String(url), typeof body === 'string' ? JSON.parse(body) as Record<string, unknown> : {}];
        });
}

describe('ReportDetailView — follow-ups and notes (D-131)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
        // Only the timers the pause uses: flushPromises needs a real setImmediate.
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('lays the page out ① summary → ② activity → ③ follow-ups → ④ notes → ⑤ recipients → ⑥ submit', async () => {
        const view = await render(rowsServer());

        const sections = SECTION_ORDER.map((name) => view.get(`[data-testid="report-section-${name}"]`).element);
        for (let i = 1; i < sections.length; i++) {
            // Each section comes after the one before it in the document, on a phone too.
            expect(sections[i - 1]!.compareDocumentPosition(sections[i]!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        }
        expect(view.get('[data-testid="report-section-submit"]').find('[data-testid="report-submit"]').exists()).toBe(true);
    });

    it('shows the draft\'s follow-ups and notes from the server', async () => {
        const view = await render(rowsServer());

        const row = view.get('[data-testid="report-follow-up-row"]');
        expect(row.text()).toContain('Nile Trading');
        expect((row.get('[data-testid="report-follow-up-note"]').element as HTMLTextAreaElement).value).toBe('Wants a visit.');
        expect((view.get('[data-testid="report-note-body"]').element as HTMLTextAreaElement).value).toBe('Morning calls.');
    });

    it('adds a follow-up: picks a customer, types, and saves 2 s after typing stops', async () => {
        const fetchMock = rowsServer();
        const view = await render(fetchMock);
        const before = view.findAll('[data-testid="report-follow-up-row"]').length;

        await view.get('[data-testid="report-follow-up-add"]').trigger('click');
        const rows = view.findAll('[data-testid="report-follow-up-row"]');
        expect(rows).toHaveLength(before + 1);
        const row = rows[rows.length - 1]!;

        await row.get('[data-testid="report-follow-up-customer"]').trigger('focus');
        await flushPromises();
        await row.findAll('[data-testid="report-follow-up-customer-option"]')[1]!.trigger('mousedown');
        await row.get('[data-testid="report-follow-up-note"]').setValue('Sent the catalogue.');
        await row.get('[data-testid="report-follow-up-note"]').trigger('input');

        vi.advanceTimersByTime(1999);
        await flushPromises();
        expect(calls(fetchMock, 'POST', /\/reports\/rep1\/follow-ups$/)).toHaveLength(0);

        vi.advanceTimersByTime(1);
        await flushPromises();
        const posts = calls(fetchMock, 'POST', /\/reports\/rep1\/follow-ups$/);
        expect(posts).toHaveLength(1);
        expect(posts[0]![1]).toEqual({ customer_id: 'cu2', note: 'Sent the catalogue.' });
        expect(view.get('[data-testid="report-section-follow-ups"]').find('[data-testid="report-autosave-status"]').exists()).toBe(true);
    });

    it('saves a follow-up at once when its box is left', async () => {
        const fetchMock = rowsServer();
        const view = await render(fetchMock);
        const note = view.get('[data-testid="report-follow-up-row"]').get('[data-testid="report-follow-up-note"]');

        await note.setValue('Called back.');
        await note.trigger('blur');
        await flushPromises();

        const patched = calls(fetchMock, 'PATCH', /\/reports\/rep1\/follow-ups\/f1$/);
        expect(patched).toHaveLength(1);
        expect(patched[0]![1]).toMatchObject({ note: 'Called back.' });
    });

    it('does not save an empty row, and marks its note required', async () => {
        const fetchMock = rowsServer();
        const view = await render(fetchMock);

        await view.get('[data-testid="report-follow-up-add"]').trigger('click');
        const rows = view.findAll('[data-testid="report-follow-up-row"]');
        const row = rows[rows.length - 1]!;
        await row.get('[data-testid="report-follow-up-customer"]').trigger('focus');
        await flushPromises();
        await row.findAll('[data-testid="report-follow-up-customer-option"]')[0]!.trigger('mousedown');
        await row.get('[data-testid="report-follow-up-note"]').trigger('blur');
        vi.advanceTimersByTime(5000);
        await flushPromises();

        expect(calls(fetchMock, 'POST', /\/follow-ups$/)).toHaveLength(0);
        expect(row.get('[data-testid="report-follow-up-note"]').attributes('aria-invalid')).toBe('true');
        expect(row.find('[data-testid="report-follow-up-note-error"]').exists()).toBe(true);
    });

    it('does not save a cleared note: the box says it is required', async () => {
        const fetchMock = rowsServer();
        const view = await render(fetchMock);
        const row = view.get('[data-testid="report-follow-up-row"]');

        await row.get('[data-testid="report-follow-up-note"]').setValue('   ');
        await row.get('[data-testid="report-follow-up-note"]').trigger('blur');
        await flushPromises();

        expect(calls(fetchMock, 'PATCH', /\/follow-ups\/f1$/)).toHaveLength(0);
        expect(row.find('[data-testid="report-follow-up-note-error"]').exists()).toBe(true);
    });

    it('asks before «حذف» removes a follow-up', async () => {
        const fetchMock = rowsServer();
        const view = await render(fetchMock);
        const row = view.get('[data-testid="report-follow-up-row"]');

        await row.get('[data-testid="report-follow-up-remove"]').trigger('click');
        await flushPromises();
        expect(calls(fetchMock, 'DELETE', /\/follow-ups\/f1$/)).toHaveLength(0);

        await row.get('[data-testid="report-follow-up-remove-confirm"]').trigger('click');
        await flushPromises();
        expect(calls(fetchMock, 'DELETE', /\/reports\/rep1\/follow-ups\/f1$/)).toHaveLength(1);
    });

    it('adds a note and saves it 2 s after typing stops; an edited note saves when left', async () => {
        const fetchMock = rowsServer();
        const view = await render(fetchMock);

        await view.get('[data-testid="report-note-add"]').trigger('click');
        const bodies = view.findAll('[data-testid="report-note-body"]');
        const fresh = bodies[bodies.length - 1]!;
        await fresh.setValue('Afternoon visit.');
        await fresh.trigger('input');
        vi.advanceTimersByTime(2000);
        await flushPromises();

        const posts = calls(fetchMock, 'POST', /\/reports\/rep1\/notes$/);
        expect(posts).toHaveLength(1);
        expect(posts[0]![1]).toEqual({ body: 'Afternoon visit.' });

        const first = view.get('[data-testid="report-note-body"]');
        await first.setValue('Morning calls, two answered.');
        await first.trigger('blur');
        await flushPromises();
        const patched = calls(fetchMock, 'PATCH', /\/reports\/rep1\/notes\/n1$/);
        expect(patched).toHaveLength(1);
        expect(patched[0]![1]).toEqual({ body: 'Morning calls, two answered.' });
    });

    it('does not save an empty note, and asks before «حذف» removes one', async () => {
        const fetchMock = rowsServer();
        const view = await render(fetchMock);

        await view.get('[data-testid="report-note-add"]').trigger('click');
        const bodies = view.findAll('[data-testid="report-note-body"]');
        await bodies[bodies.length - 1]!.trigger('blur');
        vi.advanceTimersByTime(5000);
        await flushPromises();
        expect(calls(fetchMock, 'POST', /\/notes$/)).toHaveLength(0);

        const row = view.get('[data-testid="report-note-row"]');
        await row.get('[data-testid="report-note-remove"]').trigger('click');
        await flushPromises();
        expect(calls(fetchMock, 'DELETE', /\/notes\/n1$/)).toHaveLength(0);
        await row.get('[data-testid="report-note-remove-confirm"]').trigger('click');
        await flushPromises();
        expect(calls(fetchMock, 'DELETE', /\/reports\/rep1\/notes\/n1$/)).toHaveLength(1);
    });

    it('shows a submitted report\'s follow-ups and notes read-only, from the frozen copy', async () => {
        const view = await render(rowsServer(ROWS_SUBMITTED));

        const row = view.get('[data-testid="report-follow-up-row"]');
        expect(row.text()).toContain('Nile Trading');
        expect(row.text()).toContain('Wants a visit.');
        expect(view.get('[data-testid="report-note-row"]').text()).toContain('Morning calls.');
        for (const control of ['report-follow-up-add', 'report-follow-up-remove', 'report-follow-up-note', 'report-note-add', 'report-note-remove', 'report-note-body']) {
            expect(view.find(`[data-testid="${control}"]`).exists()).toBe(false);
        }
    });

    it('shows a recipient the follow-ups and notes, read-only', async () => {
        const view = await render(rowsServer({ ...ROWS_SUBMITTED, status: 'viewed', author_name: 'Test Sales' }), 'en', MANAGER, RECEIVED_PATH);

        expect(view.get('[data-testid="report-follow-up-row"]').text()).toContain('Nile Trading');
        expect(view.get('[data-testid="report-note-row"]').text()).toContain('Morning calls.');
        expect(view.find('[data-testid="report-follow-up-add"]').exists()).toBe(false);
        expect(view.find('[data-testid="report-note-add"]').exists()).toBe(false);
    });

    it('draws the two sections in Arabic', async () => {
        const view = await render(rowsServer(), 'ar');

        const followUps = view.get('[data-testid="report-section-follow-ups"]').text();
        expect(followUps).toContain('التواصل والمتابعة');
        expect(followUps).toContain('إضافة متابعة');
        expect(followUps).toContain('حذف');
        const notes = view.get('[data-testid="report-section-notes"]').text();
        expect(notes).toContain('الملاحظات');
        expect(notes).toContain('إضافة ملاحظة');
    });

    it('draws the two sections in English, with no Arabic label', async () => {
        const view = await render(rowsServer(), 'en');

        for (const name of ['follow-ups', 'notes']) {
            const text = view.get(`[data-testid="report-section-${name}"]`).text();
            expect(text.trim()).not.toBe('');
            expect(text).not.toMatch(/[؀-ۿ]/);
        }
    });
});
