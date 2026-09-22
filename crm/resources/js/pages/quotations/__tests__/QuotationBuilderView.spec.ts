import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
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
 * string (`DB-07`); nothing is priced here (`D-67`). A missing supplier price
 * blocks the save at the line it names; a quantity above the recorded one is
 * a red line that does not block (`§5.6`, `Design System §7.2`).
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

/** What `POST /quotations` really answers — `QuotationPayload::of()`: no etag, no detail (F-03 found the SPA assuming one). */
const CREATED = { id: 'q9', code: 'QT-2026-0009' };

/** A Draft as `GET /quotations/{id}` answers it, with §3.5's cost keys (Q7). */
const QUOTATION = {
    id: 'q1', code: 'QT-2026-0001', status: 'draft', customer_id: 'c1', deal_id: 'd1', currency_id: 'cur-egp', currency: 'EGP',
    final_total: '1150.000000', quotation_date: '2026-09-13', valid_until: '2026-10-13', submitted_at: null, version: 1, parent_id: null,
    created_at: '2026-09-13T09:00:00+00:00', updated_at: '2026-09-13T09:00:00+00:00',
    default_margin: '25.00', discount_percent: '10.00', tax_percent: '14.00', rounding_unit: '1.000000', rounding_enabled: true,
    subtotal: '1000.000000', additional_total: '100.000000', discount_amount: '100.000000', tax_base: '900.000000', tax_amount: '126.000000',
    net_amount: '1026.000000', total_before_round: '1126.000000', rounding_diff: '24.000000',
    payment_terms: '50% advance', warranty: null, delivery_terms: 'Ex works', show_delivery_terms: false,
    rejection_reason: null, sent_at: null, is_self_approved: false, etag: '"v1"',
    items: [
        { id: 'l1', line_no: 1, supplier_quotation_item_id: 'sqi1', product_name: 'Split unit 1.5HP', quantity: '2.000', unit_price: '500.000000', line_total: '1000.000000', unit_cost: '400.000000', margin_percent: '25.00' },
        { id: 'l2', line_no: 2, supplier_quotation_item_id: 'sqi2', product_name: null, quantity: '1.000', unit_price: '300.000000', line_total: '300.000000', unit_cost: '250.000000', margin_percent: null },
    ],
    additional_items: [{ id: 'a1', line_no: 1, description: 'Delivery', amount: '100.000000' }],
    created_by: 'u1', updated_by: 'u1',
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
} = {}): ReturnType<typeof vi.fn> {
    const { dealStatus = 200, saves = [json(201, envelope(CREATED))], quotations = [QUOTATION], updates = [json(200, envelope(QUOTATION))], suggestions = {}, currenciesStatus = 200 } = options;
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
            return json(200, envelope(SQ_DETAIL));
        }

        if (url.includes('/supplier-quotations')) {
            return json(200, envelope(url.includes('filter%5Bdeal_id%5D=d1') ? [SQ_DEAL] : [SQ_DEAL, SQ_OTHER]));
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

async function render(fetchMock: ReturnType<typeof vi.fn>, path = '/quotations/new?deal=d1', locale: 'en' | 'ar' = 'en', user = USER) {
    await signIn(user, fetchMock as unknown as typeof globalThis.fetch);
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const router = createAppRouter();

    await router.push(path);
    await router.isReady();

    // Through `<RouterView>`, not mounted directly: `onBeforeRouteLeave` binds
    // to the route record the view renders, and a direct mount has none.
    const wrapper = mount({ components: { QuotationBuilderView }, template: '<RouterView />' }, { global: { plugins: [i18n, router] } });

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

/** One supplier block on `sq1`, its first line at `quantity`. */
async function pickFirstLine(wrapper: Awaited<ReturnType<typeof render>>['wrapper'], quantity = '2'): Promise<void> {
    await wrapper.find(id('add-supplier')).trigger('click');
    await wrapper.find(id('supplier-0-pick')).setValue('sq1');
    await flushPromises();
    await wrapper.find(id('line-0-0-quantity')).setValue(quantity);
}

async function fillHeader(wrapper: Awaited<ReturnType<typeof render>>['wrapper']): Promise<void> {
    await wrapper.find(id('currency')).setValue('EGP');
    await wrapper.find(id('default_margin')).setValue('25');
    await wrapper.find(id('discount_percent')).setValue('10');
}

describe('the quotation builder (create)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    // ──────────────────────────────────────────────────────────── the opening

    it('opens on the deal named by ?deal=, its customer by name, and the deal’s supplier quotations first', async () => {
        const { wrapper } = await render(respond());

        expect(wrapper.find(id('deal')).text()).toBe('DL-2026-0001');
        expect(wrapper.find(id('customer')).text()).toBe('Acme Industrial');

        await wrapper.find(id('add-supplier')).trigger('click');

        const options = wrapper.find(id('supplier-0-pick')).findAll('option').map((option) => option.text());

        expect(options).toEqual(['', 'SQ-2026-0001 — Nile Pumps Co', 'SQ-2026-0002 — Delta Valves']);
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

    // ──────────────────────────────────────────────────────────── suppliers

    it('lists a picked supplier quotation’s lines with the catalog name, the supplier price and the available quantity', async () => {
        const { wrapper } = await render(respond());

        await wrapper.find(id('add-supplier')).trigger('click');
        await wrapper.find(id('supplier-0-pick')).setValue('sq1');
        await flushPromises();

        expect(wrapper.find(id('line-0-0-label')).text()).toBe('Pump 5HP');
        expect(wrapper.find(id('line-0-0-recorded')).text()).toContain('1000.000');
        expect(wrapper.find(id('line-0-0-recorded')).text()).not.toContain('1000.000000');
        // F-05 (D-81): the figure beside the price is what is left of the offer, not what it recorded.
        expect(wrapper.find(id('line-0-0-recorded')).text()).toContain('3.000');
        expect(wrapper.find(id('line-0-0-recorded')).text()).not.toContain('5.000');
        expect(wrapper.find(id('line-0-0-recorded')).text()).not.toContain('3.0000');
        // F-04 + F-05: the placeholder is the available balance; D-82 cuts it after the third decimal.
        expect(wrapper.find(id('line-0-0-quantity')).attributes('placeholder')).toBe('3.000');
        expect(wrapper.find(id('line-0-1-label')).text()).toBe('Installation');
    });

    it('stops at ten suppliers (§6.1)', async () => {
        const { wrapper } = await render(respond());

        for (let index = 0; index < 10; index += 1) {
            await wrapper.find(id('add-supplier')).trigger('click');
        }

        expect(wrapper.findAll('[data-testid^="quotation-builder-supplier-"][data-testid$="-pick"]')).toHaveLength(10);
        expect(wrapper.find(id('add-supplier')).attributes('disabled')).toBeDefined();
    });

    it('removes a supplier block', async () => {
        const { wrapper } = await render(respond());

        await wrapper.find(id('add-supplier')).trigger('click');
        await wrapper.find(id('supplier-0-remove')).trigger('click');

        expect(wrapper.find(id('supplier-0-pick')).exists()).toBe(false);
    });

    it('adds the supplier quotation Module 6’s form just saved as a new block', async () => {
        const { wrapper } = await render(respond());

        await wrapper.find(id('new-supplier-quotation')).trigger('click');

        const modal = wrapper.findComponent({ name: 'SupplierQuotationFormModal' });

        expect(modal.props('open')).toBe(true);

        modal.vm.$emit('saved', { ...SQ_OTHER, id: 'sq1', deal_id: 'd1' });
        await flushPromises();

        expect((wrapper.find(id('supplier-0-pick')).element as HTMLSelectElement).value).toBe('sq1');
        expect(wrapper.find(id('line-0-0-quantity')).exists()).toBe(true);
    });

    // ──────────────────────────────────────────────────────────── the payload

    it('sends what the form holds, money as strings, only lines with a quantity, and an Idempotency-Key', async () => {
        const fetchMock = respond();
        const { wrapper, router } = await render(fetchMock);

        await fillHeader(wrapper);
        await wrapper.find(id('tax_percent')).setValue('14');
        await wrapper.find(id('quotation_date')).setValue('2026-09-14');
        await wrapper.find(id('valid_until')).setValue('2026-10-14');
        await wrapper.find(id('payment_terms')).setValue('50% advance');
        await pickFirstLine(wrapper, '2.5');
        await wrapper.find(id('line-0-0-margin_percent')).setValue('30');
        await wrapper.find(id('add-item')).trigger('click');
        await wrapper.find(id('item-0-description')).setValue('Delivery');
        await wrapper.find(id('item-0-amount')).setValue('100');

        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const [save] = saves(fetchMock);

        expect(save?.body).toEqual({
            deal_id: 'd1',
            customer_id: 'c1',
            currency: 'EGP',
            default_margin: '25',
            discount_percent: '10',
            tax_percent: '14',
            quotation_date: '2026-09-14',
            valid_until: '2026-10-14',
            payment_terms: '50% advance',
            warranty: null,
            delivery_terms: null,
            show_delivery_terms: true,
            lines: [{ supplier_quotation_item_id: 'sqi1', quantity: '2.5', margin_percent: '30' }],
            additional_items: [{ description: 'Delivery', amount: '100' }],
        });
        expect(save?.idempotencyKey).toMatch(/^[0-9a-f-]{36}$/);
        expect(router.currentRoute.value.path).toBe('/quotations/q9');
    });

    it('sends no tax when the field is empty — exempt is null, not zero (D-63)', async () => {
        const fetchMock = respond();
        const { wrapper } = await render(fetchMock);

        await fillHeader(wrapper);
        await pickFirstLine(wrapper);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect((saves(fetchMock)[0]?.body as { tax_percent: unknown }).tax_percent).toBeNull();
    });

    it('reuses the Idempotency-Key on a retry (OpenAPI §9.1)', async () => {
        const fetchMock = respond({ saves: [refusal(500, 'unexpected'), json(201, envelope(CREATED))] });
        const { wrapper, router } = await render(fetchMock);

        await fillHeader(wrapper);
        await pickFirstLine(wrapper);
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

        await pickFirstLine(wrapper);
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

    it('blocks the save at the line whose supplier price is missing (§5.6)', async () => {
        const fetchMock = respond({
            saves: [refusal(422, 'business_rule_blocked', [
                { field: 'lines.1.supplier_quotation_item_id', code: 'supplier_price_missing', message: 'This supplier line has no usable price — the line is gone, or its supplier quotation has no currency — so the quotation cannot be saved.' },
            ])],
        });
        const { wrapper, router } = await render(fetchMock);

        await fillHeader(wrapper);
        await pickFirstLine(wrapper, '1');
        await wrapper.find(id('line-0-1-quantity')).setValue('3');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(wrapper.find(id('line-0-0-error')).exists()).toBe(false);
        expect(wrapper.find(id('line-0-1-error')).text()).toContain('no usable price');
        expect(router.currentRoute.value.path).toBe('/quotations/new');
    });

    it('shows a missing FX rate at the form, not at a line', async () => {
        const fetchMock = respond({
            saves: [refusal(422, 'business_rule_blocked', [
                { field: 'lines.0.supplier_quotation_item_id', code: 'fx_rate_missing', message: 'No exchange rate is recorded to convert this supplier line. Record the rate first.' },
            ])],
        });
        const { wrapper } = await render(fetchMock);

        await fillHeader(wrapper);
        await pickFirstLine(wrapper);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(wrapper.find(id('form-error')).text()).toContain('No exchange rate');
        expect(wrapper.find(id('line-0-0-error')).exists()).toBe(false);
    });

    it('lands a validation sentence on the header field it names', async () => {
        const fetchMock = respond({
            saves: [refusal(422, 'validation_failed', [{ field: 'discount_percent', code: 'lt', message: 'The discount must be below 100.' }])],
        });
        const { wrapper } = await render(fetchMock);

        await fillHeader(wrapper);
        await wrapper.find(id('discount_percent')).setValue('100');
        await pickFirstLine(wrapper);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(wrapper.find(id('discount_percent-error')).text()).toBe('The discount must be below 100.');
    });

    /**
     * F-03 (owner, 2026-09-16): the warning must not freeze the form. The draft
     * exists, so the form stays **editable** and the next save is a `PATCH` on
     * it with its token — a second `POST` under the same key would be a 409.
     */
    it('keeps the saved draft on screen with the quantity warning red on its line, editable, and saves again as a PATCH (§5.6)', async () => {
        const fetchMock = respond({
            saves: [json(201, envelope(CREATED, {
                warnings: [{ field: 'lines.0.quantity', code: 'quantity_exceeds_recorded', message: 'The requested quantity exceeds what the supplier recorded.' }],
            }))],
            updates: [json(200, envelope({ ...QUOTATION, ...CREATED, etag: '"q9-v2"' }))],
        });
        const { wrapper, router } = await render(fetchMock);

        await fillHeader(wrapper);
        await pickFirstLine(wrapper, '9');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/quotations/new');
        expect(wrapper.find(id('line-0-0-warning')).text()).toContain('exceeds');
        expect(wrapper.find(id('saved')).text()).toContain('QT-2026-0009');
        expect(wrapper.find(id('saved-link')).attributes('href')).toBe('/quotations/q9');
        expect(wrapper.find(id('save')).exists()).toBe(true);
        expect((wrapper.find(id('line-0-0-quantity')).element as HTMLInputElement).disabled).toBe(false);
        // The 201 carries no etag; the token is the draft's own, read once after the warned create.
        expect(fetchMock.mock.calls.filter((call) => String(call[0]).endsWith('/quotations/q9') && ((call[1] as RequestInit | undefined)?.method ?? 'GET') === 'GET')).toHaveLength(1);

        await wrapper.find(id('line-0-0-quantity')).setValue('2');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const calls = saves(fetchMock);

        expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['POST /quotations', 'PATCH /quotations/q9']);
        expect(calls[1]?.ifMatch).toBe('"q9-v1"');
        expect(calls[1]?.body).toMatchObject({ lines: [{ supplier_quotation_item_id: 'sqi1', quantity: '2' }] });
        expect(router.currentRoute.value.path).toBe('/quotations/q9');
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

    it('offers the caller’s recent terms under each field, newest first, and a click copies one in', async () => {
        const { wrapper } = await render(respond({ suggestions: { warranty: ['Two years', 'One year'], payment_terms: ['50% advance'] } }));

        expect(wrapper.findAll(id('warranty-suggestion')).map((chip) => chip.text())).toEqual(['Two years', 'One year']);
        expect(wrapper.findAll(id('payment_terms-suggestion')).map((chip) => chip.text())).toEqual(['50% advance']);
        expect(wrapper.find(id('delivery_terms-suggestions')).exists()).toBe(false);

        await wrapper.findAll(id('warranty-suggestion'))[1]?.trigger('click');

        expect((wrapper.find(id('warranty')).element as HTMLTextAreaElement).value).toBe('One year');
    });

    it('asks for the three fields on an edit too, and a refused lookup leaves the form usable', async () => {
        const fetchMock = respond({ suggestions: { delivery_terms: ['Ex works'] } });
        const { wrapper } = await render(fetchMock, '/quotations/q1/edit');

        const asked = fetchMock.mock.calls.map((call) => String(call[0])).filter((url) => url.includes('/user-term-suggestions'));

        expect(asked.map((url) => url.split('field=')[1])).toEqual(['payment_terms', 'warranty', 'delivery_terms']);
        expect(wrapper.findAll(id('delivery_terms-suggestion')).map((chip) => chip.text())).toEqual(['Ex works']);
        expect(wrapper.find(id('warranty-suggestions')).exists()).toBe(false);
        expect(wrapper.find(id('save')).exists()).toBe(true);
    });
});

describe('the quotation builder (edit)', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    const EDIT = '/quotations/q1/edit';

    it('loads the header, the lines and the additional items from the detail', async () => {
        const { wrapper } = await render(respond(), EDIT);

        expect(wrapper.find(id('deal')).text()).toBe('DL-2026-0001');
        expect(wrapper.find(id('customer')).text()).toBe('Acme Industrial');
        expect((wrapper.find(id('currency')).element as HTMLInputElement).value).toBe('EGP');
        expect((wrapper.find(id('default_margin')).element as HTMLInputElement).value).toBe('25.00');
        expect((wrapper.find(id('discount_percent')).element as HTMLInputElement).value).toBe('10.00');
        expect((wrapper.find(id('tax_percent')).element as HTMLInputElement).value).toBe('14.00');
        expect((wrapper.find(id('quotation_date')).element as HTMLInputElement).value).toBe('2026-09-13');
        expect((wrapper.find(id('payment_terms')).element as HTMLTextAreaElement).value).toBe('50% advance');
        expect((wrapper.find(id('warranty')).element as HTMLTextAreaElement).value).toBe('');
        expect((wrapper.find(id('show_delivery_terms')).element as HTMLInputElement).checked).toBe(false);
        expect((wrapper.find(id('existing-0-quantity')).element as HTMLInputElement).value).toBe('2.000');
        expect((wrapper.find(id('existing-0-margin_percent')).element as HTMLInputElement).value).toBe('25.00');
        expect(wrapper.find(id('existing-0-cost')).text()).toContain('400.000');
        expect(wrapper.find(id('existing-0-cost')).text()).not.toContain('400.000000');
        expect(wrapper.find(id('existing-0-product')).text()).toBe('Split unit 1.5HP');
        expect(wrapper.find(id('existing-1-product')).exists()).toBe(false);
        expect((wrapper.find(id('existing-1-margin_percent')).element as HTMLInputElement).value).toBe('');
        expect((wrapper.find(id('item-0-description')).element as HTMLInputElement).value).toBe('Delivery');
        expect((wrapper.find(id('item-0-amount')).element as HTMLInputElement).value).toBe('100.000000');
    });

    it('sends back to the quotation’s page when it is no longer a Draft', async () => {
        const { router } = await render(respond({ quotations: [{ ...QUOTATION, status: 'pending' }] }), EDIT);

        expect(router.currentRoute.value.path).toBe('/quotations/q1');
    });

    it('sends PATCH with If-Match, every editable field, the kept lines and the new one, no deal or customer', async () => {
        const fetchMock = respond();
        const { wrapper, router } = await render(fetchMock, EDIT);

        await wrapper.find(id('existing-0-quantity')).setValue('3');
        await wrapper.find(id('existing-1-remove')).trigger('click');
        await pickFirstLine(wrapper, '4');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const [save] = saves(fetchMock);

        expect(save?.method).toBe('PATCH');
        expect(save?.ifMatch).toBe('"v1"');
        expect(save?.idempotencyKey).toBeNull();
        expect(save?.body).toEqual({
            currency: 'EGP',
            default_margin: '25.00',
            discount_percent: '10.00',
            tax_percent: '14.00',
            quotation_date: '2026-09-13',
            valid_until: '2026-10-13',
            payment_terms: '50% advance',
            warranty: null,
            delivery_terms: 'Ex works',
            show_delivery_terms: false,
            lines: [
                { supplier_quotation_item_id: 'sqi1', quantity: '3', margin_percent: '25.00' },
                { supplier_quotation_item_id: 'sqi1', quantity: '4' },
            ],
            additional_items: [{ description: 'Delivery', amount: '100.000000' }],
        });
        expect(router.currentRoute.value.path).toBe('/quotations/q1');
    });

    it('sends both lists even when emptied — an omitted list is a 422, not "keep them" (Point 3.6)', async () => {
        const fetchMock = respond();
        const { wrapper } = await render(fetchMock, EDIT);

        await wrapper.find(id('existing-0-remove')).trigger('click');
        await wrapper.find(id('existing-0-remove')).trigger('click');
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

        await wrapper.find(id('existing-0-quantity')).setValue('3');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe(EDIT);
        expect(wrapper.find(id('conflict')).text()).toContain('2000.000');
        expect(wrapper.find(id('conflict')).text()).not.toContain('2000.000000'); // D-82
        expect(saves(fetchMock)).toHaveLength(1);

        await wrapper.find(id('conflict-reload')).trigger('click');
        await flushPromises();

        expect(wrapper.find(id('conflict')).exists()).toBe(false);
        expect((wrapper.find(id('existing-0-quantity')).element as HTMLInputElement).value).toBe('2.000');

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

    it('keeps the saved draft on screen when the 200 carries a quantity warning, red on the existing line', async () => {
        const fetchMock = respond({
            updates: [json(200, envelope(QUOTATION, { warnings: [{ field: 'lines.1.quantity', code: 'quantity_exceeds_recorded', message: 'exceeds' }] }))],
        });
        const { wrapper, router } = await render(fetchMock, EDIT);

        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe(EDIT);
        expect(wrapper.find(id('existing-1-warning')).text()).toContain('exceeds');
        expect(wrapper.find(id('saved-link')).attributes('href')).toBe('/quotations/q1');
        // F-03: still editable; a second save is a second PATCH.
        expect(wrapper.find(id('save')).exists()).toBe(true);

        await wrapper.find(id('existing-1-quantity')).setValue('1');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(saves(fetchMock).map((call) => call.method)).toEqual(['PATCH', 'PATCH']);
    });

    it('asks before leaving with unsaved changes, and not otherwise (Design System §5.2)', async () => {
        // happy-dom ships no `confirm`; the stub is the whole dialog.
        const confirm = vi.fn().mockReturnValue(false);
        vi.stubGlobal('confirm', confirm);

        const { wrapper, router } = await render(respond(), EDIT);

        await router.push('/quotations/q1');
        expect(confirm).not.toHaveBeenCalled();
        expect(router.currentRoute.value.path).toBe('/quotations/q1');

        await router.push(EDIT);
        await flushPromises();
        await wrapper.find(id('existing-0-quantity')).setValue('3');
        await router.push('/quotations/q1');

        expect(confirm).toHaveBeenCalledTimes(1);
        expect(router.currentRoute.value.path).toBe(EDIT);

        confirm.mockReturnValue(true);
        await router.push('/quotations/q1');

        expect(router.currentRoute.value.path).toBe('/quotations/q1');
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
    const PENDING = { ...QUOTATION, status: 'pending' };

    it('keeps a pending quotation on the form instead of sending it back to its page', async () => {
        const { wrapper, router } = await render(respond({ quotations: [PENDING] }), APPROVE, 'en', APPROVER);

        expect(router.currentRoute.value.path).toBe(APPROVE);
        expect((wrapper.find(id('default_margin')).element as HTMLInputElement).value).toBe('25.00');
        expect(wrapper.find(id('save')).text()).toBe('Edit & approve');
    });

    it('still sends a quotation that is not pending back to its page', async () => {
        const { router } = await render(respond({ quotations: [{ ...QUOTATION, status: 'approved' }] }), APPROVE, 'en', APPROVER);

        expect(router.currentRoute.value.path).toBe('/quotations/q1');
    });

    it('saves to /edit-and-approve with If-Match and 6.7\'s body, then lands on the quotation\'s page', async () => {
        const fetchMock = respond({ quotations: [PENDING], updates: [json(200, envelope({ ...PENDING, status: 'approved' }))] });
        const { wrapper, router } = await render(fetchMock, APPROVE, 'en', APPROVER);

        await wrapper.find(id('default_margin')).setValue('30');
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        const [save] = saves(fetchMock);

        expect(saves(fetchMock)).toHaveLength(1);
        expect(save?.method).toBe('PATCH');
        expect(save?.path).toBe('/quotations/q1/edit-and-approve');
        expect(save?.ifMatch).toBe('"v1"');
        expect(save?.idempotencyKey).toBeNull();
        expect(save?.body).toMatchObject({ default_margin: '30', currency: 'EGP', lines: [{ supplier_quotation_item_id: 'sqi1', quantity: '2.000', margin_percent: '25.00' }, { supplier_quotation_item_id: 'sqi2', quantity: '1.000' }], additional_items: [{ description: 'Delivery', amount: '100.000000' }] });
        expect(save?.body).not.toHaveProperty('deal_id');
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
        await wrapper.find(id('default_margin')).setValue('25');
        await pickFirstLine(wrapper);
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
        await wrapper.find(id('default_margin')).setValue('25');
        await pickFirstLine(wrapper);
        await wrapper.find(id('form')).trigger('submit');
        await flushPromises();

        expect(saves(fetchMock)[0]?.body).toMatchObject({ currency: 'EGP' });
    });
});
