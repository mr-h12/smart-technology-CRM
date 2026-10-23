import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import QuotationBuilderView from '@/pages/quotations/QuotationBuilderView.vue';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 7, Point 6.6 — the builder, create.
 *
 * The screen collects what `SaveQuotationRequest` accepts and sends it once
 * as `POST /quotations` with an `Idempotency-Key` minted when the form opened
 * (`OpenAPI §9.1`): a retry replays, a new form mints anew. Every figure is a
 * string (`DB-07`); nothing is priced here (`D-67`). QS.2a: a line is typed
 * in an entry row — supplier, product, unit, entered price, tax, margin,
 * quantity — and Enter moves it into a table (`D-155`); the «شامل الضريبة»
 * toggle is the preparer's (`D-157`); no quotation margin, no quotation tax
 * rates, no supplier offer (`D-154`, `D-156`); no warning rides a save (`D-161`).
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const DEAL = {
    id: 'd1', code: 'DL-2026-0001', customer_id: 'c1', title: 'Pumps', source: null, service_type: null,
    status: 'supplier_quotation', owner_id: 'u1', approval_status: null, rejection_reason: null, lost_reason: null,
    last_activity_at: '2026-09-10T09:00:00+00:00', created_at: '2026-09-01T09:00:00+00:00', updated_at: '2026-09-10T09:00:00+00:00',
};

const CUSTOMER = { id: 'c1', name: 'Acme Industrial' };

const SUPPLIERS = [{ id: 's1', name: 'Nile Pumps Co', type: null }, { id: 's2', name: 'Delta Valves', type: null }];

const CATALOG = [{ id: 'ci1', name: 'Pump 5HP', service_type: null }, { id: 'ci2', name: null, service_type: 'Installation' }];

const SQ_DEAL = { id: 'sq1', code: 'SQ-2026-0001', supplier_id: 's1', deal_id: 'd1', total_price: null, currency_id: null, offer_date: null, valid_until: null, notes: null };
const SQ_OTHER = { id: 'sq2', code: 'SQ-2026-0002', supplier_id: 's2', deal_id: null, total_price: null, currency_id: null, offer_date: null, valid_until: null, notes: null };

const SQ_DETAIL = {
    ...SQ_DEAL,
    items: [
        // F-05 (D-81): the payload carries the balance beside the offer; 2 of 5 already consumed.
        { id: 'sqi1', catalog_item_id: 'ci1', unit_price: '1000.000000', quantity: '5.0000', consumed_quantity: '2.0000', available_quantity: '3.0000' },
        { id: 'sqi2', catalog_item_id: 'ci2', unit_price: '250.000000', quantity: '1.000', consumed_quantity: '0.0000', available_quantity: '1.000' },
    ],
};

/** `GET /managed-lists/delivery_terms` as `ManagedListController::payload()` builds it: both labels, the server's order (F-32). */
const TERMS = [
    { code: 'within_1_2_weeks', label_en: 'Within 1–2 weeks from receipt of the purchase order', label_ar: 'خلال أسبوع إلى أسبوعين من استلام أمر الشراء', position: 1 },
    { code: 'within_4_6_weeks', label_en: 'Within 4–6 weeks from receipt of the purchase order', label_ar: 'خلال 4 إلى 6 أسابيع من استلام أمر الشراء', position: 2 },
    { code: 'at_customer_site', label_en: 'Delivered to the customer’s site', label_ar: 'التسليم في موقع العميل', position: 3 },
];

/** QS.2a — `GET /managed-lists/units`: the entry row's unit is picked from it, by code. */
const UNITS = [
    { code: 'pcs', label_en: 'Piece', label_ar: 'قطعة', position: 1 },
    { code: 'm', label_en: 'Metre', label_ar: 'متر', position: 2 },
];

/** What `POST /quotations` really answers — `QuotationPayload::of()`: no etag, no detail (F-03 found the SPA assuming one). */
const CREATED = { id: 'q9', code: 'QT-2026-0009' };

/** A Draft as `GET /quotations/{id}` answers it, with §3.5's cost keys (Q7). */
const QUOTATION = {
    id: 'q1', code: 'QT-2026-0001', status: 'draft', customer_id: 'c1', deal_id: 'd1', currency_id: 'cur-egp', currency: 'EGP',
    final_total: '1150.000000', quotation_date: '2026-09-13', valid_until: '2026-10-13', submitted_at: null, version: 1, parent_id: null,
    created_at: '2026-09-13T09:00:00+00:00', updated_at: '2026-09-13T09:00:00+00:00',
    default_margin: '25.000', discount_percent: '10.000', tax_percent: '14.000', tax_percents: ['14.000'], rounding_unit: '1.000000', rounding_enabled: true,
    subtotal: '1000.000000', additional_total: '100.000000', discount_amount: '100.000000', tax_base: '900.000000', tax_amount: '126.000000',
    net_amount: '1026.000000', total_before_round: '1126.000000', rounding_diff: '24.000000',
    // D-103: the quotation's own terms, in their order; this one deleted its ready warranty.
    terms: [{ key: 'payment_terms', title: null, body: '50% advance' }, { key: 'delivery_terms', title: null, body: 'Ex works' }],
    rejection_reason: null, sent_at: null, is_self_approved: false, etag: '"v1"',
    items: [
        { id: 'l1', line_no: 1, supplier_quotation_item_id: 'sqi1', product_name: 'Split unit 1.5HP', quantity: '2.000', unit_price: '500.000000', line_total: '1000.000000', unit_cost: '400.000000', unit_cost_currency: 'EGP', unit_cost_fx_rate_at_time: '1.000000', unit_cost_base: '400.000000', margin_percent: '25.000' },
        { id: 'l2', line_no: 2, supplier_quotation_item_id: 'sqi2', product_name: null, quantity: '1.000', unit_price: '300.000000', line_total: '300.000000', unit_cost: '250.000000', unit_cost_currency: 'EGP', unit_cost_fx_rate_at_time: '1.000000', unit_cost_base: '250.000000', margin_percent: null },
    ],
    additional_items: [{ id: 'a1', line_no: 1, description: 'Delivery', amount: '100.000000' }],
    created_by: 'u1', updated_by: 'u1',
    // The server's `entry` for a cost viewer, a legacy Draft included (contract §4/§8): the lines as typed, margin = default + line extra.
    pricing_mode: 'legacy',
    entry: {
        pricing_mode: 'tax_included',
        lines: [
            { supplier_id: 's1', supplier_name: 'Nile Pumps Co', catalog_item_id: 'ci1', product_name: 'Split unit 1.5HP', unit_code: 'pcs', entered_unit_price: '400.000000', tax_percent: '14.000', margin_percent: '25.000', quantity: '2.000' },
        ],
    },
};

/**
 * QS.2a — `GET /quotations/{id}`'s `entry` (contract §4), what the builder loads
 * into its table: D-156's E1 (entered 114 at 14 % tax, 20 % margin, quantity 3)
 * and a 0 % line (owner, 2026-10-22 answer 5), as the server stores them.
 */
const ENTRY_LINES = [
    { supplier_id: 's1', supplier_name: 'Nile Pumps Co', catalog_item_id: 'ci1', product_name: 'Pump 5HP', unit_code: 'pcs', entered_unit_price: '114.000000', tax_percent: '14.000', margin_percent: '20.000', quantity: '3.0000' },
    { supplier_id: 's2', supplier_name: 'Delta Valves', catalog_item_id: 'ci2', product_name: 'Installation', unit_code: 'm', entered_unit_price: '500.000000', tax_percent: '0.000', margin_percent: '10.000', quantity: '1.0000' },
];

/** A Draft saved under the new pricing (`D-165` `tax_included`): no quotation margin, no quotation tax rate. */
const PRICED = {
    ...QUOTATION,
    pricing_mode: 'tax_included', default_margin: null, tax_percent: null, tax_percents: [],
    final_total: '914.360000', subtotal: '910.000000', additional_total: '50.000000', discount_amount: '91.000000', tax_base: '819.000000', tax_amount: '45.360000',
    net_amount: '869.000000', total_before_round: '914.360000', rounding_diff: '0.000000', rounding_enabled: false,
    items: [
        { id: 'l1', line_no: 1, supplier_quotation_item_id: null, catalog_item_id: 'ci1', product_name: 'Pump 5HP', supplier_id: 's1', supplier_name: 'Nile Pumps Co', unit_code: 'pcs', entered_unit_price: '114.000000', tax_percent: '14.000', quantity: '3.0000', unit_price: '120.000000', line_total: '360.000000', unit_cost: '100.000000', unit_cost_currency: 'EGP', unit_cost_fx_rate_at_time: '1.000000', unit_cost_base: '100.000000', margin_percent: '20.000' },
        { id: 'l2', line_no: 2, supplier_quotation_item_id: null, catalog_item_id: 'ci2', product_name: 'Installation', supplier_id: 's2', supplier_name: 'Delta Valves', unit_code: 'm', entered_unit_price: '500.000000', tax_percent: '0.000', quantity: '1.0000', unit_price: '550.000000', line_total: '550.000000', unit_cost: '500.000000', unit_cost_currency: 'EGP', unit_cost_fx_rate_at_time: '1.000000', unit_cost_base: '500.000000', margin_percent: '10.000' },
    ],
    additional_items: [{ id: 'a1', line_no: 1, description: 'Delivery', amount: '50.000000' }],
    entry: { pricing_mode: 'tax_included', lines: ENTRY_LINES },
};

/** What a save sends for `ENTRY_LINES` — the stored strings without their trailing zeros (U-5), nothing priced (`D-67`). */
const SENT_LINES = [
    { supplier_id: 's1', catalog_item_id: 'ci1', unit_code: 'pcs', entered_unit_price: '114', tax_percent: '14', margin_percent: '20', quantity: '3' },
    { supplier_id: 's2', catalog_item_id: 'ci2', unit_code: 'm', entered_unit_price: '500', tax_percent: '0', margin_percent: '10', quantity: '1' },
];

/**
 * `D-165`'s E4 (contract §3): a taxed legacy Draft — default margin 10, line extra 5,
 * cost 100, quotation tax 14 %. Its `entry` is the server's conversion: `tax_included`,
 * entered 100 × 1.14 = 114, margin 10 + 5 = 15.
 */
const LEGACY = {
    ...QUOTATION,
    pricing_mode: 'legacy', default_margin: '10.000', tax_percent: '14.000', tax_percents: ['14.000'],
    items: [
        { id: 'l1', line_no: 1, supplier_quotation_item_id: 'sqi1', catalog_item_id: 'ci1', product_name: 'Pump 5HP', supplier_id: 's1', supplier_name: 'Nile Pumps Co', unit_code: 'pcs', entered_unit_price: '100.000000', tax_percent: '14.000', quantity: '3.0000', unit_price: '115.000000', line_total: '345.000000', unit_cost: '100.000000', unit_cost_currency: 'EGP', unit_cost_fx_rate_at_time: '1.000000', unit_cost_base: '100.000000', margin_percent: '5.000' },
    ],
    additional_items: [{ id: 'a1', line_no: 1, description: 'Delivery', amount: '50.000000' }],
    entry: { pricing_mode: 'tax_included', lines: [{ ...ENTRY_LINES[0], margin_percent: '15.000' }] },
};

/** `D-157`'s toggle off — E3: the typed price as-is, no tax at all, so no line tax either. */
const EXEMPT = {
    ...PRICED,
    pricing_mode: 'tax_exempt', tax_amount: null,
    entry: { pricing_mode: 'tax_exempt', lines: [{ ...ENTRY_LINES[0], entered_unit_price: '100.000000', tax_percent: null }] },
};

const APPROVER: AuthenticatedUser = {
    id: 'u7',
    name: 'Test Manager',
    email: 'manager@example.test',
    role: { id: 'r7', slug: 'manager', name: 'Manager' },
    permissions: ['quotation.approve.all', 'quotation.view.all', 'quotation.edit.all', 'deal.view.all', 'supplier_quotation.view.all', 'customer.view.all', 'catalog.view.all'],
    is_active: true,
    unconditional_access: false,
};

const NEWER = { ...QUOTATION, version: 1, etag: '"v2"', final_total: '2000.000000', updated_at: '2026-09-14T10:00:00+00:00' };

const USER: AuthenticatedUser = {
    id: 'u1',
    name: 'Test Sales',
    email: 'sales@example.test',
    role: { id: 'r1', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['quotation.create.own', 'quotation.edit.own', 'quotation.view.own', 'deal.view.own', 'supplier_quotation.view.all', 'supplier_quotation.create.all', 'customer.view.all', 'catalog.view.all'],
    is_active: true,
    unconditional_access: false,
};

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'r1', ...extra } };
}

function refusal(status: number, code: string, details: unknown[] = []): Response {
    return json(status, { error: { code, message: `refused: ${code}`, details }, meta: { request_id: 'r1' } });
}

function respond(options: {
    dealStatus?: number;
    /** Each call to `POST /quotations`, in order; the last one repeats. */
    saves?: Response[];
    /** Each `GET /quotations/q1`, in order; the last one repeats. */
    quotations?: unknown[];
    /** Each `PATCH /quotations/q1` and `PATCH /quotations/q1/edit-and-approve`, in order; the last one repeats. */
    updates?: Response[];
    /** `GET /user-term-suggestions?field=` per field (Point 6.8); an unlisted field is the 500 below. */
    suggestions?: Partial<Record<'payment_terms' | 'warranty' | 'delivery_terms', string[]>>;
    /** `GET /currencies` (D-80); anything but 200 is the refusal the fallback input answers. */
    currenciesStatus?: number;
    /** `GET /managed-lists/delivery_terms` (F-32); none, or anything but 200, is what the plain text area answers. */
    terms?: typeof TERMS;
    termsStatus?: number;
    /** `GET /supplier-quotations/sq1`. */
    offer?: unknown;
} = {}): ReturnType<typeof vi.fn> {
    const { offer = SQ_DETAIL, dealStatus = 200, saves = [json(201, envelope(CREATED))], quotations = [QUOTATION], updates = [json(200, envelope(QUOTATION))], suggestions = {}, currenciesStatus = 200, terms = [], termsStatus = 200 } = options;
    let saved = 0;
    let read = 0;
    let updated = 0;

    return vi.fn(async (input: string, init?: RequestInit) => {
        const url = String(input);

        if (init?.method === 'POST' && url.endsWith('/quotations')) {
            const response = saves[Math.min(saved, saves.length - 1)] as Response;
            saved += 1;

            return response.clone();
        }

        if (init?.method === 'PATCH' && /\/quotations\/(q1|q9)(\/edit-and-approve)?$/.test(url)) {
            const response = updates[Math.min(updated, updates.length - 1)] as Response;
            updated += 1;

            return response.clone();
        }

        if (url.endsWith('/quotations/q1')) {
            const quotation = quotations[Math.min(read, quotations.length - 1)];
            read += 1;

            return json(200, envelope(quotation));
        }

        if (url.endsWith('/quotations/q9')) {
            return json(200, envelope({ ...QUOTATION, ...CREATED, etag: '"q9-v1"' }));
        }

        if (url.includes('/deals/')) {
            return dealStatus === 200 ? json(200, envelope(DEAL)) : refusal(dealStatus, dealStatus === 404 ? 'not_found' : 'forbidden');
        }

        if (url.includes('/customers/')) {
            return json(200, envelope(CUSTOMER));
        }

        if (url.includes('/currencies')) {
            return currenciesStatus === 200
                ? json(200, envelope({ currencies: [
                    { id: 'cur-egp', code: 'EGP', rounding_unit: '1', rounding_enabled: true, is_base: true },
                    { id: 'cur-usd', code: 'USD', rounding_unit: '0.01', rounding_enabled: false, is_base: false },
                ] }))
                : refusal(currenciesStatus, 'forbidden');
        }

        if (url.includes('/suppliers')) {
            return json(200, envelope(SUPPLIERS));
        }

        if (url.includes('/catalog-items')) {
            return json(200, envelope(CATALOG));
        }

        if (url.includes('/supplier-quotations/sq1')) {
            return json(200, envelope(offer));
        }

        if (url.includes('/supplier-quotations')) {
            return json(200, envelope(url.includes('filter%5Bdeal_id%5D=d1') ? [SQ_DEAL] : [SQ_DEAL, SQ_OTHER]));
        }

        if (url.includes('/managed-lists/delivery_terms')) {
            return termsStatus === 200 ? json(200, envelope(terms)) : refusal(termsStatus, 'forbidden');
        }

        if (url.includes('/managed-lists/units')) {
            return json(200, envelope(UNITS));
        }

        const field = /\/user-term-suggestions\?field=(\w+)$/.exec(url)?.[1] as keyof typeof suggestions | undefined;

        if (field !== undefined && suggestions[field] !== undefined) {
            return json(200, envelope((suggestions[field] ?? []).map((term) => ({ term }))));
        }

        return refusal(500, 'unexpected');
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

/** Mounted into the document only where focus is asserted — a detached element is never `document.activeElement`. */
const attached: Array<{ unmount: () => void }> = [];

async function render(fetchMock: ReturnType<typeof vi.fn>, path = '/quotations/new?deal=d1', locale: 'en' | 'ar' = 'en', user = USER, attach = false) {
    await signIn(user, fetchMock as unknown as typeof globalThis.fetch);
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const router = createAppRouter();

    await router.push(path);
    await router.isReady();

    // Through `<RouterView>`, not mounted directly: `onBeforeRouteLeave` binds
    // to the route record the view renders, and a direct mount has none.
    const wrapper = mount({ components: { QuotationBuilderView }, template: '<RouterView />' }, {
        global: { plugins: [i18n, router] },
        ...(attach ? { attachTo: document.body.appendChild(document.createElement('div')) } : {}),
    });

    if (attach) {
        attached.push(wrapper);
    }

    await flushPromises();

    return { wrapper, router };
}

/** The `POST /quotations`, `PATCH /quotations/{id}` and `PATCH …/edit-and-approve` calls the screen made — path, body and the two headers that matter. */
function saves(fetchMock: ReturnType<typeof vi.fn>): Array<{ method: string; path: string; body: unknown; idempotencyKey: string | null; ifMatch: string | null }> {
    return fetchMock.mock.calls
        .filter((call) => ['POST', 'PATCH'].includes(String((call[1] as RequestInit | undefined)?.method)) && /\/quotations(\/(q1|q9)(\/edit-and-approve)?)?$/.test(String(call[0])))
        .map((call) => ({
            method: String((call[1] as RequestInit).method),
            path: String(call[0]).replace(/^.*\/api\/v1/, ''),
            body: JSON.parse(String((call[1] as RequestInit).body)) as unknown,
            idempotencyKey: new Headers((call[1] as RequestInit).headers).get('Idempotency-Key'),
            ifMatch: new Headers((call[1] as RequestInit).headers).get('If-Match'),
        }));
}

function id(suffix: string): string {
    return `[data-testid="quotation-builder-${suffix}"]`;
}

async function fillHeader(wrapper: Awaited<ReturnType<typeof render>>['wrapper']): Promise<void> {
    await wrapper.find(id('currency')).setValue('EGP');
    await wrapper.find(id('discount_percent')).setValue('10');
}

type View = Awaited<ReturnType<typeof render>>['wrapper'];

/** QS.2a — one line as the entry row takes it. A field left out is not touched: supplier, tax and margin stay after Enter. */
interface Typed {
    supplier?: string;
    product?: string;
    /** A `units` code — the row's unit is a `<select>` over the managed list. */
    unit?: string;
    price?: string;
    tax?: string;
    margin?: string;
    quantity?: string;
}

/** `D-156`'s E1, as the preparer types it. */
const E1: Typed = { supplier: 'Nile Pumps Co', product: 'Pump 5HP', unit: 'pcs', price: '114', tax: '14', margin: '20', quantity: '3' };

/** A second product from the second supplier, at 0 % tax. */
const SECOND: Typed = { supplier: 'Delta Valves', product: 'Installation', unit: 'm', price: '500', tax: '0', margin: '10', quantity: '1' };

/** A server-searched combobox (`SearchCombobox`): open it, take the option that names `name`. */
async function choose(wrapper: View, box: string, name: string): Promise<void> {
    await wrapper.get(id(box)).trigger('focus');
    await flushPromises();

    const option = wrapper.findAll(id(`${box}-option`)).find((row) => row.text().includes(name));

    expect(option, `no «${name}» option in ${box}`).toBeDefined();
    await option?.trigger('mousedown');
}

async function typeEntry(wrapper: View, typed: Typed): Promise<void> {
    if (typed.supplier !== undefined) {
        await choose(wrapper, 'entry-supplier', typed.supplier);
    }

    if (typed.product !== undefined) {
        await choose(wrapper, 'entry-product', typed.product);
    }

    if (typed.unit !== undefined) {
        await wrapper.get(id('entry-unit')).setValue(typed.unit);
    }

    for (const [field, value] of [['price', typed.price], ['tax', typed.tax], ['margin', typed.margin], ['quantity', typed.quantity]] as const) {
        if (value !== undefined) {
            await wrapper.get(id(`entry-${field}`)).setValue(value);
        }
    }
}

/** Types a line and presses Enter in its quantity — the key that moves the row into the table. */
async function enterLine(wrapper: View, typed: Typed = E1): Promise<void> {
    await typeEntry(wrapper, typed);
    await wrapper.get(id('entry-quantity')).trigger('keydown', { key: 'Enter' });
    await flushPromises();
}

/** The table's rows, in order. */
function rows(wrapper: View) {
    return wrapper.findAll('[data-testid="quotation-builder-lines"] tbody tr');
}

/** One cell of the table: `line-0-price`, `line-1-unit`… */
function cell(wrapper: View, row: number, field: string): string {
    return wrapper.get(id(`line-${row}-${field}`)).text();
}

function value(wrapper: View, field: string): string {
    return (wrapper.get(id(field)).element as HTMLInputElement).value;
}

describe('the quotation builder (create)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    // ──────────────────────────────────────────────────────────── the opening

    // QS.2a (D-154): the entry row names a supplier, not a supplier offer.
    it('opens on the deal named by ?deal=, its customer by name, and the entry row’s supplier box lists the suppliers', async () => {
        const { wrapper } = await render(respond());

        expect(wrapper.find(id('deal')).text()).toBe('DL-2026-0001');
        expect(wrapper.find(id('customer')).text()).toBe('Acme Industrial');

        await wrapper.get(id('entry-supplier')).trigger('focus');
        await flushPromises();

        const options = wrapper.findAll(id('entry-supplier-option')).map((option) => option.text());

        expect(options.some((text) => text.includes('Nile Pumps Co'))).toBe(true);
        expect(options.some((text) => text.includes('Delta Valves'))).toBe(true);
    });

    it('says the deal could not be opened, never which of the two reasons', async () => {
        const { wrapper } = await render(respond({ dealStatus: 404 }));

        expect(wrapper.find(id('missing')).exists()).toBe(true);
        expect(wrapper.find(id('form')).exists()).toBe(false);
    });

    it('refuses the page without ?deal=', async () => {
        const { wrapper } = await render(respond(), '/quotations/new');

        expect(wrapper.find(id('missing')).exists()).toBe(true);
        expect(wrapper.find(id('form')).exists()).toBe(false);
    });

    // ──────────────────────────────────────────────────────────── the entry row (QS.2a)

    // The owner, 2026-10-22: Enter moves the typed row into the table; supplier, tax and margin stay
    // (answer 9). The table shows what was typed — no figure is computed here (D-67), QS.2b previews.
    it('moves the entry row into the table on Enter, showing what was typed and no computed figure', async () => {
        const { wrapper } = await render(respond());

        await enterLine(wrapper);

        expect(rows(wrapper)).toHaveLength(1);
        expect(cell(wrapper, 0, 'supplier')).toBe('Nile Pumps Co');
        expect(cell(wrapper, 0, 'product')).toBe('Pump 5HP');
        expect(cell(wrapper, 0, 'unit')).toBe('Piece');
        expect(cell(wrapper, 0, 'price')).toBe('114');
        expect(cell(wrapper, 0, 'tax')).toMatch(/^14\s*%?$/);
        expect(cell(wrapper, 0, 'margin')).toMatch(/^20\s*%?$/);
        expect(cell(wrapper, 0, 'quantity')).toBe('3');
        // E1's net 100, unit 120, line 360 and gross 136.80 are the server's, after the save.
        expect(rows(wrapper)[0]?.text()).not.toMatch(/\b(100\.00|120|360|136\.8)\b/);
    });

    it('keeps supplier, tax and margin in the entry row after Enter, and clears product, price, quantity and unit', async () => {
        const { wrapper } = await render(respond());

        await enterLine(wrapper);

        expect(value(wrapper, 'entry-supplier')).toBe('Nile Pumps Co');
        expect(value(wrapper, 'entry-tax')).toBe('14');
        expect(value(wrapper, 'entry-margin')).toBe('20');
        expect(value(wrapper, 'entry-product')).toBe('');
        expect(value(wrapper, 'entry-price')).toBe('');
        expect(value(wrapper, 'entry-quantity')).toBe('');
        expect(value(wrapper, 'entry-unit')).toBe('');

        // The next product of the same supplier needs only what changed.
        await enterLine(wrapper, { product: 'Installation', unit: 'm', price: '500', quantity: '1' });

        expect(rows(wrapper)).toHaveLength(2);
        expect(cell(wrapper, 1, 'supplier')).toBe('Nile Pumps Co');
        expect(cell(wrapper, 1, 'tax')).toMatch(/^14\s*%?$/);
        expect(cell(wrapper, 1, 'margin')).toMatch(/^20\s*%?$/);
    });

    it('moves the entry row into the table with the Add button too', async () => {
        const { wrapper } = await render(respond());

        await typeEntry(wrapper, E1);
        await wrapper.get(id('entry-add')).trigger('click');
        await flushPromises();

        expect(rows(wrapper)).toHaveLength(1);
        expect(cell(wrapper, 0, 'product')).toBe('Pump 5HP');
    });

    it('removes a table row with its trash button', async () => {
        const { wrapper } = await render(respond());

        await enterLine(wrapper);
        await enterLine(wrapper, SECOND);
        await wrapper.get(id('line-0-remove')).trigger('click');

        expect(rows(wrapper)).toHaveLength(1);
        expect(cell(wrapper, 0, 'product')).toBe('Installation');
    });

    it('sends a row back to the entry fields and takes it out of the table', async () => {
        const { wrapper } = await render(respond());

        await enterLine(wrapper);
        // The sticky fields moved on; the row's own values win.
        await wrapper.get(id('entry-margin')).setValue('30');
        await wrapper.get(id('line-0-send-back')).trigger('click');
        await flushPromises();

        expect(rows(wrapper)).toHaveLength(0);
        expect(value(wrapper, 'entry-supplier')).toBe('Nile Pumps Co');
        expect(value(wrapper, 'entry-product')).toBe('Pump 5HP');
        expect(value(wrapper, 'entry-unit')).toBe('pcs');
        expect(value(wrapper, 'entry-price')).toBe('114');
        expect(value(wrapper, 'entry-tax')).toBe('14');
        expect(value(wrapper, 'entry-margin')).toBe('20');
        expect(value(wrapper, 'entry-quantity')).toBe('3');
    });

    // A-39: the lines are a table that reads as cards below 640 px, as the detail's tables do.
    it('labels every table cell for the card form below 640 px (A-39)', async () => {
        const { wrapper } = await render(respond());

        await enterLine(wrapper);

        const table = wrapper.get(id('lines'));

        expect(table.element.tagName).toBe('TABLE');
        expect(table.classes()).toContain('card-table');
        expect(table.findAll('tbody td').every((td) => (td.attributes('data-label') ?? '') !== '')).toBe(true);
    });

    // ──────────────────────────────────────────────────────────── «شامل الضريبة» (D-157)

    it.each([
        { locale: 'en' as const, label: 'Prices include tax' },
        { locale: 'ar' as const, label: 'شامل الضريبة' },
    ])('opens a new quotation with the $locale «Prices include tax» toggle on (owner, 2026-10-22 answer 4)', async ({ locale, label }) => {
        const { wrapper } = await render(respond(), '/quotations/new?deal=d1', locale);
        const toggle = wrapper.get(id('tax-included'));

        expect((toggle.element as HTMLInputElement).type).toBe('checkbox');
        expect((toggle.element as HTMLInputElement).checked).toBe(true);
        expect(toggle.element.closest('label')?.textContent).toContain(label);
    });

    it('hides the tax field and the table’s tax column when the toggle is off', async () => {
        const { wrapper } = await render(respond());

        await enterLine(wrapper);
        expect(wrapper.find(id('line-0-tax')).exists()).toBe(true);

        await wrapper.get(id('tax-included')).setValue(false);

        expect(wrapper.find(id('entry-tax')).exists()).toBe(false);
        expect(wrapper.find(id('line-0-tax')).exists()).toBe(false);
        // The header loses its column with the cells: one heading per cell.
        expect(wrapper.get(id('lines')).findAll('thead th')).toHaveLength(rows(wrapper)[0]?.findAll('td').length ?? -1);
    });

    // ──────────────────────────────────────────────────────────── the payload

    it('sends §4’s payload — pricing_mode, each line’s seven facts as typed, and an Idempotency-Key — and none of the retired fields', async () => {
        const fetchMock = respond();
        const { wrapper, router } = await render(fetchMock);

        await wrapper.find(id('currency')).setValue('EGP');
        await wrapper.find(id('discount_percent')).setValue('10');
        await wrapper.find(id('quotation_date')).setValue('2026-09-14');
        await wrapper.find(id('valid_until')).setValue('2026-10-14');
        await wrapper.find(id('term-0-body')).setValue('50% advance');
        await wrapper.find(id('add-item')).trigger('click');
        await wrapper.find(id('item-0-description')).setValue('Delivery');
        await wrapper.find(id('item-0-amount')).setValue('50');
        await enterLine(wrapper);
        await enterLine(wrapper, SECOND);

        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const [save] = saves(fetchMock);

        // toEqual: an extra key — default_margin, tax_percents, supplier_quotation_item_id — fails it.
        expect(save?.body).toEqual({
            deal_id: 'd1',
            customer_id: 'c1',
            currency: 'EGP',
            pricing_mode: 'tax_included',
            discount_percent: '10',
            quotation_date: '2026-09-14',
            valid_until: '2026-10-14',
            terms: [
                { key: 'payment_terms', title: null, body: '50% advance' },
                { key: 'warranty', title: null, body: null },
                { key: 'delivery_terms', title: null, body: null },
            ],
            lines: SENT_LINES,
            additional_items: [{ description: 'Delivery', amount: '50' }],
        });
        expect(save?.idempotencyKey).toMatch(/^[0-9a-f-]{36}$/);
        expect(router.currentRoute.value.path).toBe('/quotations/q9');
    });

    // D-157: off is no tax at all — the typed price is used as-is, and a line names no rate.
    it('sends pricing_mode tax_exempt and no line tax_percent when the toggle is off', async () => {
        const fetchMock = respond();
        const { wrapper } = await render(fetchMock);
        // The tax field is gone with the toggle, so nothing is typed into it.
        const { tax: _gone, ...untaxed } = E1;

        await wrapper.find(id('currency')).setValue('EGP');
        await wrapper.get(id('tax-included')).setValue(false);
        await enterLine(wrapper, untaxed);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const body = saves(fetchMock)[0]?.body as { pricing_mode: string; lines: Array<Record<string, unknown>> };

        expect(body.pricing_mode).toBe('tax_exempt');
        expect(body.lines).toEqual([{ supplier_id: 's1', catalog_item_id: 'ci1', unit_code: 'pcs', entered_unit_price: '114', margin_percent: '20', quantity: '3' }]);
        expect(body).not.toHaveProperty('tax_percents');
        expect(body).not.toHaveProperty('tax_percent');
    });

    // D-154 (no offer picker, no offer form), D-156 (no quotation margin), D-157 (no quotation tax rates).
    it('has no default margin, no tax-rate fields, no supplier-offer picker and no new-supplier-quotation button, and reads no offer', async () => {
        const fetchMock = respond();
        const { wrapper } = await render(fetchMock);

        expect(wrapper.find(id('form')).exists()).toBe(true);
        expect(wrapper.find(id('default_margin')).exists()).toBe(false);
        expect(wrapper.find(id('tax-0')).exists()).toBe(false);
        expect(wrapper.find(id('add-tax')).exists()).toBe(false);
        expect(wrapper.find(id('add-supplier')).exists()).toBe(false);
        expect(wrapper.find(id('new-supplier-quotation')).exists()).toBe(false);
        expect(wrapper.findComponent({ name: 'SupplierQuotationFormModal' }).exists()).toBe(false);
        expect(fetchMock.mock.calls.some((call) => String(call[0]).includes('/supplier-quotations'))).toBe(false);
    });

    // D-159: the note sits with the additional items, and they sit above the lines table.
    it('puts the additional items above the lines table, with the note that they carry no margin and no tax', async () => {
        const arabic = await render(respond(), '/quotations/new?deal=d1', 'ar');

        await enterLine(arabic.wrapper);

        const table = arabic.wrapper.get(id('lines')).element;

        expect(arabic.wrapper.get(id('items-note')).text()).toBe('لا يُضاف على البند الإضافي ربح ولا ضريبة (مثل التوصيل والتركيب)');

        for (const before of ['items-note', 'add-item']) {
            expect(arabic.wrapper.get(id(before)).element.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_FOLLOWING, before).toBeTruthy();
        }

        const english = await render(respond());

        expect(english.wrapper.get(id('items-note')).text()).toMatch(/margin/i);
        expect(english.wrapper.get(id('items-note')).text()).toMatch(/tax/i);
    });

    // ──────────────────────────────────────────────────────────── a refused line

    // OpenAPI §5: a 422 names `lines.N.<field>`, and N is the table's row N.
    it.each(['supplier_id', 'catalog_item_id', 'unit_code', 'entered_unit_price', 'tax_percent', 'margin_percent', 'quantity'])(
        'lands a 422 on lines.1.%s on the table’s second row, not the first',
        async (field) => {
            const fetchMock = respond({ saves: [refusal(422, 'validation_failed', [{ field: `lines.1.${field}`, code: 'invalid', message: 'Row two is wrong.' }])] });
            const { wrapper, router } = await render(fetchMock);

            await wrapper.find(id('currency')).setValue('EGP');
            await enterLine(wrapper);
            await enterLine(wrapper, SECOND);
            await wrapper.find(id('form')).trigger('submit');
            await flushPromises();

            expect(wrapper.get(id('line-1-error')).text()).toBe('Row two is wrong.');
            expect(wrapper.find(id('line-0-error')).exists()).toBe(false);
            expect(router.currentRoute.value.path).toBe('/quotations/new');
        },
    );

    it('reuses the Idempotency-Key on a retry (OpenAPI §9.1)', async () => {
        const fetchMock = respond({ saves: [refusal(500, 'unexpected'), json(201, envelope(CREATED))] });
        const { wrapper, router } = await render(fetchMock);

        await fillHeader(wrapper);
        await enterLine(wrapper);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(wrapper.find(id('form-error')).exists()).toBe(true);
        expect(router.currentRoute.value.path).toBe('/quotations/new');

        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const sent = saves(fetchMock);

        expect(sent).toHaveLength(2);
        expect(sent[1]?.idempotencyKey).toBe(sent[0]?.idempotencyKey);
        expect(router.currentRoute.value.path).toBe('/quotations/q9');
    });

    /**
     * The server keeps every 4xx as the key's final answer (§9.1, "final
     * status"), so a corrected form is a new command: same key + new body would
     * be `409 idempotency_conflict` for as long as the page lives. Observed
     * 2026-09-15 on DL-2026-0003: a 422 on `currency`, then eight 409s.
     */
    it('mints a new Idempotency-Key after a 4xx answer, so the corrected form is not a 409', async () => {
        const fetchMock = respond({ saves: [refusal(422, 'validation_failed', [{ field: 'currency', code: 'required', message: 'required' }]), json(201, envelope(CREATED))] });
        const { wrapper, router } = await render(fetchMock);

        await enterLine(wrapper);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        await fillHeader(wrapper);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const sent = saves(fetchMock);

        expect(sent).toHaveLength(2);
        expect(sent[1]?.idempotencyKey).not.toBe(sent[0]?.idempotencyKey);
        expect(router.currentRoute.value.path).toBe('/quotations/q9');
    });

    // ──────────────────────────────────────────────────────────── refusals

    it('lands a validation sentence on the header field it names', async () => {
        const fetchMock = respond({
            saves: [refusal(422, 'validation_failed', [{ field: 'discount_percent', code: 'lt', message: 'The discount must be below 100.' }])],
        });
        const { wrapper } = await render(fetchMock);

        await fillHeader(wrapper);
        await wrapper.find(id('discount_percent')).setValue('100');
        await enterLine(wrapper);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(wrapper.find(id('discount_percent-error')).text()).toBe('The discount must be below 100.');
    });

    // ──────────────────────────────────────────────────────────── permission

    it('draws a 403 on the deal as a page refusal', async () => {
        const { wrapper } = await render(respond({ dealStatus: 403 }));

        expect(wrapper.find('[data-testid="permission-denied-state"]').exists()).toBe(true);
    });
});

/**
 * Point 6.7 — the same form on `/quotations/:id/edit`, loaded from the
 * detail. `PATCH` carries `If-Match` (`API-12`) and both lists always
 * (Point 3.6: an edit replaces every editable field); a 409 is a banner with
 * the newer version's total and a reload, never a silent retry; a quotation
 * that is no longer a Draft goes back to its page (`quotation_not_draft`).
 */
describe('the quotation builder — term suggestions (Point 6.8)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('offers the caller’s recent terms under each ready term, newest first, and a click copies one in', async () => {
        const { wrapper } = await render(respond({ suggestions: { warranty: ['Two years', 'One year'], payment_terms: ['50% advance'] } }));

        expect(wrapper.findAll(id('term-1-suggestion')).map((chip) => chip.text())).toEqual(['Two years', 'One year']);
        expect(wrapper.findAll(id('term-0-suggestion')).map((chip) => chip.text())).toEqual(['50% advance']);
        expect(wrapper.find(id('term-2-suggestions')).exists()).toBe(false);

        await wrapper.findAll(id('term-1-suggestion'))[1]?.trigger('click');

        expect((wrapper.find(id('term-1-body')).element as HTMLTextAreaElement).value).toBe('One year');
    });

    it('asks for the three fields on an edit too, and a refused lookup leaves the form usable', async () => {
        const fetchMock = respond({ suggestions: { delivery_terms: ['Ex works'] } });
        const { wrapper } = await render(fetchMock, '/quotations/q1/edit');

        const asked = fetchMock.mock.calls.map((call) => String(call[0])).filter((url) => url.includes('/user-term-suggestions'));

        expect(asked.map((url) => url.split('field=')[1])).toEqual(['payment_terms', 'warranty', 'delivery_terms']);
        expect(wrapper.findAll(id('term-1-suggestion')).map((chip) => chip.text())).toEqual(['Ex works']);
        expect(wrapper.find(id('term-0-suggestions')).exists()).toBe(false);
        expect(wrapper.find(id('save')).exists()).toBe(true);
    });

    it('keeps a ready term’s chips when it is renamed or moves up, and gives an added term none (D-103 ruling 2)', async () => {
        const { wrapper } = await render(respond({ suggestions: { warranty: ['One year'], payment_terms: ['50% advance'] } }));

        await wrapper.find(id('term-1-title')).setValue('Guarantee');
        await wrapper.find(id('term-0-remove')).trigger('click');

        expect((wrapper.find(id('term-0-title')).element as HTMLInputElement).value).toBe('Guarantee');
        expect(wrapper.findAll(id('term-0-suggestion')).map((chip) => chip.text())).toEqual(['One year']);

        await wrapper.find(id('add-term')).trigger('click');

        expect(wrapper.find(id('term-2-body')).exists()).toBe(true);
        expect(wrapper.find(id('term-2-suggestions')).exists()).toBe(false);
    });
});

describe('the quotation builder (edit)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    const EDIT = '/quotations/q1/edit';

    // QS.2a: the table is filled from the payload's `entry` (contract §4), never from `items`.
    it('loads the header, the entry lines into the table, and the additional items from the detail', async () => {
        const { wrapper } = await render(respond({ quotations: [PRICED] }), EDIT);

        expect(wrapper.find(id('deal')).text()).toBe('DL-2026-0001');
        expect(wrapper.find(id('customer')).text()).toBe('Acme Industrial');
        expect((wrapper.find(id('currency')).element as HTMLInputElement).value).toBe('EGP');
        expect((wrapper.find(id('discount_percent')).element as HTMLInputElement).value).toBe('10');
        expect((wrapper.find(id('quotation_date')).element as HTMLInputElement).value).toBe('2026-09-13');
        expect((wrapper.get(id('tax-included')).element as HTMLInputElement).checked).toBe(true);
        // D-103: the quotation's own terms in their order — the deleted warranty stays deleted.
        expect((wrapper.find(id('term-0-body')).element as HTMLTextAreaElement).value).toBe('50% advance');
        expect((wrapper.find(id('term-1-body')).element as HTMLTextAreaElement).value).toBe('Ex works');
        expect(wrapper.find(id('term-2-body')).exists()).toBe(false);
        expect(rows(wrapper)).toHaveLength(2);
        expect(cell(wrapper, 0, 'supplier')).toBe('Nile Pumps Co');
        expect(cell(wrapper, 0, 'product')).toBe('Pump 5HP');
        expect(cell(wrapper, 0, 'unit')).toBe('Piece');
        // U-5: a stored figure is offered without its trailing zeros — trimmed, never rounded.
        expect(cell(wrapper, 0, 'price')).toBe('114');
        expect(cell(wrapper, 0, 'tax')).toMatch(/^14\s*%?$/);
        expect(cell(wrapper, 0, 'margin')).toMatch(/^20\s*%?$/);
        expect(cell(wrapper, 0, 'quantity')).toBe('3');
        expect(cell(wrapper, 1, 'supplier')).toBe('Delta Valves');
        expect(cell(wrapper, 1, 'unit')).toBe('Metre');
        expect(cell(wrapper, 1, 'tax')).toMatch(/^0\s*%?$/);
        expect((wrapper.find(id('item-0-description')).element as HTMLInputElement).value).toBe('Delivery');
        expect((wrapper.find(id('item-0-amount')).element as HTMLInputElement).value).toBe('50');
    });

    // D-165 with contract §3's E4: a legacy Draft opens converted and saves through the new payload.
    it('loads a legacy Draft’s converted entry with the toggle on, and saves it with no default margin (D-165, E4)', async () => {
        const fetchMock = respond({ quotations: [LEGACY] });
        const { wrapper } = await render(fetchMock, EDIT);

        expect((wrapper.get(id('tax-included')).element as HTMLInputElement).checked).toBe(true);
        expect(cell(wrapper, 0, 'price')).toBe('114');
        expect(cell(wrapper, 0, 'tax')).toMatch(/^14\s*%?$/);
        expect(cell(wrapper, 0, 'margin')).toMatch(/^15\s*%?$/);

        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const body = saves(fetchMock)[0]?.body as Record<string, unknown>;

        expect(body.pricing_mode).toBe('tax_included');
        expect(body.lines).toEqual([{ ...SENT_LINES[0], margin_percent: '15' }]);

        for (const retired of ['default_margin', 'tax_percents', 'tax_percent']) {
            expect(body).not.toHaveProperty(retired);
        }
    });

    it('loads a tax-exempt Draft with the toggle off and no tax column, and keeps it exempt on save (D-157, E3)', async () => {
        const fetchMock = respond({ quotations: [EXEMPT] });
        const { wrapper } = await render(fetchMock, EDIT);

        expect((wrapper.get(id('tax-included')).element as HTMLInputElement).checked).toBe(false);
        expect(wrapper.find(id('entry-tax')).exists()).toBe(false);
        expect(wrapper.find(id('line-0-tax')).exists()).toBe(false);
        expect(cell(wrapper, 0, 'price')).toBe('100');

        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(saves(fetchMock)[0]?.body).toMatchObject({
            pricing_mode: 'tax_exempt',
            lines: [{ supplier_id: 's1', catalog_item_id: 'ci1', unit_code: 'pcs', entered_unit_price: '100', margin_percent: '20', quantity: '3' }],
        });
        expect((saves(fetchMock)[0]?.body as { lines: object[] }).lines[0]).not.toHaveProperty('tax_percent');
    });

    it('pre-fills a quantity and an amount without trailing zeros, every significant digit kept (U-5, D-99)', async () => {
        // U-5: a trim, never a rounding — `0.125000` stays `0.125` (displayDecimals would
        // print `0.13`), and a re-save sends the same number.
        const fractional = {
            ...PRICED,
            entry: { pricing_mode: 'tax_included', lines: [{ ...ENTRY_LINES[0], quantity: '1.2500' }] },
            additional_items: [{ ...PRICED.additional_items[0], amount: '0.125000' }],
        };
        const fetchMock = respond({ quotations: [fractional] });
        const { wrapper } = await render(fetchMock, EDIT);

        expect(cell(wrapper, 0, 'quantity')).toBe('1.25');
        expect((wrapper.find(id('item-0-amount')).element as HTMLInputElement).value).toBe('0.125');

        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(saves(fetchMock)[0]?.body).toMatchObject({
            lines: [{ ...SENT_LINES[0], quantity: '1.25' }],
            additional_items: [{ description: 'Delivery', amount: '0.125' }],
        });
    });

    it('sends back to the quotation’s page when it is no longer a Draft', async () => {
        const { router } = await render(respond({ quotations: [{ ...QUOTATION, status: 'pending' }] }), EDIT);

        expect(router.currentRoute.value.path).toBe('/quotations/q1');
    });

    it('sends PATCH with If-Match, every editable field, the kept line and the new one, no deal or customer', async () => {
        const fetchMock = respond({ quotations: [PRICED] });
        const { wrapper, router } = await render(fetchMock, EDIT);

        await wrapper.get(id('line-1-remove')).trigger('click');
        await enterLine(wrapper, { ...SECOND, price: '250', tax: '14', margin: '5', quantity: '4' });
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const [save] = saves(fetchMock);

        expect(save?.method).toBe('PATCH');
        expect(save?.ifMatch).toBe('"v1"');
        expect(save?.idempotencyKey).toBeNull();
        expect(save?.body).toEqual({
            currency: 'EGP',
            pricing_mode: 'tax_included',
            discount_percent: '10',
            quotation_date: '2026-09-13',
            valid_until: '2026-10-13',
            terms: [{ key: 'payment_terms', title: null, body: '50% advance' }, { key: 'delivery_terms', title: null, body: 'Ex works' }],
            lines: [
                SENT_LINES[0],
                { supplier_id: 's2', catalog_item_id: 'ci2', unit_code: 'm', entered_unit_price: '250', tax_percent: '14', margin_percent: '5', quantity: '4' },
            ],
            additional_items: [{ description: 'Delivery', amount: '50' }],
        });
        expect(router.currentRoute.value.path).toBe('/quotations/q1');
    });

    it('sends both lists even when emptied — an omitted list is a 422, not "keep them" (Point 3.6)', async () => {
        const fetchMock = respond({ quotations: [PRICED] });
        const { wrapper } = await render(fetchMock, EDIT);

        await wrapper.get(id('line-0-remove')).trigger('click');
        await wrapper.get(id('line-0-remove')).trigger('click');
        await wrapper.find(id('item-0-remove')).trigger('click');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(saves(fetchMock)[0]?.body).toMatchObject({ lines: [], additional_items: [] });
    });

    it('turns a 409 into a banner with the newer version’s total and a reload, never a retry (API-12)', async () => {
        const fetchMock = respond({
            quotations: [QUOTATION, NEWER],
            updates: [refusal(409, 'concurrency_conflict', [{ code: 'stale_version', message: 'stale' }])],
        });
        const { wrapper, router } = await render(fetchMock, EDIT);

        await wrapper.find(id('discount_percent')).setValue('15');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe(EDIT);
        expect(wrapper.find(id('conflict')).text()).toContain('2,000.00');
        expect(wrapper.find(id('conflict')).text()).not.toContain('2000.000000'); // D-110
        expect(saves(fetchMock)).toHaveLength(1);

        await wrapper.find(id('conflict-reload')).trigger('click');
        await flushPromises();

        expect(wrapper.find(id('conflict')).exists()).toBe(false);
        expect((wrapper.find(id('discount_percent')).element as HTMLInputElement).value).toBe('10');

        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(saves(fetchMock)[1]?.ifMatch).toBe('"v2"');
    });

    it('goes back to the quotation’s page on quotation_not_draft', async () => {
        const fetchMock = respond({ updates: [refusal(422, 'business_rule_blocked', [{ code: 'quotation_not_draft', message: 'not a draft' }])] });
        const { wrapper, router } = await render(fetchMock, EDIT);

        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/quotations/q1');
    });

    it('asks in the page before leaving with unsaved changes, and not otherwise (Design System §5.2, A-61)', async () => {
        // A-61: never `window.confirm` — it is neither translated nor RTL.
        const confirm = vi.fn().mockReturnValue(true);
        vi.stubGlobal('confirm', confirm);

        const { wrapper, router } = await render(respond(), EDIT);

        await router.push('/quotations/q1');
        expect(router.currentRoute.value.path).toBe('/quotations/q1');

        await router.push(EDIT);
        await flushPromises();
        await wrapper.find(id('discount_percent')).setValue('15');
        await router.push('/quotations/q1');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe(EDIT);
        expect(wrapper.find(id('unsaved')).attributes('role')).toBe('alertdialog');
        expect(wrapper.find(id('unsaved')).text()).toContain(en.quotations.builder.unsaved);

        await wrapper.find(id('keep-editing')).trigger('click');
        expect(wrapper.find(id('unsaved')).exists()).toBe(false);
        expect(router.currentRoute.value.path).toBe(EDIT);

        await router.push('/quotations/q1');
        await flushPromises();
        await wrapper.find(id('discard')).trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/quotations/q1');
        expect(confirm).not.toHaveBeenCalled();
    });
});

/**
 * Module 8, Point 3.2 — the same builder, opened from `/approvals` on a
 * `pending` quotation by an approver. Save is 1.3's
 * `PATCH /quotations/{id}/edit-and-approve` — 6.7's body and `If-Match`,
 * re-priced, audited and approved in one transaction on the server (§6.4);
 * the page only changes where it sends.
 */
describe('the quotation builder (edit and approve, Module 8 · 3.2)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    const APPROVE = '/quotations/q1/edit-and-approve';
    const PENDING = { ...PRICED, status: 'pending' };

    it('keeps a pending quotation on the form instead of sending it back to its page', async () => {
        const { wrapper, router } = await render(respond({ quotations: [PENDING] }), APPROVE, 'en', APPROVER);

        expect(router.currentRoute.value.path).toBe(APPROVE);
        // QS.2a: the approver edits the same table the preparer typed.
        expect(cell(wrapper, 0, 'price')).toBe('114');
        expect(wrapper.find(id('save')).text()).toBe('Edit & approve');
    });

    it('still sends a quotation that is not pending back to its page', async () => {
        const { router } = await render(respond({ quotations: [{ ...QUOTATION, status: 'approved' }] }), APPROVE, 'en', APPROVER);

        expect(router.currentRoute.value.path).toBe('/quotations/q1');
    });

    it('saves to /edit-and-approve with If-Match and 6.7\'s body, then lands on the quotation\'s page', async () => {
        const fetchMock = respond({ quotations: [PENDING], updates: [json(200, envelope({ ...PENDING, status: 'approved' }))] });
        const { wrapper, router } = await render(fetchMock, APPROVE, 'en', APPROVER);

        await wrapper.find(id('discount_percent')).setValue('5');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const [save] = saves(fetchMock);

        expect(saves(fetchMock)).toHaveLength(1);
        expect(save?.method).toBe('PATCH');
        expect(save?.path).toBe('/quotations/q1/edit-and-approve');
        expect(save?.ifMatch).toBe('"v1"');
        expect(save?.idempotencyKey).toBeNull();
        expect(save?.body).toMatchObject({ pricing_mode: 'tax_included', discount_percent: '5', currency: 'EGP', lines: SENT_LINES, additional_items: [{ description: 'Delivery', amount: '50' }] });
        expect(save?.body).not.toHaveProperty('deal_id');
        expect(save?.body).not.toHaveProperty('default_margin');
        expect(router.currentRoute.value.path).toBe('/quotations/q1');
    });
});

/**
 * F-02 (2026-09-16): since D-80 the builder's roles may read `GET /currencies`,
 * so the currency is chosen from the list rather than typed — and typed again,
 * unchanged, when the list cannot be loaded, because a refused lookup leaves a
 * working form, not an empty select that can only produce a 422.
 */
describe('the builder\'s currency control', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('offers the currencies the server lists, by code, and sends the code', async () => {
        const fetchMock = respond();
        const { wrapper } = await render(fetchMock);

        const control = wrapper.find(id('currency'));

        expect(control.element.tagName).toBe('SELECT');
        expect(control.findAll('option').map((option) => option.text())).toEqual([en.quotations.builder.currencyNone, 'EGP', 'USD']);

        await control.setValue('USD');
        await enterLine(wrapper);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(saves(fetchMock)[0]?.body).toMatchObject({ currency: 'USD' });
    });

    it('falls back to the typed code when the list cannot be loaded', async () => {
        const fetchMock = respond({ currenciesStatus: 403 });
        const { wrapper } = await render(fetchMock);

        const control = wrapper.find(id('currency'));

        expect(control.element.tagName).toBe('INPUT');

        await control.setValue('egp');
        await enterLine(wrapper);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(saves(fetchMock)[0]?.body).toMatchObject({ currency: 'EGP' });
    });
});

/**
 * F-32 · 1.1 — the delivery field over the `delivery_terms` managed list
 * (`DB-05`). An editable combobox: focus opens the list, typing narrows it, a
 * pick fills the field — and text that matches nothing is still the value
 * (`§6.2`, `Design System §6.3`: free text with suggestions). With no entries,
 * or the list refused, the field stays the text area it was.
 */
describe('the builder\'s delivery-terms control', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
        // The combobox waits 300 ms after the last key before it narrows the list.
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    type Rendered = Awaited<ReturnType<typeof render>>['wrapper'];

    // D-103: the combobox sits on the delivery-keyed term — the third ready row of a new quotation.
    const field = (wrapper: Rendered, row = 2) => wrapper.find(id(`term-${row}-body`));
    const optionTexts = (wrapper: Rendered): string[] => wrapper.findAll(id('term-2-body-option')).map((option) => option.text());
    /** What a new quotation sends with only the delivery term filled. */
    const deliverySaved = (body: string) => ({ terms: [{ key: 'payment_terms' }, { key: 'warranty' }, { key: 'delivery_terms', title: null, body }] });

    async function open(wrapper: Rendered): Promise<void> {
        await field(wrapper).trigger('focus');
        await flushPromises();
    }

    async function typeInto(wrapper: Rendered, text: string): Promise<void> {
        await field(wrapper).setValue(text);
        vi.advanceTimersByTime(300);
        await flushPromises();
    }

    async function save(wrapper: Rendered): Promise<void> {
        await fillHeader(wrapper);
        await enterLine(wrapper);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();
    }

    // QS.2a: the entry row's unit comes from the `units` list, read beside the delivery terms.
    it('reads the delivery terms and the units once each, page 1, when the builder opens', async () => {
        const fetchMock = respond({ terms: TERMS });

        await render(fetchMock);

        const asked = fetchMock.mock.calls.map((call) => String(call[0]).replace(/^.*\/api\/v1/, '')).filter((path) => path.startsWith('/managed-lists/'));

        expect([...asked].sort()).toEqual(['/managed-lists/delivery_terms?page=1', '/managed-lists/units?page=1']);
    });

    it('makes the delivery field a combobox and leaves payment terms and warranty as text areas', async () => {
        const { wrapper } = await render(respond({ terms: TERMS }));

        expect(field(wrapper).attributes('role')).toBe('combobox');
        expect(wrapper.find(id('term-0-body')).element.tagName).toBe('TEXTAREA');
        expect(wrapper.find(id('term-1-body')).element.tagName).toBe('TEXTAREA');
    });

    it('keeps the combobox on the delivery term when it is renamed or moves up, and an added term is a text area', async () => {
        const { wrapper } = await render(respond({ terms: TERMS }));

        await wrapper.find(id('term-0-remove')).trigger('click');
        await wrapper.find(id('term-1-title')).setValue('Shipping');

        expect(field(wrapper, 1).attributes('role')).toBe('combobox');

        await wrapper.find(id('add-term')).trigger('click');

        expect(field(wrapper, 2).element.tagName).toBe('TEXTAREA');
    });

    it('opens on focus with one option per term, in the session’s language and the server’s order', async () => {
        const { wrapper } = await render(respond({ terms: TERMS }));

        await open(wrapper);

        expect(optionTexts(wrapper)).toEqual([
            'Within 1–2 weeks from receipt of the purchase order',
            'Within 4–6 weeks from receipt of the purchase order',
            'Delivered to the customer’s site',
        ]);
    });

    it('shows the Arabic labels in an Arabic session', async () => {
        const { wrapper } = await render(respond({ terms: TERMS }), '/quotations/new?deal=d1', 'ar');

        await open(wrapper);

        expect(optionTexts(wrapper)).toEqual([
            'خلال أسبوع إلى أسبوعين من استلام أمر الشراء',
            'خلال 4 إلى 6 أسابيع من استلام أمر الشراء',
            'التسليم في موقع العميل',
        ]);
    });

    it('narrows the list as you type, ignoring case', async () => {
        const { wrapper } = await render(respond({ terms: TERMS }));

        await typeInto(wrapper, 'WEEKS');

        expect(optionTexts(wrapper)).toEqual([
            'Within 1–2 weeks from receipt of the purchase order',
            'Within 4–6 weeks from receipt of the purchase order',
        ]);
    });

    it('narrows the Arabic labels by Arabic text', async () => {
        const { wrapper } = await render(respond({ terms: TERMS }), '/quotations/new?deal=d1', 'ar');

        await typeInto(wrapper, 'أسابيع');

        expect(optionTexts(wrapper)).toEqual(['خلال 4 إلى 6 أسابيع من استلام أمر الشراء']);
    });

    it.each([
        { locale: 'en' as const, messages: en },
        { locale: 'ar' as const, messages: ar },
    ])('speaks the $locale session’s language in its placeholder and its no-match sentence', async ({ locale, messages }) => {
        const { wrapper } = await render(respond({ terms: TERMS }), '/quotations/new?deal=d1', locale);

        expect(field(wrapper).attributes('placeholder')).toBe(messages.quotations.builder.deliveryTermPlaceholder);

        await typeInto(wrapper, 'zzz');

        expect(wrapper.find(id('term-2-body-state')).text()).toBe(messages.quotations.builder.deliveryTermNoMatch.replace('{query}', 'zzz'));
    });

    it('fills the field with the picked term, and the save sends that text', async () => {
        const fetchMock = respond({ terms: TERMS });
        const { wrapper } = await render(fetchMock);

        await open(wrapper);
        await wrapper.findAll(id('term-2-body-option'))[2]?.trigger('mousedown');

        expect((field(wrapper).element as HTMLInputElement).value).toBe('Delivered to the customer’s site');

        await save(wrapper);

        expect(saves(fetchMock)[0]?.body).toMatchObject(deliverySaved('Delivered to the customer’s site'));
    });

    it('marks the term the field already holds as the selected option when the list opens again', async () => {
        const { wrapper } = await render(respond({ terms: TERMS }));

        await open(wrapper);
        await wrapper.findAll(id('term-2-body-option'))[2]?.trigger('mousedown');
        await open(wrapper);

        expect(wrapper.findAll(id('term-2-body-option')).map((option) => option.attributes('aria-selected'))).toEqual(['false', 'false', 'true']);
    });

    it('picks from the keyboard: ArrowDown to the first term, Enter to take it', async () => {
        const { wrapper } = await render(respond({ terms: TERMS }));

        await open(wrapper);
        await field(wrapper).trigger('keydown', { key: 'ArrowDown' });
        await field(wrapper).trigger('keydown', { key: 'Enter' });

        expect((field(wrapper).element as HTMLInputElement).value).toBe('Within 1–2 weeks from receipt of the purchase order');
    });

    // The owner, 2026-10-01: Enter only takes a highlighted term. A one-line field inside the
    // builder's <form> otherwise submits it (implicit submission), where the old text area took a line.
    it.each([
        { why: 'the typed text matches no term', text: 'Within ten days' },
        { why: 'terms are listed but none is highlighted', text: 'Within' },
    ])('blocks the form’s submit on Enter when $why', async ({ text }) => {
        const { wrapper } = await render(respond({ terms: TERMS }));

        await typeInto(wrapper, text);

        const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });

        field(wrapper).element.dispatchEvent(enter);

        expect(enter.defaultPrevented).toBe(true);
        expect((field(wrapper).element as HTMLInputElement).value).toBe(text);

        // Enter alone: any other key still types.
        const letter = new KeyboardEvent('keydown', { key: 'a', bubbles: true, cancelable: true });

        field(wrapper).element.dispatchEvent(letter);

        expect(letter.defaultPrevented).toBe(false);
    });

    it('keeps text that matches no term, says so, and saves it as typed', async () => {
        const fetchMock = respond({ terms: TERMS });
        const { wrapper } = await render(fetchMock);

        await typeInto(wrapper, 'Within ten days');

        expect(optionTexts(wrapper)).toEqual([]);
        expect(wrapper.find(id('term-2-body-state')).exists()).toBe(true);
        expect(wrapper.find(id('term-2-body-state')).text()).toContain('Within ten days');

        await field(wrapper).trigger('focusout');

        expect((field(wrapper).element as HTMLInputElement).value).toBe('Within ten days');

        await save(wrapper);

        expect(saves(fetchMock)[0]?.body).toMatchObject(deliverySaved('Within ten days'));
    });

    it('still copies a recent term into the field when its chip is clicked', async () => {
        const { wrapper } = await render(respond({ terms: TERMS, suggestions: { delivery_terms: ['Ex works'] } }));

        expect(field(wrapper).attributes('role')).toBe('combobox');

        await wrapper.findAll(id('term-2-suggestion'))[0]?.trigger('click');

        expect((field(wrapper).element as HTMLInputElement).value).toBe('Ex works');
    });

    it('shows an opened quotation’s stored term in the field', async () => {
        const { wrapper } = await render(respond({ terms: TERMS }), '/quotations/q1/edit');

        expect(field(wrapper, 1).attributes('role')).toBe('combobox');
        expect((field(wrapper, 1).element as HTMLInputElement).value).toBe('Ex works');
    });

    // A one-line box strips line breaks and glues the words (Chrome: "a\nb" → "ab"); the PDF
    // template sets no line-break style, so it prints a break as a space.
    it('shows an old term typed on several lines on one line, each break as a space', async () => {
        const { wrapper } = await render(respond({ terms: TERMS, quotations: [{ ...QUOTATION, terms: [{ key: 'delivery_terms', title: null, body: 'Ex works\r\nbefore noon' }] }] }), '/quotations/q1/edit');

        expect((field(wrapper, 0).element as HTMLInputElement).value).toBe('Ex works before noon');
    });

    // The owner, 2026-10-01 (F-24 · 1.5's option C): at 375 px the one-line field cut 6 of 8 dev
    // terms, so the whole term also wraps under it, hidden from a screen reader that reads the field.
    it.each([
        {
            how: 'picked',
            act: async (wrapper: Rendered) => {
                await open(wrapper);
                await wrapper.findAll(id('term-2-body-option'))[0]?.trigger('mousedown');
            },
            whole: 'Within 1–2 weeks from receipt of the purchase order',
        },
        { how: 'typed', act: (wrapper: Rendered) => typeInto(wrapper, 'Within ten days'), whole: 'Within ten days' },
    ])('wraps the whole $how term under the one-line field, hidden from screen readers', async ({ act, whole }) => {
        const { wrapper } = await render(respond({ terms: TERMS }));

        expect(wrapper.find(id('term-2-full')).exists()).toBe(false);

        await act(wrapper);

        const full = wrapper.find(id('term-2-full'));

        expect(full.exists()).toBe(true);
        expect(full.text()).toBe(whole);
        expect(full.attributes('aria-hidden')).toBe('true');
    });

    it('saves an old multi-line term exactly as stored until somebody edits it', async () => {
        const fetchMock = respond({ terms: TERMS, quotations: [{ ...QUOTATION, terms: [{ key: 'delivery_terms', title: null, body: 'Ex works\nbefore noon' }] }] });
        const { wrapper } = await render(fetchMock, '/quotations/q1/edit');

        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(saves(fetchMock)[0]?.body).toMatchObject({ terms: [{ key: 'delivery_terms', title: null, body: 'Ex works\nbefore noon' }] });
    });

    it.each([
        { why: 'has no entries', options: { terms: [] } },
        { why: 'is refused', options: { termsStatus: 403 } },
    ])('keeps the plain text area, still saving what is typed, when the list $why', async ({ options }) => {
        const fetchMock = respond(options);
        const { wrapper } = await render(fetchMock);

        expect(fetchMock.mock.calls.some((call) => String(call[0]).includes('/managed-lists/delivery_terms'))).toBe(true);
        expect(field(wrapper).element.tagName).toBe('TEXTAREA');

        await field(wrapper).setValue('Ex works');

        // A text area wraps by itself: nothing is repeated under it.
        expect(wrapper.find(id('term-2-full')).exists()).toBe(false);

        await save(wrapper);

        expect(saves(fetchMock)[0]?.body).toMatchObject(deliverySaved('Ex works'));
    });
});

/**
 * F-37 · 1.4 — `D-103`: the terms are a list built like the additional items.
 * A new quotation opens with three ready terms whose name starts empty, the
 * default name shown as the placeholder — the label the PDF prints for an
 * empty name; up to 15; every row renamable and removable; no checkbox.
 */
describe('the builder\'s terms list (F-37, D-103)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    const titles = '[data-testid^="quotation-builder-term-"][data-testid$="-title"]';

    it.each([
        { locale: 'en' as const, labels: ['Payment', 'Warranty', 'Delivery'] },
        { locale: 'ar' as const, labels: ['الدفع', 'الضمان', 'التسليم'] },
    ])('opens a new quotation on three ready, unnamed terms, the $locale default name as each placeholder', async ({ locale, labels }) => {
        const { wrapper } = await render(respond(), '/quotations/new?deal=d1', locale);

        const ready = [0, 1, 2].map((row) => wrapper.find(id(`term-${row}-title`)));

        expect(wrapper.findAll(titles)).toHaveLength(3);
        expect(ready.map((title) => (title.element as HTMLInputElement).value)).toEqual(['', '', '']);
        expect(ready.map((title) => title.attributes('placeholder'))).toEqual(labels);
        // Ruling 3: no checkbox — an empty body or a deleted row keeps a term off the PDF.
        expect(wrapper.find(id('show_delivery_terms')).exists()).toBe(false);
    });

    it('adds terms up to fifteen, then disables the button until one is removed', async () => {
        const { wrapper } = await render(respond());

        for (let index = 0; index < 12; index += 1) {
            await wrapper.find(id('add-term')).trigger('click');
        }

        expect(wrapper.findAll(titles)).toHaveLength(15);
        expect(wrapper.find(id('add-term')).attributes('disabled')).toBeDefined();

        await wrapper.find(id('add-term')).trigger('click');

        expect(wrapper.findAll(titles)).toHaveLength(15);

        await wrapper.find(id('term-13-title')).setValue('Kept');
        await wrapper.find(id('term-14-remove')).trigger('click');

        // The clicked row goes, not another one.
        expect((wrapper.find(id('term-13-title')).element as HTMLInputElement).value).toBe('Kept');
        expect(wrapper.find(id('add-term')).attributes('disabled')).toBeUndefined();
    });

    it('saves a renamed, a removed and an added term in row order, and none of the old fields', async () => {
        const fetchMock = respond();
        const { wrapper } = await render(fetchMock);

        await fillHeader(wrapper);
        await enterLine(wrapper);
        await wrapper.find(id('term-0-remove')).trigger('click');
        await wrapper.find(id('term-0-title')).setValue('Guarantee');
        await wrapper.find(id('term-0-body')).setValue('Two years');
        await wrapper.find(id('add-term')).trigger('click');
        await wrapper.find(id('term-2-title')).setValue('Spare parts');
        await wrapper.find(id('term-2-body')).setValue('Six months');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const body = saves(fetchMock)[0]?.body as Record<string, unknown>;

        expect(body.terms).toEqual([
            { key: 'warranty', title: 'Guarantee', body: 'Two years' },
            { key: 'delivery_terms', title: null, body: null },
            { key: null, title: 'Spare parts', body: 'Six months' },
        ]);

        for (const old of ['payment_terms', 'warranty', 'delivery_terms', 'show_delivery_terms']) {
            expect(body).not.toHaveProperty(old);
        }
    });

    it('refills a stored name and an added term on an edit, and sends them back as stored', async () => {
        const stored = [
            { key: 'warranty', title: 'Guarantee', body: 'Two years' },
            { key: null, title: 'Spare parts', body: 'Six months' },
        ];
        const fetchMock = respond({ quotations: [{ ...QUOTATION, terms: stored }] });
        const { wrapper } = await render(fetchMock, '/quotations/q1/edit');

        expect((wrapper.find(id('term-0-title')).element as HTMLInputElement).value).toBe('Guarantee');
        expect((wrapper.find(id('term-1-title')).element as HTMLInputElement).value).toBe('Spare parts');
        expect((wrapper.find(id('term-1-body')).element as HTMLTextAreaElement).value).toBe('Six months');

        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect((saves(fetchMock)[0]?.body as { terms: unknown }).terms).toEqual(stored);
    });

    it('opens a quotation with no terms on no rows, and the button still adds one', async () => {
        const { wrapper } = await render(respond({ quotations: [{ ...QUOTATION, terms: [] }] }), '/quotations/q1/edit');

        expect(wrapper.findAll(titles)).toHaveLength(0);

        await wrapper.find(id('add-term')).trigger('click');

        expect(wrapper.findAll(titles)).toHaveLength(1);
    });

    it('lands a 422 on terms.N.title under that row', async () => {
        const fetchMock = respond({
            saves: [refusal(422, 'validation_failed', [{ field: 'terms.3.title', code: 'invalid', message: 'An added term needs a name.' }])],
        });
        const { wrapper } = await render(fetchMock);

        await fillHeader(wrapper);
        await enterLine(wrapper);
        await wrapper.find(id('add-term')).trigger('click');
        await wrapper.find(id('term-3-body')).setValue('Six months');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(wrapper.find(id('term-3-error')).text()).toBe('An added term needs a name.');
        expect(wrapper.find(id('term-0-error')).exists()).toBe(false);
    });

    it('counts a changed term as an unsaved change', async () => {
        const { wrapper, router } = await render(respond(), '/quotations/q1/edit');

        await wrapper.find(id('term-1-title')).setValue('Shipping');
        await router.push('/quotations/q1');
        await flushPromises();

        expect(wrapper.find(id('unsaved')).exists()).toBe(true);
        expect(router.currentRoute.value.path).toBe('/quotations/q1/edit');
    });
});

/**
 * F-42 · P5 (docs/ux-upgrade/plan.md): the builder follows its route (A-01),
 * moves focus on add and remove (A-17), removes at once with Undo (A-35),
 * names each Remove (A-36), keeps Enter from saving mid-entry (A-37), and says
 * the edit route's refusal and the missing total in words (A-03, A-15).
 */
describe('the quotation builder (F-42 · P5)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    afterEach(() => {
        vi.useRealTimers();
        attached.splice(0).forEach((wrapper) => wrapper.unmount());
    });

    const EDIT = '/quotations/q1/edit';
    const DEAL_2 = { ...DEAL, id: 'd2', code: 'DL-2026-0002' };

    /** `respond()` on the new-pricing Draft (QS.2a), with a second deal and whatever `extra` answers first. */
    function respondWith(extra: (url: string) => Response | null = () => null): ReturnType<typeof vi.fn> {
        const base = respond({ quotations: [PRICED] }) as unknown as (input: string, init?: RequestInit) => Promise<Response>;

        return vi.fn(async (input: string, init?: RequestInit) => {
            const url = String(input);

            if (url.includes('/deals/d2')) {
                return json(200, envelope(DEAL_2));
            }

            return extra(url) ?? base(input, init);
        });
    }

    const active = (): string | null => document.activeElement?.getAttribute('data-testid') ?? null;

    it('A-01: reloads when the route moves to another deal, and an edit then a create starts clean', async () => {
        const { wrapper, router } = await render(respondWith());

        await router.push('/quotations/new?deal=d2');
        await flushPromises();

        expect(wrapper.find(id('deal')).text()).toBe('DL-2026-0002');

        await router.push(EDIT);
        await flushPromises();
        expect(rows(wrapper)).toHaveLength(2);

        await router.push('/quotations/new?deal=d1');
        await flushPromises();

        expect(wrapper.find(id('deal')).text()).toBe('DL-2026-0001');
        expect(wrapper.find('h1').text()).toBe(en.quotations.builder.title);
        expect(rows(wrapper)).toHaveLength(0);
        expect(value(wrapper, 'entry-price')).toBe('');
    });

    it('A-17: Add item focuses the new row’s first field; a removed last row hands focus to Add once Undo expires', async () => {
        const { wrapper } = await render(respond(), undefined, 'en', USER, true);

        await wrapper.find(id('add-item')).trigger('click');
        await flushPromises();
        expect(active()).toBe('quotation-builder-item-0-description');

        await wrapper.find(id('add-term')).trigger('click');
        await flushPromises();
        expect(active()).toBe('quotation-builder-term-3-title');

        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        await wrapper.find(id('item-0-remove')).trigger('click');
        await nextTick();
        expect(active()).toBe('quotation-builder-undo');

        vi.runAllTimers();
        await nextTick();
        await nextTick();
        expect(active()).toBe('quotation-builder-add-item');
    });

    it('A-35: a row’s trash takes it at once, and Undo brings it back where it was', async () => {
        const { wrapper } = await render(respondWith(), EDIT, 'en', USER, true);

        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        await wrapper.get(id('line-0-remove')).trigger('click');
        await nextTick();

        expect(rows(wrapper)).toHaveLength(1);
        expect(cell(wrapper, 0, 'product')).toBe('Installation');
        expect(wrapper.find(id('undo-bar')).attributes('role')).toBe('status');

        await wrapper.get(id('undo')).trigger('click');
        await nextTick();

        expect(rows(wrapper)).toHaveLength(2);
        expect(cell(wrapper, 0, 'product')).toBe('Pump 5HP');
        expect(wrapper.find(id('undo')).exists()).toBe(false);
    });

    it('A-36: each row’s trash and send-back name their line by number', async () => {
        const { wrapper } = await render(respondWith(), EDIT);

        expect(wrapper.get(id('line-0-remove')).attributes('aria-label')).toBe('Remove line 1');
        expect(wrapper.get(id('line-1-remove')).attributes('aria-label')).toBe('Remove line 2');
        expect(wrapper.get(id('line-0-send-back')).attributes('aria-label')).toMatch(/\b1\b/);
        expect(wrapper.get(id('line-1-send-back')).attributes('aria-label')).toMatch(/\b2\b/);
        expect(wrapper.get(id('line-0-send-back')).attributes('aria-label')).not.toBe('Remove line 1');
        expect(wrapper.find(id('item-0-remove')).attributes('aria-label')).toBe('Remove item 1');
        expect(wrapper.find(id('term-0-remove')).attributes('aria-label')).toBe('Remove term 1');
    });

    // The owner, 2026-10-22: Enter in the entry row moves it into the table; only Save submits.
    it('A-37: Enter in the entry row moves it into the table and sends nothing', async () => {
        const fetchMock = respond();
        const { wrapper } = await render(fetchMock, undefined, 'en', USER, true);

        await wrapper.find(id('currency')).setValue('EGP');
        await typeEntry(wrapper, E1);
        (wrapper.get(id('entry-quantity')).element as HTMLInputElement).focus();
        await wrapper.get(id('entry-quantity')).trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(saves(fetchMock)).toEqual([]);
        expect(rows(wrapper)).toHaveLength(1);
    });

    it('A-62: the entry row’s margin keeps the full keyboard and reads left to right, so a minus can be typed (D-106)', async () => {
        const { wrapper } = await render(respond());

        expect(wrapper.get(id('entry-margin')).attributes('inputmode')).not.toBe('decimal');
        expect(wrapper.get(id('entry-margin')).attributes('dir')).toBe('ltr');
    });

    it('A-03: a refused edit says the quotation cannot be edited, not that a deal is missing', async () => {
        const { wrapper } = await render(respondWith((url) => (url.endsWith('/quotations/q1') ? refusal(404, 'not_found') : null)), EDIT);

        expect(wrapper.find(id('missing')).text()).toBe(en.quotations.builder.missingEdit);
    });

    it('A-15: one muted line under Save says the total appears after saving', async () => {
        const { wrapper } = await render(respond(), undefined, 'ar');

        expect(wrapper.find(id('total-note')).text()).toBe(ar.quotations.builder.totalAfterSave);
    });
});
