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
 * sees the day's activity (`D-126`, read by the server from the audit log,
 * `D-129`), chooses recipients (default preselected, `D-129`) and submits.
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

const COUNTS = {
    deals_created: 2, deal_status_moves: 1, quotations_created: 1,
    quotations_sent: 1, customer_responses: 2, purchase_orders: 1,
};

const DRAFT = {
    id: 'rep1', code: 'RPT-DY-2026-0001', type: 'daily', status: 'draft',
    period_start: '2026-10-15', period_end: '2026-10-15', submitted_at: null,
    notes: 'Morning calls.', etag: 'report:rep1:1',
    activity: {
        counts: COUNTS,
        items: [
            { event: 'DEAL_CREATED', entity_type: 'deal', entity_id: 'd1', occurred_at: '2026-10-15T07:00:00Z' },
            { event: 'QUOTATION_SENT', entity_type: 'quotation', entity_id: 'q1', occurred_at: '2026-10-15T08:20:00Z' },
        ],
    },
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
    await router.push('/reports/rep1');
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

    it('lists the day\'s activity in the order the server gives it', async () => {
        const view = await render(server());

        const items = view.findAll('[data-testid="activity-item"]');
        expect(items).toHaveLength(2);
        expect(items[0].text()).toContain(en.reports.event.DEAL_CREATED);
        expect(items[1].text()).toContain(en.reports.event.QUOTATION_SENT);
    });

    it('shows an empty activity message when the day has none', async () => {
        const quiet = { ...DRAFT, activity: { counts: { ...COUNTS, deals_created: 0 }, items: [] } };
        const view = await render(server({ report: quiet }));

        expect(view.find('[data-testid="activity-empty"]').exists()).toBe(true);
    });

    it('saves the notes with If-Match and adopts the new etag', async () => {
        const fetchMock = server({ patch: (_url, init) => envelope({ ...DRAFT, notes: JSON.parse(init.body as string).notes, etag: 'report:rep1:2' }) });
        const view = await render(fetchMock);

        expect((view.get('[data-testid="report-notes"]').element as HTMLTextAreaElement).value).toBe('Morning calls.');
        await view.get('[data-testid="report-notes"]').setValue('Two visits.');
        await view.get('[data-testid="report-save"]').trigger('click');
        await flushPromises();

        const [url, init] = patches(fetchMock)[0];
        expect(url).toMatch(/\/reports\/rep1$/);
        expect(JSON.parse(init.body as string)).toEqual({ notes: 'Two visits.' });
        expect(new Headers(init.headers).get('If-Match')).toBe('report:rep1:1');

        await view.get('[data-testid="report-save"]').trigger('click');
        await flushPromises();
        expect(new Headers(patches(fetchMock)[1][1].headers).get('If-Match')).toBe('report:rep1:2');
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
        expect((options[0].get('input').element as HTMLInputElement).checked).toBe(true);
        expect((options[1].get('input').element as HTMLInputElement).checked).toBe(false);
    });

    it('cannot submit with no recipient chosen', async () => {
        const view = await render(server());

        await view.findAll('[data-testid="recipient-option"]')[0].get('input').setValue(false);

        expect(view.get('[data-testid="report-submit"]').attributes('disabled')).toBeDefined();
    });

    it('submits the chosen recipients under If-Match and becomes read-only', async () => {
        const fetchMock = server({ patch: () => envelope(SUBMITTED) });
        const view = await render(fetchMock);

        await view.findAll('[data-testid="recipient-option"]')[1].get('input').setValue(true);
        await view.get('[data-testid="report-submit"]').trigger('click');
        await flushPromises();

        const [url, init] = patches(fetchMock)[0];
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
