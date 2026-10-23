import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import QuotationDetailView from '@/pages/quotations/QuotationDetailView.vue';
import { createAppRouter } from '@/router';
import { downloadFile } from '@/services/files';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

// The save gesture needs an object URL, which jsdom lacks; the name it is given is what matters.
vi.mock('@/services/files', async (original) => ({ ...(await original<object>()), downloadFile: vi.fn(async () => undefined) }));

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
    discount_percent: '10.000',
    tax_percent: '14.000',
    tax_percents: ['14.000'],
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
    // D-103: a ready term left unnamed, one left empty, and one the employee added.
    terms: [
        { key: 'payment_terms', title: null, body: '50% advance. Balance on delivery.' },
        { key: 'warranty', title: null, body: null },
        { key: null, title: 'Shipping', body: 'Ex works. Cairo warehouse.' },
    ],
    rejection_reason: null,
    sent_at: null,
    return_note: null,
    is_self_approved: false,
    purchase_order: null,
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
    margin_percent: '5.000',
    effective_margin_percent: '25.500',
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

/** `GET /quotations/{id}/pdf` (Module 9 · 4.2), as the panel reads it. */
const NO_PDF = { generation: null, latest_file_id: null };
const QUEUED_PDF = {
    generation: { job_id: 'j1', status: 'queued', requested_at: '2026-09-13T10:00:00+00:00', completed_at: null, failure_reason: null },
    latest_file_id: null,
};
const COMPLETED_PDF = {
    generation: { ...QUEUED_PDF.generation, status: 'completed', completed_at: '2026-09-13T10:00:05+00:00' },
    latest_file_id: 'f2',
};

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'r1', ...extra } };
}

function respond(options: {
    quotation?: unknown;
    status?: number;
    action?: (url: string, init?: RequestInit) => Response | null;
    pdf?: unknown[];
} = {}): ReturnType<typeof vi.fn> {
    const { quotation = QUOTATION, status = 200, action, pdf = [NO_PDF] } = options;
    let pdfServed = 0;

    return vi.fn(async (input: string, init?: RequestInit) => {
        const url = String(input);

        if (url.includes('/customers/')) {
            return json(200, envelope(CUSTOMER));
        }

        // `D-147`: the Rejected answer's reasons come from the managed list.
        if (url.includes('/managed-lists/lost_reasons')) {
            return json(200, {
                data: [
                    { code: 'price_too_high', label_en: 'Price too high', label_ar: 'السعر أعلى من المتوقع', position: 1 },
                    { code: 'other', label_en: 'Other', label_ar: 'أخرى', position: 7 },
                ],
                meta: { request_id: 'r1', pagination: { page: 1, per_page: 25, total: 2, total_pages: 1, has_next_page: false, has_previous_page: false } },
            });
        }

        // QS.2a: a line's unit is a `units` code, named from the managed list.
        if (url.includes('/managed-lists/units')) {
            return json(200, envelope([{ code: 'pcs', label_en: 'Piece', label_ar: 'قطعة', position: 1 }]));
        }

        // Module 10 · 3.3's block reads its order's files on its own.
        if (url.includes('/purchase-orders/')) {
            return json(200, envelope({ documents: [] }));
        }

        if (init?.method !== undefined && init.method !== 'GET') {
            return action?.(url, init) ?? json(500, { error: { code: 'unexpected' }, meta: { request_id: 'r1' } });
        }

        // Module 9 · 5.2's panel reads its quotation's PDF state on its own; the last answer repeats.
        if (url.endsWith('/pdf')) {
            const answer = pdf[Math.min(pdfServed, pdf.length - 1)];
            pdfServed += 1;

            return json(200, envelope(answer));
        }

        return status === 200
            ? json(200, envelope(quotation))
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

async function render(fetchMock: ReturnType<typeof vi.fn>, profile: AuthenticatedUser = USER, locale: 'en' | 'ar' = 'en', attached = false) {
    await signIn(profile, fetchMock as unknown as typeof globalThis.fetch);
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const router = createAppRouter();

    await router.push('/quotations/q1');
    await router.isReady();

    // Focus only moves inside a document; `attached` is for the tests that follow it.
    const wrapper = mount(QuotationDetailView, { global: { plugins: [i18n, router] }, ...(attached ? { attachTo: document.body } : {}) });

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
        // D-110 formats money and quantities only: free text a salesperson typed renders whole,
        // periods and all — it never goes near the formatter.
        // D-103: the terms numbered as the PDF prints them — an empty one left out, an unnamed
        // ready term under the PDF's own label.
        const terms = wrapper.find('[data-testid="quotation-detail-terms"]');
        expect(terms.element.tagName).toBe('OL');
        expect(terms.findAll('[data-testid="quotation-detail-term"]').map((term) => term.text())).toEqual([
            'Payment: 50% advance. Balance on delivery.',
            'Shipping: Ex works. Cairo warehouse.',
        ]);
        expect(wrapper.find('[data-testid="quotation-detail-payment_terms"]').exists()).toBe(false);
    });

    it('names an unnamed ready term with the Arabic label in an Arabic session', async () => {
        const { wrapper } = await render(respond(), USER, 'ar');

        expect(wrapper.findAll('[data-testid="quotation-detail-term"]').map((term) => term.text())[0]).toBe('الدفع: 50% advance. Balance on delivery.');
    });

    it('says there are no terms when none has a body', async () => {
        const { wrapper } = await render(respond({ quotation: { ...QUOTATION, terms: [{ key: 'warranty', title: null, body: null }] } }));

        expect(wrapper.find('[data-testid="quotation-detail-terms"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="quotation-detail-terms-none"]').text()).toBe('—');
    });

    it('draws the totals in §7.2’s groups, every figure the server’s string', async () => {
        const { wrapper } = await render(respond());

        const totals = wrapper.find('[data-testid="quotation-detail-totals"]');
        expect(totals.find('[data-testid="quotation-total-subtotal"]').text()).toBe('1,000.00 EGP');
        expect(totals.find('[data-testid="quotation-total-additional_total"]').text()).toBe('100.00 EGP');
        expect(totals.find('[data-testid="quotation-total-discount_amount"]').text()).toBe('100.00 EGP');
        expect(totals.find('[data-testid="quotation-total-tax_base"]').text()).toBe('900.00 EGP');
        expect(totals.find('[data-testid="quotation-total-tax_amount"]').text()).toBe('126.00 EGP');
        expect(totals.find('[data-testid="quotation-total-rounding_diff"]').text()).toBe('24.00 EGP');
        expect(totals.find('[data-testid="quotation-total-final_total"]').text()).toBe('1,150.00 EGP');
        // The percentages ride on their labels.
        expect(totals.text()).toContain('Discount (10%)');
        expect(totals.text()).toContain('Tax (14%)');
        // The rounding unit on its label is a money figure, not a percentage, so it takes
        // the diff's form beside it (owner's ruling 2026-09-21; D-110's form).
        expect(totals.text()).toContain('Rounding (to 1.00)');
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

    it('keeps a negative rounding’s minus before its digits, amount and currency in one left-to-right run (U-6, A-26)', async () => {
        // U-6: bare in an RTL page the minus is a bidi neutral, so Arabic read «EGP 0.40-».
        const { wrapper } = await render(respond({ quotation: { ...QUOTATION, rounding_diff: '-0.400000' } }), USER, 'ar');
        const run = wrapper.get('[data-testid="quotation-total-rounding_diff"] bdi');

        expect(run.attributes('dir')).toBe('ltr');
        expect(run.text()).toBe('-0.40 EGP');
    });

    it('draws every total and the header total as one left-to-right run, so Arabic does not put the code first (A-26)', async () => {
        // The pattern the quotations list pins (QuotationsView.spec.ts, A-26).
        const { wrapper } = await render(respond(), USER, 'ar');

        for (const [testid, text] of [
            ['quotation-total-subtotal', '1,000.00 EGP'],
            ['quotation-total-discount_amount', '100.00 EGP'],
            ['quotation-total-tax_base', '900.00 EGP'],
            ['quotation-total-tax_amount', '126.00 EGP'],
            ['quotation-total-additional_total', '100.00 EGP'],
            ['quotation-total-rounding_diff', '24.00 EGP'],
            ['quotation-total-final_total', '1,150.00 EGP'],
            ['quotation-detail-header-total', '1,150.00 EGP'],
        ]) {
            const run = wrapper.find(`[data-testid="${testid}"] bdi`);

            expect(run.exists(), testid).toBe(true);
            expect(run.attributes('dir'), testid).toBe('ltr');
            expect(run.text(), testid).toBe(text);
        }
    });

    it('labels a rounding unit below 0.01 with the places it needs, never 0.00 (U-7)', async () => {
        // Two places, more only when needed (owner, 2026-10-21); 1.000000 → 1.00 is pinned above.
        for (const [unit, label] of [
            ['0.010000', 'Rounding (to 0.01)'],
            ['0.001000', 'Rounding (to 0.001)'],
            ['0.005000', 'Rounding (to 0.005)'],
        ]) {
            const { wrapper } = await render(respond({ quotation: { ...QUOTATION, rounding_unit: unit } }));

            expect(wrapper.find('[data-testid="quotation-detail-totals"]').text(), unit).toContain(label);
        }
    });

    it('shows the cost columns only when the body carries them', async () => {
        const bare = await render(respond());
        expect(bare.wrapper.find('[data-testid="quotation-line-unit_cost"]').exists()).toBe(false);
        expect(bare.wrapper.find('[data-testid="quotation-lines"]').text()).not.toContain('Cost');

        const costed = await render(respond({ quotation: { ...QUOTATION, default_margin: '20.000', items: [COSTED_LINE] } }));
        expect(costed.wrapper.find('[data-testid="quotation-line-unit_cost"]').text()).toBe('400.00 EGP');
        // D-148: the column is the margin the stored figures achieve, as the server
        // computed it, never the line's own extra (5.00).
        expect(costed.wrapper.find('[data-testid="quotation-line-effective_margin_percent"]').text()).toBe('25.5%');
        expect(costed.wrapper.find('[data-testid="quotation-detail-default_margin"]').text()).toBe('20%');
    });

    // D-148 (U-4, QT-2026-0018): the cost converted into the quotation's currency, with its code — not the supplier's raw price.
    it('shows a cross-currency line’s cost in the quotation’s currency, with its code', async () => {
        const line = { ...COSTED_LINE, unit_cost: '877.190000', unit_cost_currency: 'EGP', unit_cost_fx_rate_at_time: '0.018936', unit_cost_base: '16.610000' };
        const { wrapper } = await render(respond({ quotation: { ...QUOTATION, currency: 'USD', currency_id: 'cur-usd', items: [line] } }));

        const cell = wrapper.find('[data-testid="quotation-line-unit_cost"]');
        expect(cell.text()).toBe('16.61 USD');
        expect(cell.text()).not.toContain('877.19');
        // A-26: money and its code stay one left-to-right run inside the RTL table.
        expect(cell.find('bdi[dir="ltr"]').exists()).toBe(true);
    });

    // D-148 over D-142: a negative achieved margin keeps its minus first inside the RTL table (A-26), never «%10-».
    it('draws a negative margin as one left-to-right run, minus first', async () => {
        const { wrapper } = await render(respond({ quotation: { ...QUOTATION, items: [{ ...COSTED_LINE, effective_margin_percent: '-10.000' }] } }));

        const run = wrapper.find('[data-testid="quotation-line-effective_margin_percent"] bdi[dir="ltr"]');
        expect(run.exists()).toBe(true);
        expect(run.text()).toBe('-10%');
    });

    // D-148: no cost, no margin — the server sends null and the cell says so.
    it('shows a dash for a line whose margin is null', async () => {
        const { wrapper } = await render(respond({ quotation: { ...QUOTATION, items: [{ ...COSTED_LINE, unit_cost: '0.000000', unit_cost_base: '0.000000', effective_margin_percent: null }] } }));

        expect(wrapper.find('[data-testid="quotation-line-effective_margin_percent"]').text()).toBe('—');
    });

    it('lists the additional items and the line’s quantity and price', async () => {
        const { wrapper } = await render(respond());

        expect(wrapper.find('[data-testid="quotation-line-product"]').text()).toBe('Split unit 1.5HP');
        expect(wrapper.find('[data-testid="quotation-line-quantity"]').text()).toBe('2');
        expect(wrapper.find('[data-testid="quotation-line-unit_price"]').text()).toBe('500.00');
        expect(wrapper.find('[data-testid="quotation-additional-description"]').text()).toBe('Delivery');
        expect(wrapper.find('[data-testid="quotation-additional-amount"]').text()).toBe('100.00');
    });

    it('labels every line and additional-item cell for the card form below 640 px (F-42 · A-39)', async () => {
        const { wrapper } = await render(respond());
        const tables = wrapper.findAll('table');

        expect(tables.length).toBe(2);
        expect(tables.every((table) => table.classes().includes('card-table'))).toBe(true);
        expect(wrapper.findAll('tbody td').every((cell) => (cell.attributes('data-label') ?? '') !== '')).toBe(true);
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

    it('draws the purchase order block only when the quotation carries one (Module 10 · 3.3)', async () => {
        const accepted = await render(respond({
            quotation: { ...QUOTATION, status: 'accepted', purchase_order: { id: 'po1', po_number: 'PO-2026-0001', customer_po_reference: '4500123987', po_date: '2026-09-20' } },
        }));
        expect(accepted.wrapper.find('[data-testid="purchase-order-number"]').text()).toBe('PO-2026-0001');

        const draft = await render(respond({ quotation: { ...QUOTATION, purchase_order: null } }));
        expect(draft.wrapper.find('[data-testid="quotation-purchase-order"]').exists()).toBe(false);
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

    /** F-42 · 5.8 (A-33): one primary in the action bar; Delete is row Danger text; the filled Danger is the confirm's. */
    it('draws Submit as the one primary, Delete as danger text, and the confirm\'s Delete the draft as filled Danger', async () => {
        const { wrapper } = await render(respond());
        const bar = wrapper.find('[data-testid="quotation-detail-actions"]');

        expect(bar.findAll('.create-action').map((button) => button.attributes('data-testid'))).toEqual(['quotation-action-submit']);
        expect(wrapper.find('[data-testid="quotation-action-delete"]').classes()).toContain('danger-text');

        await wrapper.find('[data-testid="quotation-action-delete"]').trigger('click');
        expect(wrapper.get('[data-testid="confirm-accept"]').text()).toBe(en.quotations.detail.deleteProceed);
        expect(wrapper.get('[data-testid="confirm-accept"]').classes()).toContain('dialog-confirm--danger');
        expect(wrapper.get('[data-testid="confirm-cancel"]').classes()).not.toContain('dialog-confirm--danger');
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
        expect(wrapper.get('[data-testid="confirm-dialog"]').attributes('role')).toBe('alertdialog');

        await wrapper.get('[data-testid="confirm-cancel"]').trigger('click');
        expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(false);

        await wrapper.find('[data-testid="quotation-action-delete"]').trigger('click');
        await wrapper.get('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        expect(writes(fetchMock)).toEqual([{ method: 'DELETE', url: expect.stringContaining('/quotations/q1'), ifMatch: '"v1"', idempotencyKey: null }]);
        expect(router.currentRoute.value.path).toBe('/quotations');
    });

    /** F-42 · 5.13 (A-22, the owner's Option B): Delete's confirmation is a modal dialog, not a strip in the page. */
    it('asks Delete in a modal dialog', async () => {
        const { wrapper } = await render(respond());

        await wrapper.find('[data-testid="quotation-action-delete"]').trigger('click');

        expect(wrapper.get('[data-testid="confirm-dialog"]').element.tagName).toBe('DIALOG');
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

    // ─────────────────────────────────── Module 10 · 3.1: send and the response

    describe('send and the customer’s response (Module 10 · 3.1)', () => {
        const SELLER: AuthenticatedUser = {
            ...USER,
            permissions: [...USER.permissions, 'quotation.send_to_customer.own', 'quotation.record_customer_response.own'],
        };

        /** The body of the one `PATCH …/respond` the screen made. */
        function respondBody(fetchMock: ReturnType<typeof vi.fn>): unknown {
            const call = fetchMock.mock.calls.find((entry) => String(entry[0]).endsWith('/respond'));

            return call === undefined ? undefined : JSON.parse(String((call[1] as RequestInit).body));
        }

        async function openDialog(status: string, action: (url: string, init?: RequestInit) => Response | null = () => null) {
            const fetchMock = respond({ quotation: { ...QUOTATION, status }, action });
            const rendered = await render(fetchMock, SELLER);

            await rendered.wrapper.find('[data-testid="quotation-action-respond"]').trigger('click');
            await flushPromises();

            return { ...rendered, fetchMock };
        }

        it('offers Send on an Approved quotation the caller may send, and nothing otherwise', async () => {
            const approved = await render(respond({ quotation: { ...QUOTATION, status: 'approved' } }), SELLER);
            expect(approved.wrapper.find('[data-testid="quotation-action-send"]').exists()).toBe(true);

            const withoutGrant = await render(respond({ quotation: { ...QUOTATION, status: 'approved' } }), USER);
            expect(withoutGrant.wrapper.find('[data-testid="quotation-action-send"]').exists()).toBe(false);

            const sent = await render(respond({ quotation: { ...QUOTATION, status: 'sent' } }), SELLER);
            expect(sent.wrapper.find('[data-testid="quotation-action-send"]').exists()).toBe(false);
        });

        it('sends with the detail’s etag as If-Match and reads the answer back', async () => {
            const fetchMock = respond({
                quotation: { ...QUOTATION, status: 'approved' },
                action: (url, init) =>
                    url.endsWith('/send') && init?.method === 'PATCH' ? json(200, envelope({ ...QUOTATION, status: 'sent', etag: '"v2"' })) : null,
            });
            const { wrapper } = await render(fetchMock, SELLER);

            await wrapper.find('[data-testid="quotation-action-send"]').trigger('click');
            await flushPromises();

            expect(writes(fetchMock)).toEqual([{ method: 'PATCH', url: expect.stringContaining('/quotations/q1/send'), ifMatch: '"v1"', idempotencyKey: null }]);
            expect(wrapper.find('[data-testid="quotation-status"]').text()).toContain('Sent');
        });

        it('offers four outcomes on a Sent quotation and only Reject, reason prefilled, on an Expired one — by permission', async () => {
            const sent = await openDialog('sent');
            const outcomes = sent.wrapper.findAll('[data-testid^="quotation-respond-outcome-"]').map((radio) => radio.attributes('value'));
            expect(outcomes).toEqual(['accepted', 'partial', 'counter', 'rejected']);

            const expired = await openDialog('expired');
            expect(expired.wrapper.findAll('[data-testid^="quotation-respond-outcome-"]').map((radio) => radio.attributes('value'))).toEqual(['rejected']);
            // §10.5: "records Rejected with reason 'no response'" — offered, still editable.
            expect((expired.wrapper.find('[data-testid="quotation-respond-reason"]').element as HTMLTextAreaElement).value).toBe('No response');

            const withoutGrant = await render(respond({ quotation: { ...QUOTATION, status: 'sent' } }), USER);
            expect(withoutGrant.wrapper.find('[data-testid="quotation-action-respond"]').exists()).toBe(false);

            const draft = await render(respond(), SELLER);
            expect(draft.wrapper.find('[data-testid="quotation-action-respond"]').exists()).toBe(false);
        });

        it('refuses a blank reason for Counter and Rejected, and asks the PO reference and date for Accepted', async () => {
            const { wrapper, fetchMock } = await openDialog('sent', (url) =>
                url.endsWith('/respond') ? json(200, envelope({ ...QUOTATION, status: 'accepted', etag: '"v2"' })) : null,
            );
            const record = () => wrapper.find('[data-testid="quotation-respond-record"]');

            for (const outcome of ['counter', 'rejected']) {
                await wrapper.find(`[data-testid="quotation-respond-outcome-${outcome}"]`).setValue(true);
                await wrapper.find('[data-testid="quotation-respond-reason"]').setValue('   ');
                expect(record().attributes('disabled')).toBeDefined();
            }

            await wrapper.find('[data-testid="quotation-respond-outcome-accepted"]').setValue(true);
            expect(wrapper.find('[data-testid="quotation-respond-reason"]').exists()).toBe(false);
            expect(record().attributes('disabled')).toBeDefined();

            await wrapper.find('[data-testid="quotation-respond-po-reference"]').setValue('CUST-PO-77');
            expect(record().attributes('disabled')).toBeDefined();
            await wrapper.find('[data-testid="quotation-respond-po-date"]').setValue('2026-09-20');
            expect(record().attributes('disabled')).toBeUndefined();

            await wrapper.find('[data-testid="quotation-respond-dialog"]').trigger('submit');
            await flushPromises();

            expect(writes(fetchMock)).toEqual([{ method: 'PATCH', url: expect.stringContaining('/quotations/q1/respond'), ifMatch: '"v1"', idempotencyKey: null }]);
            expect(respondBody(fetchMock)).toEqual({ response: 'accepted', customer_po_reference: 'CUST-PO-77', po_date: '2026-09-20' });
            expect(wrapper.find('[data-testid="quotation-status"]').text()).toContain('Accepted');
            expect(wrapper.find('[data-testid="quotation-respond-dialog"]').exists()).toBe(false);
        });

        /** F-42 · 5.13 (A-22): a greyed Record says why, and the button points at that sentence. */
        it('says why Record is disabled, and nothing once it is enabled', async () => {
            const { wrapper } = await openDialog('sent');
            const hint = () => wrapper.find('[data-testid="quotation-respond-hint"]');

            await wrapper.find('[data-testid="quotation-respond-outcome-accepted"]').setValue(true);
            expect(hint().text()).toBe(en.quotations.detail.recordNeedsPo);
            expect(wrapper.get('[data-testid="quotation-respond-record"]').attributes('aria-describedby')).toBe(hint().attributes('id'));

            // `D-147`: a rejection first asks why, from the list.
            await wrapper.find('[data-testid="quotation-respond-outcome-rejected"]').setValue(true);
            expect(hint().text()).toBe(en.quotations.detail.recordNeedsReasonCode);

            await wrapper.find('[data-testid="quotation-respond-reason-code"]').setValue('price_too_high');
            expect(hint().exists()).toBe(false);

            await wrapper.find('[data-testid="quotation-respond-outcome-counter"]').setValue(true);
            expect(hint().text()).toBe(en.quotations.detail.recordNeedsReason);
        });

        // The ring itself is app.css's one :focus-visible rule (A-34), pinned in uiSourceRules.spec.ts.
        it('shows each response field as a field: a hint where text goes (D-108)', async () => {
            const { wrapper } = await openDialog('sent', () => null);

            await wrapper.find('[data-testid="quotation-respond-outcome-rejected"]').setValue(true);
            const reason = wrapper.find('[data-testid="quotation-respond-reason"]');
            expect(reason.attributes('placeholder')).toBe('Type the reason here…');
            expect(reason.classes()).toContain('form-field');

            await wrapper.find('[data-testid="quotation-respond-outcome-accepted"]').setValue(true);
            const reference = wrapper.find('[data-testid="quotation-respond-po-reference"]');
            expect(reference.attributes('placeholder')).toBe('e.g. 4500123987');
            for (const field of [reference, wrapper.find('[data-testid="quotation-respond-po-date"]')]) {
                expect(field.classes()).toContain('form-field');
            }
        });

        it('offers no PO date after today in the date picker (D-108)', async () => {
            vi.useFakeTimers({ toFake: ['Date'] });
            vi.setSystemTime(new Date(2026, 9, 11, 23, 30));
            try {
                const { wrapper } = await openDialog('sent', () => null);

                await wrapper.find('[data-testid="quotation-respond-outcome-accepted"]').setValue(true);

                expect(wrapper.find('[data-testid="quotation-respond-po-date"]').attributes('max')).toBe('2026-10-11');
            } finally {
                vi.useRealTimers();
            }
        });

        it('opens the new draft after Partial or Counter', async () => {
            for (const outcome of ['partial', 'counter']) {
                const { wrapper, router, fetchMock } = await openDialog('sent', (url) =>
                    url.endsWith('/respond')
                        ? json(200, envelope({ ...QUOTATION, status: outcome, etag: '"v2"', new_version: { id: 'q2', code: 'QT-2026-0001', version: 2 } }))
                        : null,
                );

                await wrapper.find(`[data-testid="quotation-respond-outcome-${outcome}"]`).setValue(true);
                if (outcome === 'counter') {
                    await wrapper.find('[data-testid="quotation-respond-reason"]').setValue('Price too high');
                }
                await wrapper.find('[data-testid="quotation-respond-dialog"]').trigger('submit');
                await flushPromises();

                expect(respondBody(fetchMock)).toEqual(outcome === 'counter' ? { response: 'counter', reason: 'Price too high' } : { response: 'partial' });
                expect(router.currentRoute.value.path).toBe('/quotations/q2/edit');
            }
        });

        /** `D-147`: the reason is picked from `lost_reasons`; the note is optional, except for "other". */
        it('sends the chosen reason code, with a note only when one is written, and asks for the note on "other"', async () => {
            const { wrapper, fetchMock } = await openDialog('sent', (url) =>
                url.endsWith('/respond') ? json(200, envelope({ ...QUOTATION, status: 'rejected', etag: '"v2"', deal_lost: true })) : null,
            );
            const record = () => wrapper.find('[data-testid="quotation-respond-record"]');

            await wrapper.find('[data-testid="quotation-respond-outcome-rejected"]').setValue(true);
            expect(wrapper.findAll('[data-testid="quotation-respond-reason-code"] option').map((o) => o.attributes('value'))).toEqual(['', 'price_too_high', 'other']);

            await wrapper.find('[data-testid="quotation-respond-reason-code"]').setValue('other');
            expect(record().attributes('disabled')).toBeDefined();

            await wrapper.find('[data-testid="quotation-respond-reason-code"]').setValue('price_too_high');
            expect(record().attributes('disabled')).toBeUndefined();

            await wrapper.find('[data-testid="quotation-respond-dialog"]').trigger('submit');
            await flushPromises();

            expect(respondBody(fetchMock)).toEqual({ response: 'rejected', reason_code: 'price_too_high' });
        });

        it('says whether the rejection made the deal Lost', async () => {
            for (const [dealLost, words] of [[true, 'The deal is now Lost.'], [false, 'The deal keeps its status']] as const) {
                const { wrapper, fetchMock } = await openDialog('expired', (url) =>
                    url.endsWith('/respond') ? json(200, envelope({ ...QUOTATION, status: 'rejected', etag: '"v2"', deal_lost: dealLost })) : null,
                );

                await wrapper.find('[data-testid="quotation-respond-dialog"]').trigger('submit');
                await flushPromises();

                // An expired quotation's 'no response' is no reason on the list: `other`, with that note (`D-147`).
                expect(respondBody(fetchMock)).toEqual({ response: 'rejected', reason_code: 'other', reason: 'No response' });
                expect(wrapper.find('[data-testid="quotation-detail-deal-outcome"]').text()).toContain(words);
            }
        });

        it('turns a 409 on send or respond into the reload banner and never retries', async () => {
            const stale = () => json(409, { error: { code: 'concurrency_conflict', message: 'stale' }, meta: { request_id: 'r1' } });

            const sending = await render(respond({ quotation: { ...QUOTATION, status: 'approved' }, action: stale }), SELLER);
            await sending.wrapper.find('[data-testid="quotation-action-send"]').trigger('click');
            await flushPromises();
            expect(sending.wrapper.find('[data-testid="quotation-detail-conflict"]').exists()).toBe(true);

            const { wrapper, fetchMock } = await openDialog('sent', stale);
            await wrapper.find('[data-testid="quotation-respond-outcome-partial"]').setValue(true);
            await wrapper.find('[data-testid="quotation-respond-dialog"]').trigger('submit');
            await flushPromises();

            expect(writes(fetchMock)).toHaveLength(1);
            expect(wrapper.find('[data-testid="quotation-detail-conflict"]').exists()).toBe(true);
        });

        it('returns focus to the button that opened the dialog when it closes (Design System §6.6)', async () => {
            const { wrapper } = await render(respond({ quotation: { ...QUOTATION, status: 'sent' } }), SELLER, 'en', true);
            const opener = wrapper.find('[data-testid="quotation-action-respond"]');

            for (const close of [
                () => wrapper.find('[data-testid="quotation-respond-dialog"]').trigger('keydown', { key: 'Escape' }),
                () => wrapper.find('[data-testid="quotation-respond-cancel"]').trigger('click'),
            ]) {
                await opener.trigger('click');
                await flushPromises();
                expect(document.activeElement).not.toBe(opener.element);

                await close();
                await flushPromises();
                expect(document.activeElement).toBe(opener.element);
            }

            wrapper.unmount();
        });

        it('closes the dialog on Escape or Cancel without writing (Design System §6.6)', async () => {
            const { wrapper, fetchMock } = await openDialog('sent');

            await wrapper.find('[data-testid="quotation-respond-dialog"]').trigger('keydown', { key: 'Escape' });
            expect(wrapper.find('[data-testid="quotation-respond-dialog"]').exists()).toBe(false);

            await wrapper.find('[data-testid="quotation-action-respond"]').trigger('click');
            await flushPromises();
            await wrapper.find('[data-testid="quotation-respond-cancel"]').trigger('click');
            expect(wrapper.find('[data-testid="quotation-respond-dialog"]').exists()).toBe(false);

            expect(writes(fetchMock)).toHaveLength(0);
        });
    });

    // ─────────────────────────────── Module 9 · 5.2: the customer PDF on the page

    describe('the customer PDF panel on the page (Module 9 · 5.2)', () => {
        /** §3.5: the export row is what draws the panel; the sales roles hold it `Own`. */
        const EXPORTER: AuthenticatedUser = { ...USER, id: 'u3', permissions: [...USER.permissions, 'quotation.export_pdf.own'] };

        function pdfReads(fetchMock: ReturnType<typeof vi.fn>): number {
            return fetchMock.mock.calls.filter((call) => String(call[0]).endsWith('/quotations/q1/pdf')).length;
        }

        beforeEach(() => {
            vi.mocked(downloadFile).mockClear();
        });

        it('draws the panel for this quotation, and downloads under its code, when the caller holds quotation.export_pdf', async () => {
            const fetchMock = respond({ pdf: [COMPLETED_PDF] });
            const { wrapper } = await render(fetchMock, EXPORTER);

            expect(wrapper.find('[data-testid="quotation-pdf-panel"]').exists()).toBe(true);
            expect(pdfReads(fetchMock)).toBe(1);
            expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.ready);

            await wrapper.get('[data-testid="pdf-download"]').trigger('click');
            await flushPromises();
            expect(downloadFile).toHaveBeenCalledWith('f2', 'QT-2026-0001.pdf');
        });

        it('draws no panel, and asks for no PDF state, without quotation.export_pdf (the Outdoor Supervisor)', async () => {
            const fetchMock = respond();
            const { wrapper } = await render(fetchMock, USER);

            expect(wrapper.find('[data-testid="quotation-pdf-panel"]').exists()).toBe(false);
            expect(pdfReads(fetchMock)).toBe(0);
        });

        it('reads the PDF state again after Send, so the render the send queued shows (Q14)', async () => {
            const sender: AuthenticatedUser = { ...EXPORTER, permissions: [...EXPORTER.permissions, 'quotation.send_to_customer.own'] };
            const fetchMock = respond({
                quotation: { ...QUOTATION, status: 'approved' },
                pdf: [NO_PDF, QUEUED_PDF],
                action: (url, init) =>
                    url.endsWith('/send') && init?.method === 'PATCH' ? json(200, envelope({ ...QUOTATION, status: 'sent', etag: '"v2"' })) : null,
            });
            const { wrapper } = await render(fetchMock, sender);
            expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.none);

            await wrapper.get('[data-testid="quotation-action-send"]').trigger('click');
            await flushPromises();

            expect(pdfReads(fetchMock)).toBe(2);
            expect(wrapper.get('[data-testid="pdf-status"]').text()).toBe(en.pdf.panel.queued);

            // A queued render polls; unmounting stops it before the next test.
            wrapper.unmount();
        });
    });
});

/**
 * QS.2a — a line owns what was typed (`D-155`): its supplier, unit, entered price
 * and tax. `GET /quotations/{id}` always carries `unit_code`, and the other three
 * only for a caller with `quotation.view_cost_and_margin` (contract §4); the page
 * draws what the payload carries and decides nothing (Q7, `D-67`).
 */
describe('the quotation detail view — line pricing (QS.2a)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    /** D-156's E1 as a cost viewer reads it: entered 114 at 14 % tax, priced 120.00. */
    const PRICED_LINE = {
        ...COSTED_LINE,
        supplier_quotation_item_id: null,
        catalog_item_id: 'ci1',
        product_name: 'Pump 5HP',
        supplier_id: 's1',
        supplier_name: 'Nile Pumps Co',
        unit_code: 'pcs',
        entered_unit_price: '114.000000',
        tax_percent: '14.000',
        quantity: '3.0000',
        unit_price: '120.000000',
        line_total: '360.000000',
        unit_cost: '100.000000',
        unit_cost_base: '100.000000',
        margin_percent: '20.000',
    };

    /** A `tax_included` quotation (D-165): no quotation margin, no quotation rate, the VAT the lines' sum (D-157). */
    const PRICED = {
        ...QUOTATION,
        pricing_mode: 'tax_included',
        default_margin: null,
        tax_percent: null,
        tax_percents: [],
        subtotal: '360.000000',
        discount_amount: '36.000000',
        tax_base: '324.000000',
        tax_amount: '45.360000',
        additional_total: '50.000000',
        net_amount: '374.000000',
        total_before_round: '419.360000',
        rounding_enabled: false,
        rounding_diff: '0.000000',
        final_total: '419.360000',
        items: [PRICED_LINE],
    };

    it('shows each line’s supplier, unit, entered price and tax when the payload carries them (D-155)', async () => {
        const { wrapper } = await render(respond({ quotation: PRICED }));

        expect(wrapper.get('[data-testid="quotation-line-supplier"]').text()).toBe('Nile Pumps Co');
        expect(wrapper.get('[data-testid="quotation-line-unit"]').text()).toBe('Piece');
        expect(wrapper.get('[data-testid="quotation-line-entered_unit_price"]').text()).toContain('114.00');
        expect(wrapper.get('[data-testid="quotation-line-entered_unit_price"]').text()).not.toContain('114.000000');
        expect(wrapper.get('[data-testid="quotation-line-tax_percent"]').text()).toMatch(/^14\s*%$/);
    });

    it('names the unit in the session’s language', async () => {
        const { wrapper } = await render(respond({ quotation: PRICED }), USER, 'ar');

        expect(wrapper.get('[data-testid="quotation-line-unit"]').text()).toBe('قطعة');
    });

    // §3.5 / Q7: without `view cost & margin` the server strips supplier, entered price and tax; the unit stays.
    it('shows the unit and none of the supplier, entered price or tax when the payload strips them', async () => {
        const stripped = { ...QUOTATION, items: [{ ...QUOTATION.items[0], unit_code: 'pcs' }] };
        const { wrapper } = await render(respond({ quotation: stripped }), READER);

        expect(wrapper.get('[data-testid="quotation-line-unit"]').text()).toBe('Piece');
        expect(wrapper.find('[data-testid="quotation-line-supplier"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="quotation-line-entered_unit_price"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="quotation-line-tax_percent"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="quotation-lines"]').text()).not.toContain('Nile Pumps Co');
    });

    // D-165: `default_margin` is non-null only on a legacy quotation; the new pricing has none to show.
    it('shows the default margin only when it is not null', async () => {
        const priced = await render(respond({ quotation: PRICED }));
        expect(priced.wrapper.find('[data-testid="quotation-detail-default_margin"]').exists()).toBe(false);

        const legacy = await render(respond({ quotation: { ...QUOTATION, default_margin: '10.000', items: [COSTED_LINE] } }));
        expect(legacy.wrapper.find('[data-testid="quotation-detail-default_margin"]').text()).toBe('10%');
    });

    // D-157: the VAT row is the sum of the lines' taxes — there is no single quotation rate to print beside it.
    it('draws a tax_included quotation’s VAT row with its amount and no empty rate', async () => {
        const { wrapper } = await render(respond({ quotation: PRICED }));
        const totals = wrapper.get('[data-testid="quotation-detail-totals"]');

        expect(totals.get('[data-testid="quotation-total-tax_amount"]').text()).toBe('45.36 EGP');
        expect(totals.text()).not.toContain('(%)');
        expect(totals.text()).not.toContain('null');
        expect(totals.get('[data-testid="quotation-total-final_total"]').text()).toBe('419.36 EGP');
    });

    // D-163: under «إرسال للاعتماد», the rule as it stands — no permission changes.
    it.each([
        { locale: 'ar' as const, note: 'لا يمكن التعديل بعد الإرسال للاعتماد إلا إذا أعاده المدير أو قائد الفريق' },
        { locale: 'en' as const, note: 'Once sent for approval, it can\'t be edited unless a Manager or Team Leader returns it.' },
    ])('writes the $locale note under Submit for approval', async ({ locale, note }) => {
        const { wrapper } = await render(respond(), USER, locale);
        const submit = wrapper.get('[data-testid="quotation-action-submit"]').element;
        const written = wrapper.get('[data-testid="quotation-detail-submit-note"]');

        expect(written.text()).toBe(note);
        expect(submit.compareDocumentPosition(written.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('writes no submit note where there is no Submit', async () => {
        const pending = await render(respond({ quotation: { ...QUOTATION, status: 'pending' } }));
        expect(pending.wrapper.find('[data-testid="quotation-detail-submit-note"]').exists()).toBe(false);

        const reader = await render(respond(), READER);
        expect(reader.wrapper.find('[data-testid="quotation-detail-submit-note"]').exists()).toBe(false);
    });
});

/** F-42 · P5: the total beside the code (A-56), and no raw id where a name cannot be read (A-18). */
describe('the quotation detail view (F-42 · P5)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('A-56: repeats the payload’s final total in the header, exactly as the totals block shows it', async () => {
        const { wrapper } = await render(respond(), USER, 'ar');

        const header = wrapper.find('header [data-testid="quotation-detail-header-total"]');

        expect(header.exists()).toBe(true);
        expect(header.text()).toBe(wrapper.find('[data-testid="quotation-total-final_total"]').text());
    });

    it('A-18: shows «—», never a uuid, where the customer’s name cannot be read', async () => {
        const UUID = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i;
        const base = respond({ quotation: { ...QUOTATION, customer_id: '9d0f3c2a-1b2c-4d5e-8f90-a1b2c3d4e5f6', deal_id: '7a1e2b3c-4d5e-4f60-8a7b-9c0d1e2f3a4b' } }) as unknown as (input: string, init?: RequestInit) => Promise<Response>;
        const fetchMock = vi.fn(async (input: string, init?: RequestInit) =>
            String(input).includes('/customers/')
                ? json(403, { error: { code: 'forbidden', message: 'no' }, meta: { request_id: 'r1' } })
                : base(input, init));

        const { wrapper } = await render(fetchMock, READER);

        expect(wrapper.find('[data-testid="quotation-detail-customer"]').text()).toBe('—');
        expect(wrapper.text()).not.toMatch(UUID);
        expect(wrapper.find('[data-testid="quotation-detail-deal"]').attributes('href')).toBe('/deals/7a1e2b3c-4d5e-4f60-8a7b-9c0d1e2f3a4b');
    });
});

describe('the quotation status chip (A-66, Q-41)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('carries no title that repeats its own words', async () => {
        const { wrapper } = await render(respond());

        expect(wrapper.find('[data-testid="quotation-status"]').attributes('title')).toBeUndefined();
    });
});

/**
 * F-49 · 1.4a (`D-119`): the quotation page shows its deal's status and the
 * manual move. The deal is read best-effort through `GET /deals/{id}`, the way
 * the page reads its customer — every `change_status` holder also holds
 * `deal.view` (§3.4), so whoever may see the control can read the deal.
 */
describe('the deal status on the quotation page (F-49 · 1.4a, D-119)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    const DEAL = {
        id: 'd1', code: 'DL-2026-0007', customer_id: 'c1', title: 'Twelve pumps', details: null,
        source: 'employee_entry', service_type: 'product', status: 'won', owner_id: 'u1',
        approval_status: null, rejection_reason: null, lost_reason: null,
        last_activity_at: '2026-10-01T09:00:00+00:00', created_at: '2026-10-01T09:00:00+00:00', updated_at: '2026-10-01T09:00:00+00:00',
    };

    const DEAL_VIEWER: AuthenticatedUser = { ...USER, permissions: [...USER.permissions, 'deal.view.own'] };
    const CHANGER: AuthenticatedUser = { ...USER, permissions: [...DEAL_VIEWER.permissions, 'deal.change_status.own'] };

    /** `respond()` plus the deal: `GET /deals/d1` answers with `dealStatus`, and the move answers `purchasing`. */
    function withDeal(dealStatus = 200): ReturnType<typeof vi.fn> {
        const base = respond({
            action: (url) => (/\/deals\/d1\/status$/.test(url) ? json(200, envelope({ ...DEAL, status: 'purchasing' })) : null),
        }) as unknown as (input: string, init?: RequestInit) => Promise<Response>;

        return vi.fn(async (input: string, init?: RequestInit) =>
            /\/deals\/d1$/.test(String(input))
                ? (dealStatus === 200
                    ? json(200, envelope(DEAL))
                    : json(dealStatus, { error: { code: 'forbidden', message: 'no' }, meta: { request_id: 'r1' } }))
                : base(input, init));
    }

    function askedForDeal(fetchMock: ReturnType<typeof vi.fn>): boolean {
        return fetchMock.mock.calls.some((c) => /\/deals\/d1$/.test(String(c[0])));
    }

    it('shows the deal’s status beside its link, and the next manual move from won onward', async () => {
        const { wrapper } = await render(withDeal(), CHANGER);

        expect(wrapper.get('[data-testid="quotation-detail-deal-status"]').text()).toBe('Won');
        expect(wrapper.get('[data-testid="deal-status-next"]').text()).toBe('Move to Purchasing');
        expect(wrapper.find('[data-testid="quotation-detail-deal"]').exists()).toBe(true);
    });

    it('a confirmed move redraws the chip with the server’s new status', async () => {
        const fetchMock = withDeal();
        const { wrapper } = await render(fetchMock, CHANGER);

        await wrapper.get('[data-testid="deal-status-next"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some((c) => /\/deals\/d1\/status$/.test(String(c[0])))).toBe(true);
        expect(wrapper.get('[data-testid="quotation-detail-deal-status"]').text()).toBe('Purchasing');
        expect(wrapper.get('[data-testid="deal-status-next"]').text()).toBe('Move to Delivery');
    });

    it('shows the chip without the control to a deal viewer who cannot change status', async () => {
        const { wrapper } = await render(withDeal(), DEAL_VIEWER);

        expect(wrapper.get('[data-testid="quotation-detail-deal-status"]').text()).toBe('Won');
        expect(wrapper.find('[data-testid="deal-status-next"]').exists()).toBe(false);
    });

    it('draws neither when the deal cannot be read, and the rest of the page stands', async () => {
        const { wrapper } = await render(withDeal(403), CHANGER);

        expect(wrapper.find('[data-testid="quotation-detail-deal-status"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="deal-status-next"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="quotation-detail-deal"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="quotation-total-final_total"]').exists()).toBe(true);
    });

    it('does not ask for the deal without deal.view', async () => {
        const fetchMock = withDeal();
        await render(fetchMock, USER);

        expect(askedForDeal(fetchMock)).toBe(false);
    });
});
