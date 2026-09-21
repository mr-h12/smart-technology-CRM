/**
 * `/approvals` — Module 8 Point 3.1. One page for the Team Leader and the
 * Manager (`D-10`): the pending quotations grouped by employee, `days_waiting`
 * and a **text-labelled** red chip past the SLA (`D-11`, Design System §6.4
 * "never colour alone"), Approve and Return inline. The list row carries no
 * etag, so an action reads the detail first and acts on that token; a `409`
 * is 6.5's reload banner (`API-12`), never a silent retry.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import ApprovalsView from '@/pages/approvals/ApprovalsView.vue';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const PAGINATION = { page: 1, per_page: 25, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false };

const ROW = {
    id: 'q1',
    code: 'QT-2026-0001',
    status: 'pending',
    customer_id: 'c1',
    deal_id: 'd1',
    currency_id: 'cur-egp',
    currency: 'EGP',
    final_total: '1235.000000',
    quotation_date: '2026-09-13',
    valid_until: null,
    submitted_at: '2026-09-12T09:00:00+00:00',
    days_waiting: 3,
    sla_exceeded: true,
    version: 1,
    parent_id: null,
    created_at: '2026-09-13T09:00:00+00:00',
    updated_at: '2026-09-13T09:00:00+00:00',
};

const GROUP = { key: 'u2', label: 'Sara Sales', count: 1, items: [ROW] };

const CUSTOMER = { id: 'c1', name: 'Acme Industrial', customer_status: 'prospect', is_archived: false, is_incomplete: false };

const USER: AuthenticatedUser = {
    id: 'u1',
    name: 'Test Manager',
    email: 'manager@example.test',
    role: { id: 'r1', slug: 'manager', name: 'Manager' },
    permissions: ['quotation.approve.all', 'quotation.return_with_note.all', 'quotation.view.all', 'customer.view.all'],
    is_active: true,
    unconditional_access: false,
};

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'r1', ...extra } };
}

type Reply = (init?: RequestInit) => Response;

/** A fetch that answers by `METHOD path`, recording every call. Unlisted routes are a 500 so a wrong URL fails loudly. */
function server(overrides: Record<string, Reply> = {}): ReturnType<typeof vi.fn> {
    const routes: Record<string, Reply> = {
        'GET /customers/c1': () => json(200, envelope(CUSTOMER)),
        'GET /quotations': () => json(200, envelope([GROUP], { pagination: PAGINATION })),
        'GET /quotations/q1': () => json(200, envelope({ ...ROW, etag: 'quotation:q1:7', lines: [], additional_items: [] })),
        'PATCH /quotations/q1/approve': () => json(200, envelope({ ...ROW, status: 'approved', etag: 'quotation:q1:8' })),
        'PATCH /quotations/q1/return': () => json(200, envelope({ ...ROW, status: 'draft', etag: 'quotation:q1:8' })),
        ...overrides,
    };

    return vi.fn(async (input: string, init?: RequestInit) => {
        const path = new URL(String(input), 'http://localhost').pathname.replace(/^\/api\/v1/, '').replace(/\?.*$/, '');
        const reply = routes[`${init?.method ?? 'GET'} ${path}`];

        return reply === undefined ? json(500, { error: { code: 'unrouted' } }) : reply(init);
    });
}

async function signIn(profile: AuthenticatedUser, delegate: typeof globalThis.fetch): Promise<void> {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, { data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile } }))
            : delegate(input as unknown as RequestInfo, init));
    await useAuth().login(profile.email, 'Passw0rd123');
}

async function render(fetchMock: ReturnType<typeof vi.fn>, locale: 'en' | 'ar' = 'en') {
    await signIn(USER, fetchMock as unknown as typeof globalThis.fetch);
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(ApprovalsView, { global: { plugins: [i18n, createAppRouter()] } });
    await flushPromises();

    return wrapper;
}

function calls(fetchMock: ReturnType<typeof vi.fn>, method: string, path: string): RequestInit[] {
    return fetchMock.mock.calls
        .filter((c) => (c[1]?.method ?? 'GET') === method && String(c[0]).includes(path))
        .map((c) => c[1] as RequestInit);
}

function header(init: RequestInit | undefined, name: string): string | null {
    return new Headers(init?.headers).get(name);
}

describe('the approvals screen', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
    });

    it('asks for the pending quotations grouped by employee, and draws the employee, customer, total and days waiting', async () => {
        const fetchMock = server();
        const wrapper = await render(fetchMock);

        const url = String(fetchMock.mock.calls.find((c) => String(c[0]).includes('/quotations'))?.[0]);
        expect(url).toContain('group_by=employee');
        expect(url).toContain('filter%5Bstatus%5D=pending');

        expect(wrapper.find('[data-testid="approvals-group-heading"]').text()).toBe('Sara Sales');
        expect(wrapper.find('[data-testid="approvals-code"]').text()).toBe('QT-2026-0001');
        expect(wrapper.find('[data-testid="approvals-customer"]').text()).toBe('Acme Industrial');
        expect(wrapper.find('[data-testid="approvals-total"]').text()).toBe('1235.000 EGP');
        expect(wrapper.find('[data-testid="approvals-days-waiting"]').text()).toBe('3');
    });

    it('labels an SLA breach in words, and shows nothing when the row is within it or the limit is unset', async () => {
        const breached = await render(server());
        expect(breached.find('[data-testid="approvals-sla-exceeded"]').text()).toBe('SLA exceeded');

        for (const sla_exceeded of [false, null]) {
            const within = await render(server({
                'GET /quotations': () => json(200, envelope([{ ...GROUP, items: [{ ...ROW, sla_exceeded }] }], { pagination: PAGINATION })),
            }));
            expect(within.find('[data-testid="approvals-sla-exceeded"]').exists()).toBe(false);
        }
    });

    it('approves on the detail\'s etag, then reads the list again', async () => {
        const fetchMock = server({
            'PATCH /quotations/q1/approve': () => json(200, envelope({ ...ROW, status: 'approved', etag: 'quotation:q1:8' })),
        });
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="approvals-approve"]').trigger('click');
        await flushPromises();

        const [approve] = calls(fetchMock, 'PATCH', '/quotations/q1/approve');
        expect(header(approve, 'If-Match')).toBe('quotation:q1:7');
        expect(calls(fetchMock, 'GET', '/quotations?')).toHaveLength(2);
    });

    it('links each row to the builder\'s edit-and-approve route (Module 8 · 3.2)', async () => {
        const wrapper = await render(server());

        const link = wrapper.find('[data-testid="approvals-edit"]');
        expect(link.text()).toBe('Edit & approve');
        expect(link.attributes('href')).toBe('/quotations/q1/edit-and-approve');
    });

    it('returns with a note in the body, and refuses a blank note without asking the server', async () => {
        const fetchMock = server();
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="approvals-return"]').trigger('click');
        await wrapper.find('[data-testid="approvals-return-note"]').setValue('   ');
        await wrapper.find('[data-testid="approvals-return-dialog"]').trigger('submit');
        await flushPromises();
        expect(calls(fetchMock, 'PATCH', '/return')).toHaveLength(0);

        await wrapper.find('[data-testid="approvals-return-note"]').setValue('Price too high');
        await wrapper.find('[data-testid="approvals-return-dialog"]').trigger('submit');
        await flushPromises();

        const [returned] = calls(fetchMock, 'PATCH', '/quotations/q1/return');
        expect(header(returned, 'If-Match')).toBe('quotation:q1:7');
        expect(JSON.parse(String(returned?.body))).toEqual({ note: 'Price too high' });
        expect(calls(fetchMock, 'GET', '/quotations?')).toHaveLength(2);
    });

    it('draws the reload banner on a stale token, never retrying', async () => {
        const fetchMock = server({
            'PATCH /quotations/q1/approve': () => json(409, { error: { code: 'concurrency_conflict', message: 'stale' }, meta: { request_id: 'r1' } }),
        });
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="approvals-approve"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="approvals-conflict"]').exists()).toBe(true);
        expect(calls(fetchMock, 'PATCH', '/approve')).toHaveLength(1);
    });

    it('closes the note on cancel, and tells any other refusal in the server\'s words', async () => {
        const fetchMock = server({
            'PATCH /quotations/q1/approve': () => json(422, { error: { code: 'validation_failed', message: 'A pending quotation only' }, meta: { request_id: 'r1' } }),
        });
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="approvals-return"]').trigger('click');
        expect(wrapper.find('[data-testid="approvals-return-dialog"]').exists()).toBe(true);
        await wrapper.find('[data-testid="approvals-return-cancel"]').trigger('click');
        expect(wrapper.find('[data-testid="approvals-return-dialog"]').exists()).toBe(false);
        expect(calls(fetchMock, 'PATCH', '/return')).toHaveLength(0);

        await wrapper.find('[data-testid="approvals-approve"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="approvals-action-error"]').text()).toBe('A pending quotation only');
        expect(wrapper.find('[data-testid="approvals-conflict"]').exists()).toBe(false);
    });

    it('tells a refusal, an empty page and a failure apart', async () => {
        const denied = await render(server({ 'GET /quotations': () => json(403, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } }) }));
        expect(denied.find('[data-testid="permission-denied-state"]').exists()).toBe(true);

        const empty = await render(server({ 'GET /quotations': () => json(200, envelope([], { pagination: { ...PAGINATION, total: 0 } })) }));
        expect(empty.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(empty.find('[data-testid="approvals-approve"]').exists()).toBe(false);

        const failed = await render(server({ 'GET /quotations': () => json(500, { error: { code: 'server_error' }, meta: { request_id: 'r1' } }) }));
        expect(failed.find('[data-testid="error-state"]').exists()).toBe(true);
    });

    it('reads right to left in Arabic with every label translated', async () => {
        const wrapper = await render(server(), 'ar');

        expect(wrapper.text()).not.toMatch(/approvals\./);
        expect(wrapper.find('[data-testid="approvals-sla-exceeded"]').text()).not.toBe('SLA exceeded');
    });
});
