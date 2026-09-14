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
        { id: 'sqi1', catalog_item_id: 'ci1', unit_price: '1000.000000', quantity: '5.000' },
        { id: 'sqi2', catalog_item_id: 'ci2', unit_price: '250.000000', quantity: '1.000' },
    ],
};

const CREATED = { id: 'q9', code: 'QT-2026-0009', status: 'draft', version: 1 };

const USER: AuthenticatedUser = {
    id: 'u1',
    name: 'Test Sales',
    email: 'sales@example.test',
    role: { id: 'r1', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['quotation.create.own', 'quotation.view.own', 'deal.view.own', 'supplier_quotation.view.all', 'supplier_quotation.create.all', 'customer.view.all', 'catalog.view.all'],
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
} = {}): ReturnType<typeof vi.fn> {
    const { dealStatus = 200, saves = [json(201, envelope(CREATED))] } = options;
    let saved = 0;

    return vi.fn(async (input: string, init?: RequestInit) => {
        const url = String(input);

        if (init?.method === 'POST' && url.endsWith('/quotations')) {
            const response = saves[Math.min(saved, saves.length - 1)] as Response;
            saved += 1;

            return response.clone();
        }

        if (url.includes('/deals/')) {
            return dealStatus === 200 ? json(200, envelope(DEAL)) : refusal(dealStatus, dealStatus === 404 ? 'not_found' : 'forbidden');
        }

        if (url.includes('/customers/')) {
            return json(200, envelope(CUSTOMER));
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

async function render(fetchMock: ReturnType<typeof vi.fn>, path = '/quotations/new?deal=d1', locale: 'en' | 'ar' = 'en') {
    await signIn(USER, fetchMock as unknown as typeof globalThis.fetch);
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
    const router = createAppRouter();

    await router.push(path);
    await router.isReady();

    const wrapper = mount(QuotationBuilderView, { global: { plugins: [i18n, router] } });

    await flushPromises();

    return { wrapper, router };
}

/** The `POST /quotations` calls the screen made — body and the header that matters. */
function saves(fetchMock: ReturnType<typeof vi.fn>): Array<{ body: unknown; idempotencyKey: string | null }> {
    return fetchMock.mock.calls
        .filter((call) => (call[1] as RequestInit | undefined)?.method === 'POST' && String(call[0]).endsWith('/quotations'))
        .map((call) => ({
            body: JSON.parse(String((call[1] as RequestInit).body)) as unknown,
            idempotencyKey: new Headers((call[1] as RequestInit).headers).get('Idempotency-Key'),
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

    it('lists a picked supplier quotation’s lines with the catalog name, the recorded price and quantity', async () => {
        const { wrapper } = await render(respond());

        await wrapper.find(id('add-supplier')).trigger('click');
        await wrapper.find(id('supplier-0-pick')).setValue('sq1');
        await flushPromises();

        expect(wrapper.find(id('line-0-0-label')).text()).toBe('Pump 5HP');
        expect(wrapper.find(id('line-0-0-recorded')).text()).toContain('1000.000000');
        expect(wrapper.find(id('line-0-0-recorded')).text()).toContain('5.000');
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

    // ──────────────────────────────────────────────────────────── refusals

    it('blocks the save at the line whose supplier price is missing (§5.6)', async () => {
        const fetchMock = respond({
            saves: [refusal(422, 'business_rule_blocked', [
                { field: 'lines.1.supplier_quotation_item_id', code: 'supplier_price_missing', message: 'This supplier line has no usable price, so the quotation cannot be saved.' },
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

    it('keeps the saved draft on screen with the quantity warning red on its line, and a link on (§5.6, Q3)', async () => {
        const fetchMock = respond({
            saves: [json(201, envelope(CREATED, {
                warnings: [{ field: 'lines.0.quantity', code: 'quantity_exceeds_recorded', message: 'The requested quantity exceeds what the supplier recorded.' }],
            }))],
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
        expect(wrapper.find(id('save')).exists()).toBe(false);
    });

    // ──────────────────────────────────────────────────────────── permission

    it('draws a 403 on the deal as a page refusal', async () => {
        const { wrapper } = await render(respond({ dealStatus: 403 }));

        expect(wrapper.find('[data-testid="permission-denied-state"]').exists()).toBe(true);
    });
});
