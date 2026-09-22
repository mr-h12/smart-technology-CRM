import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import QuotationDetailView from '@/pages/quotations/QuotationDetailView.vue';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 7, Point 6.5 — one quotation, as `GET /quotations/{id}` answers it.
 *
 * Every figure is the server's string (`DB-07`); every action is a request
 * the server decides (`D-67`): submit carries `If-Match` (`API-12`), a new
 * version carries `Idempotency-Key` (`OpenAPI §9.1`), and a 409 is a banner
 * asking for a reload, never a silent retry.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const QUOTATION = {
    id: 'q1',
    code: 'QT-2026-0001',
    status: 'draft',
    customer_id: 'c1',
    deal_id: 'd1',
    currency_id: 'cur-egp',
    currency: 'EGP',
    final_total: '1150.000000',
    quotation_date: '2026-09-13',
    valid_until: '2026-10-13',
    submitted_at: null,
    version: 1,
    parent_id: null,
    created_at: '2026-09-13T09:00:00+00:00',
    updated_at: '2026-09-13T09:00:00+00:00',
    discount_percent: '10.00',
    tax_percent: '14.00',
    rounding_unit: '1.000000',
    rounding_enabled: true,
    subtotal: '1000.000000',
    additional_total: '100.000000',
    discount_amount: '100.000000',
    tax_base: '900.000000',
    tax_amount: '126.000000',
    net_amount: '1026.000000',
    total_before_round: '1126.000000',
    rounding_diff: '24.000000',
    payment_terms: '50% advance. Balance on delivery.',
    warranty: null,
    delivery_terms: 'Ex works. Cairo warehouse.',
    show_delivery_terms: true,
    rejection_reason: null,
    sent_at: null,
    return_note: null,
    is_self_approved: false,
    etag: '"v1"',
    items: [
        { id: 'l1', line_no: 1, supplier_quotation_item_id: 'sqi1', product_name: 'Split unit 1.5HP', quantity: '2.000', unit_price: '500.000000', line_total: '1000.000000' },
    ],
    additional_items: [{ id: 'a1', line_no: 1, description: 'Delivery', amount: '100.000000' }],
    created_by: 'u1',
    updated_by: 'u1',
};

const COSTED_LINE = {
    ...QUOTATION.items[0],
    unit_cost: '400.000000',
    unit_cost_currency: 'EGP',
    unit_cost_fx_rate_at_time: '1.000000',
    unit_cost_base: '400.000000',
    margin_percent: '25.00',
    line_cost: '800.000000',
};

const CUSTOMER = {
    id: 'c1',
    name: 'Acme Industrial',
    customer_status: 'prospect',
    sector: null,
    region: null,
    contact_person: null,
    phone: null,
    phone2: null,
    whatsapp: null,
    email: null,
    sales_owner_id: null,
    start_date: null,
    notes: null,
    is_archived: false,
    is_incomplete: false,
    created_at: '2026-08-01T00:00:00+00:00',
    updated_at: '2026-08-01T00:00:00+00:00',
};

const USER: AuthenticatedUser = {
    id: 'u1',
    name: 'Test Sales',
    email: 'sales@example.test',
    role: { id: 'r1', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['quotation.view.own', 'quotation.edit.own', 'quotation.submit_for_approval.own', 'quotation.delete.own', 'customer.view.all'],
    is_active: true,
    unconditional_access: false,
};

const READER: AuthenticatedUser = { ...USER, id: 'u2', permissions: ['quotation.view.all'] };

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'r1', ...extra } };
}

function respond(options: {
    quotation?: unknown;
    status?: number;
    warnings?: unknown[];
    action?: (url: string, init?: RequestInit) => Response | null;
} = {}): ReturnType<typeof vi.fn> {
    const { quotation = QUOTATION, status = 200, warnings = [], action } = options;

    return vi.fn(async (input: string, init?: RequestInit) => {
        const url = String(input);

        if (url.includes('/customers/')) {
            return json(200, envelope(CUSTOMER));
        }

        if (init?.method !== undefined && init.method !== 'GET') {
            return action?.(url, init) ?? json(500, { error: { code: 'unexpected' }, meta: { request_id: 'r1' } });
        }

        return status === 200
            ? json(200, envelope(quotation, { warnings }))
            : json(status, { error: { code: status === 404 ? 'not_found' : 'forbidden', message: 'no' }, meta: { request_id: 'r1' } });
    });
}

async function signIn(profile: AuthenticatedUser, delegate: typeof globalThis.fetch): Promise<void> {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
            }))
            : delegate(input as unknown as RequestInfo, init));

    await useAuth().login(profile.email, 'Passw0rd123');
}

async function render(fetchMock: ReturnType<typeof vi.fn>, profile: AuthenticatedUser = USER, locale: 'en' | 'ar' = 'en') {
    await signIn(profile, fetchMock as unknown as typeof globalThis.fetch);
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const router = createAppRouter();

    await router.push('/quotations/q1');
    await router.isReady();

    const wrapper = mount(QuotationDetailView, { global: { plugins: [i18n, router] } });

    await flushPromises();

    return { wrapper, router };
}

/** The mutating calls the screen made — method, path, and the two headers that matter. */
function writes(fetchMock: ReturnType<typeof vi.fn>): Array<{ method: string; url: string; ifMatch: string | null; idempotencyKey: string | null }> {
    return fetchMock.mock.calls
        .filter((call) => (call[1] as RequestInit | undefined)?.method !== undefined && (call[1] as RequestInit).method !== 'GET')
        .map((call) => {
            const headers = new Headers((call[1] as RequestInit).headers);

            return {
                method: String((call[1] as RequestInit).method),
                url: String(call[0]),
                ifMatch: headers.get('If-Match'),
                idempotencyKey: headers.get('Idempotency-Key'),
            };
        });
}

describe('the quotation detail view', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    // ─────────────────────────────────────────────────────────── what it shows

    it('shows the header, the customer by name and the deal by link', async () => {
        const { wrapper } = await render(respond());

        expect(wrapper.find('[data-testid="quotation-detail-title"]').text()).toBe('QT-2026-0001');
        expect(wrapper.find('[data-testid="quotation-detail-version"]').text()).toBe('v1');
        expect(wrapper.find('[data-testid="quotation-status"]').text()).toContain('Draft');
        expect(wrapper.find('[data-testid="quotation-detail-customer"]').text()).toBe('Acme Industrial');
        expect(wrapper.find('[data-testid="quotation-detail-deal"]').attributes('href')).toBe('/deals/d1');
        // D-82 cuts money and quantities only: free text a salesperson typed renders whole,
        // periods and all — it never goes near the formatter.
        expect(wrapper.find('[data-testid="quotation-detail-payment_terms"]').text()).toBe('50% advance. Balance on delivery.');
        expect(wrapper.find('[data-testid="quotation-detail-delivery_terms"]').text()).toBe('Ex works. Cairo warehouse.');
        expect(wrapper.find('[data-testid="quotation-detail-warranty"]').text()).toBe('—');
    });

    it('draws the totals in §7.2’s groups, every figure the server’s string', async () => {
        const { wrapper } = await render(respond());

        const totals = wrapper.find('[data-testid="quotation-detail-totals"]');
        expect(totals.find('[data-testid="quotation-total-subtotal"]').text()).toBe('1000.000 EGP');
        expect(totals.find('[data-testid="quotation-total-additional_total"]').text()).toBe('100.000 EGP');
        expect(totals.find('[data-testid="quotation-total-discount_amount"]').text()).toBe('100.000 EGP');
        expect(totals.find('[data-testid="quotation-total-tax_base"]').text()).toBe('900.000 EGP');
        expect(totals.find('[data-testid="quotation-total-tax_amount"]').text()).toBe('126.000 EGP');
        expect(totals.find('[data-testid="quotation-total-rounding_diff"]').text()).toBe('24.000 EGP');
        expect(totals.find('[data-testid="quotation-total-final_total"]').text()).toBe('1150.000 EGP');
        // The percentages ride on their labels.
        expect(totals.text()).toContain('10.00%');
        expect(totals.text()).toContain('14.00%');
        // D-82 reaches the rounding unit on its label too: it is a money figure, not a
        // percentage, so it is cut like the diff beside it — owner's ruling 2026-09-21.
        expect(totals.text()).toContain('Rounding (to 1.000)');
        expect(totals.text()).not.toContain('1.000000');
    });

    it('draws no tax row at all on an exempt quotation, and no rounding row when the currency does not round', async () => {
        const { wrapper } = await render(
            respond({ quotation: { ...QUOTATION, tax_percent: null, tax_amount: null, rounding_enabled: false, rounding_diff: '0.000000' } }),
        );

        // `D-63`: exempt renders no tax line — not a zero line.
        expect(wrapper.find('[data-testid="quotation-total-tax_amount"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="quotation-total-tax_base"]').exists()).toBe(false);
        // `D-65`: rounding is per currency; off means no row.
        expect(wrapper.find('[data-testid="quotation-total-rounding_diff"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="quotation-total-final_total"]').exists()).toBe(true);
    });

    it('shows the cost columns only when the body carries them', async () => {
        const bare = await render(respond());
        expect(bare.wrapper.find('[data-testid="quotation-line-unit_cost"]').exists()).toBe(false);
        expect(bare.wrapper.find('[data-testid="quotation-lines"]').text()).not.toContain('Cost');

        const costed = await render(respond({ quotation: { ...QUOTATION, default_margin: '25.00', items: [COSTED_LINE] } }));
        expect(costed.wrapper.find('[data-testid="quotation-line-unit_cost"]').text()).toBe('400.000');
        expect(costed.wrapper.find('[data-testid="quotation-line-margin_percent"]').text()).toBe('25.00%');
        expect(costed.wrapper.find('[data-testid="quotation-detail-default_margin"]').text()).toBe('25.00%');
    });

    it('lists the additional items and the line’s quantity and price', async () => {
        const { wrapper } = await render(respond());

        expect(wrapper.find('[data-testid="quotation-line-product"]').text()).toBe('Split unit 1.5HP');
        expect(wrapper.find('[data-testid="quotation-line-quantity"]').text()).toBe('2.000');
        expect(wrapper.find('[data-testid="quotation-line-unit_price"]').text()).toBe('500.000');
        expect(wrapper.find('[data-testid="quotation-additional-description"]').text()).toBe('Delivery');
        expect(wrapper.find('[data-testid="quotation-additional-amount"]').text()).toBe('100.000');
    });

    it('draws each warning from `meta.warnings` as an alert in the server’s words', async () => {
        const { wrapper } = await render(
            respond({ warnings: [{ field: 'lines.0.supplier_quotation_item_id', code: 'supplier_price_changed', message: 'Supplier price has changed — review pricing.' }] }),
        );

        const alerts = wrapper.findAll('[data-testid="quotation-detail-warning"]');
        expect(alerts).toHaveLength(1);
        expect(alerts[0]?.attributes('role')).toBe('alert');
        expect(alerts[0]?.text()).toContain('Supplier price has changed — review pricing.');
    });

    it('names the previous version and the self-approval', async () => {
        const { wrapper } = await render(
            respond({ quotation: { ...QUOTATION, version: 2, parent_id: 'q0', status: 'approved', is_self_approved: true, rejection_reason: null } }),
        );

        expect(wrapper.find('[data-testid="quotation-detail-parent"]').attributes('href')).toBe('/quotations/q0');
        expect(wrapper.find('[data-testid="quotation-self-approved"]').text()).toContain('Self-approved');
    });

    it('shows the return note on a returned draft, and nothing otherwise (Module 8 · 3.3)', async () => {
        const returned = { ...QUOTATION, status: 'draft', return_note: 'السعر مرتفع، راجع الهامش' };
        const { wrapper } = await render(respond({ quotation: returned }));

        expect(wrapper.find('[data-testid="quotation-detail-return_note"]').text()).toBe('السعر مرتفع، راجع الهامش');

        const { wrapper: plain } = await render(respond());

        expect(plain.find('[data-testid="quotation-detail-return_note"]').exists()).toBe(false);
    });

    it('shows the rejection reason when there is one', async () => {
        const { wrapper } = await render(respond({ quotation: { ...QUOTATION, status: 'rejected', rejection_reason: 'Too expensive' } }));

        expect(wrapper.find('[data-testid="quotation-detail-rejection_reason"]').text()).toBe('Too expensive');
    });

    it('reads in Arabic through the same keys', async () => {
        const { wrapper } = await render(respond(), USER, 'ar');

        expect(wrapper.find('[data-testid="quotation-detail-totals"]').text()).toContain('الإجمالي النهائي');
    });

    // ──────────────────────────────────────────────────────────── the states

    it('says the record could not be opened on a 404, never which reason', async () => {
        const { wrapper } = await render(respond({ status: 404 }));

        expect(wrapper.find('[data-testid="quotation-detail-missing"]').exists()).toBe(true);
        expect(wrapper.html()).not.toContain('permission-denied');
    });

    it('draws a refusal for a 403 and a fault for a 500', async () => {
        const denied = await render(respond({ status: 403 }));
        expect(denied.wrapper.html()).toContain('permission-denied');

        const failed = await render(respond({ status: 500 }));
        expect(failed.wrapper.find('[data-testid="error-state"]').exists()).toBe(true);
    });

    // ───────────────────────────────────────────────────────────── the actions

    it('offers edit, submit and delete on a Draft the caller may act on, and nothing otherwise', async () => {
        const draft = await render(respond());
        expect(draft.wrapper.find('[data-testid="quotation-action-edit"]').attributes('href')).toBe('/quotations/q1/edit');
        expect(draft.wrapper.find('[data-testid="quotation-action-submit"]').exists()).toBe(true);
        expect(draft.wrapper.find('[data-testid="quotation-action-delete"]').exists()).toBe(true);
        expect(draft.wrapper.find('[data-testid="quotation-action-new-version"]').exists()).toBe(false);

        const pending = await render(respond({ quotation: { ...QUOTATION, status: 'pending' } }));
        expect(pending.wrapper.find('[data-testid="quotation-action-edit"]').exists()).toBe(false);
        expect(pending.wrapper.find('[data-testid="quotation-action-submit"]').exists()).toBe(false);
        expect(pending.wrapper.find('[data-testid="quotation-action-delete"]').exists()).toBe(false);

        // A reader holds `quotation.view` only: the API would refuse, so nothing is drawn (§3.12).
        const reader = await render(respond(), READER);
        expect(reader.wrapper.find('[data-testid="quotation-detail-actions"]').exists()).toBe(false);
    });

    it('offers a new version on the statuses `D-08` names, when the caller may edit', async () => {
        const counter = await render(respond({ quotation: { ...QUOTATION, status: 'counter' } }));
        expect(counter.wrapper.find('[data-testid="quotation-action-new-version"]').exists()).toBe(true);

        const sent = await render(respond({ quotation: { ...QUOTATION, status: 'sent' } }));
        expect(sent.wrapper.find('[data-testid="quotation-action-new-version"]').exists()).toBe(false);
    });

    it('submits with the detail’s etag as If-Match and reads the answer back', async () => {
        const fetchMock = respond({
            action: (url, init) =>
                url.endsWith('/submit-for-approval') && init?.method === 'PATCH'
                    ? json(200, envelope({ ...QUOTATION, status: 'pending', etag: '"v2"' }))
                    : null,
        });
        const { wrapper } = await render(fetchMock);

        await wrapper.find('[data-testid="quotation-action-submit"]').trigger('click');
        await flushPromises();

        expect(writes(fetchMock)).toEqual([{ method: 'PATCH', url: expect.stringContaining('/quotations/q1/submit-for-approval'), ifMatch: '"v1"', idempotencyKey: null }]);
        expect(wrapper.find('[data-testid="quotation-status"]').text()).toContain('Pending');
    });

    it('turns a 409 into a reload banner and never retries', async () => {
        const fetchMock = respond({
            action: () => json(409, { error: { code: 'concurrency_conflict', message: 'stale', details: [{ code: 'stale_version' }] }, meta: { request_id: 'r1' } }),
        });
        const { wrapper } = await render(fetchMock);

        await wrapper.find('[data-testid="quotation-action-submit"]').trigger('click');
        await flushPromises();

        expect(writes(fetchMock)).toHaveLength(1);
        const banner = wrapper.find('[data-testid="quotation-detail-conflict"]');
        expect(banner.exists()).toBe(true);
        expect(banner.text()).toContain('changed by someone else');

        const readsBefore = fetchMock.mock.calls.length;
        await banner.find('button').trigger('click');
        await flushPromises();

        // The reload is a GET, not a second attempt at the write.
        expect(writes(fetchMock)).toHaveLength(1);
        expect(fetchMock.mock.calls.length).toBeGreaterThan(readsBefore);
    });

    it('deletes only after the in-page confirmation, with If-Match, then returns to the list', async () => {
        const fetchMock = respond({ action: (_url, init) => (init?.method === 'DELETE' ? new Response(null, { status: 204 }) : null) });
        const { wrapper, router } = await render(fetchMock);

        await wrapper.find('[data-testid="quotation-action-delete"]').trigger('click');
        await flushPromises();
        expect(writes(fetchMock)).toHaveLength(0);
        expect(wrapper.find('[data-testid="quotation-delete-confirm"]').attributes('role')).toBe('alertdialog');

        await wrapper.find('[data-testid="quotation-delete-keep"]').trigger('click');
        expect(wrapper.find('[data-testid="quotation-delete-confirm"]').exists()).toBe(false);

        await wrapper.find('[data-testid="quotation-action-delete"]').trigger('click');
        await wrapper.find('[data-testid="quotation-delete-proceed"]').trigger('click');
        await flushPromises();

        expect(writes(fetchMock)).toEqual([{ method: 'DELETE', url: expect.stringContaining('/quotations/q1'), ifMatch: '"v1"', idempotencyKey: null }]);
        expect(router.currentRoute.value.path).toBe('/quotations');
    });

    it('creates a new version with one Idempotency-Key and opens the copy', async () => {
        const fetchMock = respond({
            quotation: { ...QUOTATION, status: 'partial' },
            action: (url, init) => (url.endsWith('/new-version') && init?.method === 'POST' ? json(201, envelope({ id: 'q2', code: 'QT-2026-0001', version: 2 })) : null),
        });
        const { wrapper, router } = await render(fetchMock);

        await wrapper.find('[data-testid="quotation-action-new-version"]').trigger('click');
        await flushPromises();

        const [write] = writes(fetchMock);
        expect(write?.method).toBe('POST');
        expect(write?.idempotencyKey).toMatch(/^[0-9a-f-]{36}$/);
        expect(router.currentRoute.value.path).toBe('/quotations/q2/edit');
    });

    it('explains a refused action in the server’s words', async () => {
        const fetchMock = respond({
            action: () => json(409, { error: { code: 'state_transition_invalid', message: 'This quotation cannot be submitted from its status.' }, meta: { request_id: 'r1' } }),
        });
        const { wrapper } = await render(fetchMock);

        await wrapper.find('[data-testid="quotation-action-submit"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="quotation-detail-conflict"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="quotation-detail-action-error"]').text()).toContain('This quotation cannot be submitted from its status.');
    });
});
