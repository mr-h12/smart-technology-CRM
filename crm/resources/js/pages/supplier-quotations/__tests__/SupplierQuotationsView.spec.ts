import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import SupplierQuotationsView from '@/pages/supplier-quotations/SupplierQuotationsView.vue';
import { createAppRouter } from '@/router';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 6, Point 6.2 — §8's *Supplier Quotations* screen, Design System
 * §5.2's Table/List.
 *
 * ── The list is the server's, and the test reads the URL to prove it ───────
 *
 * §5.2 requires "server-side filters/sort/search" and §6.5 that "Every list is
 * server-paginated". A client-side `filter()` narrows the 25 rows in hand and
 * silently claims to have narrowed all of them, so every assertion about a
 * filter or a sort below reads the **query string**, never the rendered rows.
 *
 * The declared surface is `SupplierQuotationListCriteria`'s: **three** filters
 * (`supplier_id`, `deal_id`, `deal_code`), two sorts (`offer_date`, `created_at`),
 * `DEFAULT_SORT = offer_date` descending, and **no search at all**.
 * `OpenAPI §6.2` answers anything else with a 400.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 and `SEC-09` put enforcement at the API, where
 * `SupplierQuotationListEndpointTest` proves it by withdrawing the seeded
 * grant. What is proved here is what the screen draws — including that a 403 is
 * drawn as a refusal rather than as an empty list, which would read as "there
 * are no offers".
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const PAGINATION = {
    page: 1,
    per_page: 25,
    total: 1,
    total_pages: 1,
    has_next_page: false,
    has_previous_page: false,
};

const OFFER = {
    id: 'q1',
    code: 'SQ-2026-0001',
    supplier_id: 's1',
    deal_id: null,
    deal_code: null,
    total_price: '4500.000000',
    currency_id: 'c1',
    offer_date: '2026-09-01',
    valid_until: null,
    notes: null,
};

const CURRENCY = { id: 'c1', code: 'EGP', rounding_unit: '1', rounding_enabled: true, is_base: true };

const SUPPLIER = { id: 's1', name: 'Alpha Supply', type: 'supplier', color_rating: 'green', phone: null, contact_person: null, has_open_account: false, is_active: true, created_at: '2026-08-01T00:00:00+00:00', updated_at: '2026-08-01T00:00:00+00:00' };

const USER: AuthenticatedUser = {
    id: 'u1',
    name: 'Test Manager',
    email: 'manager@example.test',
    role: { id: 'r1', slug: 'manager', name: 'Manager' },
    permissions: [
        'supplier_quotation.view.all',
        'supplier_quotation.create.all',
        // §3.6 seeds a **third** row, and Point 6.5 is the first thing that
        // reads it. `PermissionMatrix` grants it to the same five roles as
        // `create` today, but RBAC is database-backed and dynamic (`SEC-07`),
        // so the two are not interchangeable.
        'supplier_quotation.upload_attachment.all',
    ],
    is_active: true,
    unconditional_access: false,
};

/**
 * §3.6's documented negative case: the CEO holds `supplier_quotation.view` and
 * **not** `create`, so every write control on this screen is drawn for the
 * Manager above and for nobody else.
 */
const CEO: AuthenticatedUser = {
    ...USER,
    id: 'u2',
    name: 'Test CEO',
    email: 'ceo@example.test',
    role: { id: 'r2', slug: 'ceo', name: 'CEO' },
    permissions: ['supplier_quotation.view.all'],
};

/**
 * A writer whose `upload_attachment` was revoked through §3.11's role screen —
 * which is exactly what the permission-matrix incident of 2026-08-31 showed can
 * happen to a live role. `create` alone must not draw an upload control.
 */
const WRITER_WITHOUT_UPLOAD: AuthenticatedUser = {
    ...USER,
    id: 'u3',
    permissions: ['supplier_quotation.view.all', 'supplier_quotation.create.all'],
};

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'r1', ...extra } };
}

const CATALOG_ITEM = {
    id: 'ci1',
    kind: 'product',
    name: 'Cable 2.5mm',
    product_code: 'P-1',
    category: null,
    unit: 'm',
    service_type: null,
    company: null,
    description: null,
    notes: null,
    is_active: true,
    created_at: '2026-08-01T00:00:00+00:00',
    updated_at: '2026-08-01T00:00:00+00:00',
};

/** `SupplierQuotationPayload::detail()` — the header, plus `items`, always present. */
const OFFER_LINES = [{ catalog_item_id: 'ci1', unit_price: '1500.000000', quantity: '3.000' }];

/**
 * The same lines as the detail publishes them — with F-05's balance (D-81) and
 * F-18 · 1.2's `product_name` (D-93), neither of which the editor sends back.
 */
const OFFER_DETAIL_LINES = OFFER_LINES.map((line) => ({ ...line, product_name: 'Cable 2.5mm', consumed_quantity: '1.0000', available_quantity: '2.0000' }));

/** A `GET /supplier-quotations/{id}`, which a `PATCH` to the same path is not. */
function isDetailRead(input: string, init?: RequestInit): boolean {
    return /\/supplier-quotations\/[^/?]+$/.test(input) && (init?.method ?? 'GET') === 'GET';
}

/**
 * Answers the offers call, the suppliers call the name column needs, the
 * catalog call the line editor's picker needs, and the detail read an edit
 * makes for its lines.
 */
function respond(
    offers: unknown = [OFFER],
    status = 200,
    detail: unknown = { ...OFFER, items: OFFER_DETAIL_LINES },
    scanStatus = 'clean',
    currenciesStatus = 200,
): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string, init?: RequestInit) => {
        if (String(input).includes('/currencies')) {
            return currenciesStatus === 200
                ? json(200, envelope({ currencies: [CURRENCY] }))
                : json(currenciesStatus, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } });
        }

        if (String(input).endsWith('/download')) {
            return new Response('%PDF-1.4', { status: 200 });
        }

        if (String(input).includes('/documents')) {
            return json(201, envelope({
                id: 'd1',
                original_name: 'offer.pdf',
                mime_type: 'application/pdf',
                size_bytes: 8,
                scan_status: scanStatus,
                created_at: '2026-09-05T00:00:00+00:00',
            }));
        }

        // F-24 · 1.4: the edit dialog reads its supplier's name.
        if (/\/suppliers\/[^/?]+$/.test(String(input))) {
            return json(200, envelope(SUPPLIER));
        }

        if (String(input).includes('/suppliers')) {
            return json(200, envelope([SUPPLIER], { pagination: { ...PAGINATION, per_page: 100 } }));
        }

        if (String(input).includes('/catalog-items')) {
            return json(200, envelope([CATALOG_ITEM], { pagination: { ...PAGINATION, per_page: 100 } }));
        }

        if (isDetailRead(String(input), init)) {
            return json(200, envelope(detail));
        }

        return json(status, status === 200 ? envelope(offers, { pagination: PAGINATION }) : { error: { code: 'forbidden' }, meta: { request_id: 'r1' } });
    });
}

/**
 * `SuppliersView.spec.ts`'s helper, verbatim in shape: the store has no
 * `$patch` — it is not Pinia — so a session is established by answering the
 * login call and letting `useAuth().login()` store what it receives.
 */
async function signIn(profile: AuthenticatedUser, delegate: typeof globalThis.fetch): Promise<void> {
    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
            }))
            : delegate(input as unknown as RequestInfo, init));

    await useAuth().login(profile.email, 'Passw0rd123');
}

async function render(fetchMock: ReturnType<typeof vi.fn>, profile: AuthenticatedUser = USER) {
    await signIn(profile, fetchMock as unknown as typeof globalThis.fetch);
    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });
    const wrapper = mount(SupplierQuotationsView, { global: { plugins: [i18n, createAppRouter()] } });

    await flushPromises();

    return wrapper;
}

/** F-18 · 1.2: a line's product is picked in `CatalogItemPicker` — open it, take the first item. */
async function pickProduct(view: Awaited<ReturnType<typeof render>>, index: number): Promise<void> {
    await view.get(`[data-testid="supplier-quotation-line-${index}-product"]`).trigger('focus');
    await flushPromises();
    await view.get(`[data-testid="supplier-quotation-line-${index}-product-option"]`).trigger('mousedown');
}

/** `D-22`'s first option, «type a name instead»: it empties the line's item and brings the name box back. */
async function chooseTypedName(view: Awaited<ReturnType<typeof render>>, index: number): Promise<void> {
    await view.get(`[data-testid="supplier-quotation-line-${index}-product"]`).trigger('focus');
    await flushPromises();
    await view.get(`[data-testid="supplier-quotation-line-${index}-product-all"]`).trigger('mousedown');
}

/** F-24 · 1.4: the offer's supplier is picked in a server search — open it, take the row named. */
async function pickSupplier(view: Awaited<ReturnType<typeof render>>, name = 'Alpha Supply'): Promise<void> {
    await view.get('[data-testid="supplier-quotation-form-supplier-id"]').trigger('focus');
    await flushPromises();

    const option = view.findAll('[data-testid="supplier-quotation-form-supplier-id-option"]').find((row) => row.text().includes(name));

    expect(option, `no «${name}» option`).toBeDefined();
    await option!.trigger('mousedown');
}

/** Deactivated, with a contact and a phone, and outside the list screen's own load. */
const BETA = { ...SUPPLIER, id: 's2', name: 'Beta Trading', contact_person: 'Mona', phone: '0100', is_active: false };

/**
 * Over `respond()`: the picker's own search (`per_page=20`, `SearchCombobox`'s
 * page) answers Alpha and Beta, while the list screen's `per_page=100` load
 * still answers Alpha alone. `readStatus` fails the edit dialog's supplier read.
 */
function withSupplierSearch(readStatus = 200): ReturnType<typeof vi.fn> {
    const base = respond() as unknown as typeof globalThis.fetch;

    return vi.fn(async (input: string, init?: RequestInit) => {
        const url = new URL(String(input), 'http://localhost');

        if (readStatus !== 200 && /\/suppliers\/[^/]+$/.test(url.pathname)) {
            return json(readStatus, { error: { code: 'internal_error' }, meta: { request_id: 'r1' } });
        }

        if (url.pathname.endsWith('/suppliers') && url.searchParams.get('per_page') === '20') {
            return json(200, envelope([SUPPLIER, BETA], { pagination: { ...PAGINATION, total: 2, per_page: 20 } }));
        }

        return base(input, init);
    });
}

/** The picker's searches — not the list screen's 100. */
function supplierSearches(fetchMock: ReturnType<typeof vi.fn>): URL[] {
    return fetchMock.mock.calls
        .map((call) => new URL(String(call[0]), 'http://localhost'))
        .filter((url) => url.pathname.endsWith('/suppliers') && url.searchParams.get('per_page') === '20');
}

/**
 * F-24 · 1.1: two `DealRow`s as `GET /deals` lists them — one with no title,
 * one whose title and customer are Arabic.
 */
const DEAL = {
    id: 'd3',
    code: 'DL-2026-0003',
    customer_id: 'cu1',
    title: null,
    source: null,
    service_type: null,
    status: 'lead',
    owner_id: null,
    approval_status: null,
    rejection_reason: null,
    lost_reason: null,
    last_activity_at: '2026-09-01T00:00:00+00:00',
    created_at: '2026-09-01T00:00:00+00:00',
    updated_at: '2026-09-01T00:00:00+00:00',
    customer_name: 'Nile Hotels',
};
const DEAL_7 = { ...DEAL, id: 'd7', code: 'DL-2026-0007', title: 'تمديد كابلات', customer_name: 'فندق النيل' };

/** An offer on `DEAL`, as the list publishes it (`D-88`: the code beside the id). */
const LINKED = { ...OFFER, deal_id: 'd3', deal_code: 'DL-2026-0003' };

/** Over `respond()`, with `offer` as the list's one row: the deal picker's search answers both deals. */
function withDealSearch(offer: Record<string, unknown> = OFFER): ReturnType<typeof vi.fn> {
    const base = respond([offer], 200, { ...offer, items: OFFER_DETAIL_LINES }) as unknown as typeof globalThis.fetch;

    return vi.fn(async (input: string, init?: RequestInit) => {
        if (new URL(String(input), 'http://localhost').pathname.endsWith('/deals')) {
            return json(200, envelope([DEAL, DEAL_7], { pagination: { ...PAGINATION, total: 2, per_page: 20 } }));
        }

        return base(input, init);
    });
}

function dealSearches(fetchMock: ReturnType<typeof vi.fn>): URL[] {
    return fetchMock.mock.calls
        .map((call) => new URL(String(call[0]), 'http://localhost'))
        .filter((url) => url.pathname.endsWith('/deals'));
}

/** F-24 · 1.1: the offer's deal is picked in a server search — open it, take the row with this code. */
async function pickDeal(view: Awaited<ReturnType<typeof render>>, code: string): Promise<void> {
    await view.get('[data-testid="supplier-quotation-form-deal-id"]').trigger('focus');
    await flushPromises();

    const option = view.findAll('[data-testid="supplier-quotation-form-deal-id-option"]').find((row) => row.text().includes(code));

    expect(option, `no «${code}» option`).toBeDefined();
    await option!.trigger('mousedown');
}

/** The body of the first call with this method. */
function sentBody(fetchMock: ReturnType<typeof vi.fn>, method: 'POST' | 'PATCH'): Record<string, unknown> {
    const call = fetchMock.mock.calls.find((c) => (c[1] as RequestInit | undefined)?.method === method);

    expect(call, `no ${method}`).toBeDefined();

    return JSON.parse(String((call![1] as RequestInit).body)) as Record<string, unknown>;
}

/** The methods of every non-GET call, in order — what the screen actually submitted. */
function writes(fetchMock: ReturnType<typeof vi.fn>): string[] {
    return fetchMock.mock.calls
        .map((call) => (call[1] as RequestInit | undefined)?.method ?? 'GET')
        .filter((method) => method !== 'GET');
}

/** How many times the *list* was asked for — not the detail, whose path is longer. */
function listReads(fetchMock: ReturnType<typeof vi.fn>): number {
    return fetchMock.mock.calls.filter((call) => {
        const url = String(call[0]).split('?')[0] ?? '';

        return url.endsWith('/supplier-quotations') && ((call[1] as RequestInit | undefined)?.method ?? 'GET') === 'GET';
    }).length;
}

function offersUrl(fetchMock: ReturnType<typeof vi.fn>): string {
    const call = [...fetchMock.mock.calls].reverse().find((c) => String(c[0]).includes('/supplier-quotations'));

    return String(call?.[0] ?? '');
}

describe('the supplier quotations screen', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('asks the server for the first page on arrival', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        expect(offersUrl(fetchMock)).toContain('/supplier-quotations');
        expect(wrapper.find('[data-testid="supplier-quotations-table"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="supplier-quotations-row"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('SQ-2026-0001');
    });

    /** The supplier is an id on the wire; the screen resolves it to §7.1's name. */
    it('shows the supplier by name, not by identifier', async () => {
        const wrapper = await render(respond());

        const cell = wrapper.find('[data-testid="supplier-quotations-supplier"]');

        expect(cell.text()).toBe('Alpha Supply');
        expect(cell.text()).not.toBe(SUPPLIER.id);
    });

    it('sends the supplier filter as the server declares it', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="supplier-quotations-filter-supplier"]').setValue('s1');
        await flushPromises();

        expect(offersUrl(fetchMock)).toContain('filter%5Bsupplier_id%5D=s1');
    });

    /** `D-88`: the box is a deal-code fragment, not the deal's UUID. */
    it('sends the deal box as filter[deal_code], never deal_id', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        const box = wrapper.find('[data-testid="supplier-quotations-filter-deal"]');

        // Its own key: a code fragment's example, not the form's deal picker (F-24 · 1.1).
        expect(box.attributes('placeholder')).toBe(en.supplierQuotations.filter.dealCodePlaceholder);

        await box.setValue('0003');
        await wrapper.find('[data-testid="supplier-quotations-filters"]').trigger('submit');
        await flushPromises();

        expect(offersUrl(fetchMock)).toContain('filter%5Bdeal_code%5D=0003');
        expect(offersUrl(fetchMock)).not.toContain('deal_id');
    });

    it('shows the deal code in the deal column, and the none text without one', async () => {
        const fetchMock = respond([
            { ...OFFER, id: 'q1', deal_id: 'd1', deal_code: 'DL-2026-0003' },
            { ...OFFER, id: 'q2', code: 'SQ-2026-0002' },
        ]);
        const wrapper = await render(fetchMock);

        const text = wrapper.text();

        expect(text).toContain('DL-2026-0003');
        expect(text).not.toContain('d1');
        expect(text).toContain(en.supplierQuotations.deal.none);
    });

    it('never sends a search parameter, because this list declares none', async () => {
        const fetchMock = respond();
        await render(fetchMock);

        expect(offersUrl(fetchMock)).not.toContain('q=');
        expect(offersUrl(fetchMock)).not.toContain('search');
    });

    /** `DEFAULT_SORT` is `offer_date` and `DEFAULT_SORT_DESCENDING` is true. */
    it('sorts by offer date, newest first, until told otherwise', async () => {
        const fetchMock = respond();
        const wrapper = await render(fetchMock);

        await wrapper.find('[data-testid="supplier-quotations-sort-offer_date"]').trigger('click');
        await flushPromises();

        expect(offersUrl(fetchMock)).toContain('sort=offer_date');
    });

    it('draws the empty state when the server returns no offers', async () => {
        const wrapper = await render(respond([]));

        expect(wrapper.find('[data-testid="supplier-quotations-table"]').exists()).toBe(false);
        expect(wrapper.text()).not.toBe('');
    });

    /** A 403 is a boundary, not an absence — drawing it as "no offers" would lie. */
    it('draws a refusal for a 403 rather than an empty list', async () => {
        const wrapper = await render(respond([], 403));

        expect(wrapper.find('[data-testid="supplier-quotations-table"]').exists()).toBe(false);
        expect(wrapper.html()).toContain('permission-denied');
    });

    /** §6.5: monetary values right-aligned with tabular numerals. */
    it('renders the total cut after the third decimal (D-82), never parsed', async () => {
        const wrapper = await render(respond());

        const cell = wrapper.find('[data-testid="supplier-quotations-total"]');

        expect(cell.text()).toBe('4500.000');
        expect(cell.classes()).toContain('tabular-nums');
    });
});

/**
 * Module 6, Point 6.3 — §7.2's header form, create and edit in one dialog.
 *
 * ── The total and the currency are a pair the form now carries ─────────────
 *
 * §7.2 lists both. Since D-80 `GET /currencies` is `currency.view` (the §3.6
 * create/edit set) and each row carries its `id`, so the dialog can offer a
 * closed select for `currency_id` and a text input for `total_price`. They are
 * one pair (`SaveSupplierQuotationRequest`'s mutual `required_with`, over
 * Point 1.1's `CHECK ((total_price IS NULL) = (currency_id IS NULL))`); the
 * form does not re-state that rule — a half-filled pair is the server's 422,
 * landing on the field it names.
 *
 * What the tests below pin: a create and an edit **always name both keys**
 * (`""` → `null`), so blanking them on an edit clears the offer's total on
 * purpose, and hydration re-sends what the offer already had. A currency list
 * that cannot be loaded is said, not hidden.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * `SupplierQuotationEndpointTest` withdraws the seeded grant and proves the
 * refusal (§3.12 rule 1, `SEC-09`). What is proved here is what the screen
 * *draws*: §6.2's "one primary action per context, drawn only for the
 * permission that can complete it".
 */
describe('the supplier quotation form', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    it('offers New offer and row Edit to a holder of create, and to the CEO neither', async () => {
        const permitted = await render(respond());

        expect(permitted.find('[data-testid="supplier-quotations-create"]').exists()).toBe(true);
        expect(permitted.find('[data-testid="supplier-quotations-row-edit"]').exists()).toBe(true);

        const refused = await render(respond(), CEO);

        expect(refused.find('[data-testid="supplier-quotations-create"]').exists()).toBe(false);
        expect(refused.find('[data-testid="supplier-quotations-row-edit"]').exists()).toBe(false);

        // §7.2's last criterion, on the screen: "**Shared screen — not
        // restricted by ownership**". A reader who may not write still sees
        // every row, so refusing the write controls must not empty the table.
        // The enforcement half is `SupplierQuotationListEndpointTest`'s, where
        // a reader is shown an offer the Manager entered.
        expect(refused.findAll('[data-testid="supplier-quotations-row"]')).toHaveLength(1);
    });

    it('opens the dialog empty for a create and filled for an edit', async () => {
        const view = await render(respond());

        expect(view.find('[data-testid="supplier-quotation-form-modal"]').exists()).toBe(false);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        expect((view.get('[data-testid="supplier-quotation-form-supplier-id"]').element as HTMLInputElement).value).toBe('');

        await view.find('[data-testid="supplier-quotation-form-cancel"]').trigger('click');
        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        // F-24 · 1.4: the field shows the supplier's name, not its id.
        expect((view.get('[data-testid="supplier-quotation-form-supplier-id"]').element as HTMLInputElement).value).toBe('Alpha Supply');
        expect((view.get('[data-testid="supplier-quotation-form-offer-date"]').element as HTMLInputElement).value).toBe('2026-09-01');
    });

    /** §7.2's `code` is "Automatic" and the boundary answers a supplied one with `prohibited`. */
    it('creates with §7.2 header fields, the total and its currency — no code', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await pickSupplier(view);
        await view.get('[data-testid="supplier-quotation-form-offer-date"]').setValue('2026-09-04');
        await view.get('[data-testid="supplier-quotation-form-notes"]').setValue('From the PDF');
        await view.get('[data-testid="supplier-quotation-form-total-price"]').setValue('4500');
        await view.get('[data-testid="supplier-quotation-form-currency-id"]').setValue('c1');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => (c[1] as RequestInit | undefined)?.method === 'POST');

        expect(call).toBeDefined();

        const sent = JSON.parse(String((call?.[1] as RequestInit).body)) as Record<string, unknown>;

        expect(sent).toEqual({
            supplier_id: 's1',
            deal_id: null,
            offer_date: '2026-09-04',
            valid_until: null,
            notes: 'From the PDF',
            // A string, never a number: DB-07 through JSON.
            total_price: '4500',
            currency_id: 'c1',
            // Point 6.4: a create always states its line set, and `forCreate()`
            // folds absent and `[]` together anyway. Still no `code`.
            items: [],
        });
    });

    it('sends the pair as null when both are left blank', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await pickSupplier(view);
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => (c[1] as RequestInit | undefined)?.method === 'POST');
        const sent = JSON.parse(String((call?.[1] as RequestInit).body)) as Record<string, unknown>;

        expect(sent.total_price).toBeNull();
        expect(sent.currency_id).toBeNull();
    });

    /**
     * An edit hydrates the pair from the offer and sends it back as it stands,
     * so a PATCH that only touched the notes leaves the total exactly as it
     * was — by re-stating it, not by omitting it.
     */
    it('edits with the offer\'s total and currency hydrated and re-sent', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        expect((view.get('[data-testid="supplier-quotation-form-total-price"]').element as HTMLInputElement).value).toBe('4500.000000');
        expect((view.get('[data-testid="supplier-quotation-form-currency-id"]').element as HTMLSelectElement).value).toBe('c1');

        await view.get('[data-testid="supplier-quotation-form-notes"]').setValue('Revised');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => (c[1] as RequestInit | undefined)?.method === 'PATCH');

        expect(String(call?.[0])).toContain('/supplier-quotations/q1');

        const sent = JSON.parse(String((call?.[1] as RequestInit).body)) as Record<string, unknown>;

        expect(sent.total_price).toBe('4500.000000');
        expect(sent.currency_id).toBe('c1');
        expect(sent.notes).toBe('Revised');
    });

    /** The list not loading is said where the select is, and the rest of the form still works. */
    it('says so when the currency list cannot be loaded, instead of a silent empty select', async () => {
        const view = await render(respond([OFFER], 200, { ...OFFER, items: OFFER_DETAIL_LINES }, 'clean', 403));

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="supplier-quotation-form-total-unavailable"]').exists()).toBe(true);
        expect(view.get('[data-testid="supplier-quotation-form-currency-id"]').findAll('option')).toHaveLength(1);

        const loaded = await render(respond());

        await loaded.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();

        expect(loaded.find('[data-testid="supplier-quotation-form-total-unavailable"]').exists()).toBe(false);
        expect(loaded.get('[data-testid="supplier-quotation-form-currency-id"]').findAll('option')).toHaveLength(2);
    });

    /** `OpenAPI §5` — `details[]` names the field, so the sentence lands on the control that caused it. */
    it('shows a field refusal against the field the server named', async () => {
        const fetchMock = vi.fn(async (input: string, init?: RequestInit) => {
            if (String(input).includes('/suppliers')) {
                return json(200, envelope([SUPPLIER], { pagination: { ...PAGINATION, per_page: 100 } }));
            }

            if (init?.method === 'POST') {
                return json(422, {
                    error: {
                        code: 'validation_failed',
                        message: 'no',
                        details: [{ field: 'supplier_id', message: 'The selected supplier is archived.' }],
                    },
                    meta: { request_id: 'r1' },
                });
            }

            return json(200, envelope([OFFER], { pagination: PAGINATION }));
        });

        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await pickSupplier(view);
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(view.get('[data-testid="supplier-quotation-form-supplier-id-error"]').text())
            .toBe('The selected supplier is archived.');
        expect(view.find('[data-testid="supplier-quotation-form-modal"]').exists()).toBe(true);
    });

    /** A courtesy check, not the rule: the boundary requires `supplier_id` on a POST regardless (`D-67`). */
    it('refuses a create with no supplier without asking the server', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        // Counting *every* call would now count Point 6.4's catalog read, which
        // opening the dialog legitimately makes. What this test claims is that
        // nothing was **submitted**.
        expect(writes(fetchMock)).toEqual([]);
        expect(view.get('[data-testid="supplier-quotation-form-supplier-id-error"]').text())
            .toBe(en.supplierQuotations.form.supplierRequired);
    });

    // ── F-24 · 1.4: the supplier is searched on the server, not picked from
    // the list screen's first 100 (debt row "The supplier-quotations screen
    // reads only the first 100 suppliers"; Design System §6.3).

    it('searches the server for the offer\'s supplier as the user types', async () => {
        const fetchMock = withSupplierSearch();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();

        const field = view.get('[data-testid="supplier-quotation-form-supplier-id"]');
        await field.trigger('focus');
        await flushPromises();

        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        await field.setValue('Bet');
        vi.advanceTimersByTime(300);
        vi.useRealTimers();
        await flushPromises();

        expect(supplierSearches(fetchMock).at(-1)?.searchParams.get('q')).toBe('Bet');
    });

    it('offers and saves a supplier the list screen never loaded', async () => {
        const fetchMock = withSupplierSearch();
        const view = await render(fetchMock);

        // The list screen's own load holds Alpha alone.
        expect(view.get('[data-testid="supplier-quotations-filter-supplier"]').text()).not.toContain('Beta Trading');

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await pickSupplier(view, 'Beta Trading');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(sentBody(fetchMock, 'POST').supplier_id).toBe('s2');
    });

    it('reads the offer\'s supplier from the server when editing', async () => {
        const fetchMock = withSupplierSearch();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some((call) => new URL(String(call[0]), 'http://localhost').pathname.endsWith('/suppliers/s1'))).toBe(true);
        expect((view.get('[data-testid="supplier-quotation-form-supplier-id"]').element as HTMLInputElement).value).toBe('Alpha Supply');
    });

    it('falls back to the id when the supplier cannot be read, and still saves', async () => {
        const fetchMock = withSupplierSearch(500);
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some((call) => new URL(String(call[0]), 'http://localhost').pathname.endsWith('/suppliers/s1'))).toBe(true);
        expect((view.get('[data-testid="supplier-quotation-form-supplier-id"]').element as HTMLInputElement).value).toBe('s1');

        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(sentBody(fetchMock, 'PATCH').supplier_id).toBe('s1');
    });

    it('lets an edit change the supplier', async () => {
        const fetchMock = withSupplierSearch();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();
        await pickSupplier(view, 'Beta Trading');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(sentBody(fetchMock, 'PATCH').supplier_id).toBe('s2');
    });

    it('marks the offer\'s own supplier as the selected option', async () => {
        const view = await render(withSupplierSearch());

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();
        await view.get('[data-testid="supplier-quotation-form-supplier-id"]').trigger('focus');
        await flushPromises();

        const selected = view.findAll('[data-testid="supplier-quotation-form-supplier-id-option"]')
            .filter((row) => row.attributes('aria-selected') === 'true')
            .map((row) => row.text());

        expect(selected).toEqual(['Alpha Supply']);
    });

    /** The edit's name read is slow; a pick made meanwhile must not be overwritten by it. */
    it('keeps a supplier picked while the edit\'s read is still running', async () => {
        let release = (): void => {};
        const gate = new Promise<void>((resolve) => { release = resolve; });
        const search = withSupplierSearch() as unknown as typeof globalThis.fetch;
        const fetchMock = vi.fn(async (input: string, init?: RequestInit) => {
            if (/\/suppliers\/s1$/.test(String(input))) {
                await gate;
            }

            return search(input, init);
        });
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();
        await pickSupplier(view, 'Beta Trading');
        release();
        await flushPromises();

        expect((view.get('[data-testid="supplier-quotation-form-supplier-id"]').element as HTMLInputElement).value).toBe('Beta Trading');
    });

    /** §6.3: "show inactive/deactivated selection warnings when the CRM permits use" — it does (`alive()`). */
    it('marks a deactivated supplier among the options', async () => {
        const view = await render(withSupplierSearch());

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await view.get('[data-testid="supplier-quotation-form-supplier-id"]').trigger('focus');
        await flushPromises();

        const beta = view.findAll('[data-testid="supplier-quotation-form-supplier-id-option"]').find((row) => row.text().includes('Beta Trading'));

        expect(beta, 'no «Beta Trading» option').toBeDefined();

        const detail = beta!.get('[data-testid="supplier-quotation-form-supplier-id-option-detail"]').text();

        expect(detail).toContain('Mona');
        expect(detail).toContain(en.suppliers.status.inactive);
    });

    /**
     * `rtl-ui-verifier`, 2026-09-28: in English, «يوسف · 01287436897» drew as
     * "01287436897 · يوسف" — digits after Arabic letters turn Arabic-number and
     * pull the separator into one right-to-left run (UAX #9 W2, N1). Each part
     * in its own isolate (U+2068 … U+2069) keeps its own direction.
     */
    it('isolates each part of an option\'s detail, so a phone keeps its place beside an Arabic contact', async () => {
        const view = await render(withSupplierSearch());

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await view.get('[data-testid="supplier-quotation-form-supplier-id"]').trigger('focus');
        await flushPromises();

        const beta = view.findAll('[data-testid="supplier-quotation-form-supplier-id-option"]').find((row) => row.text().includes('Beta Trading'));

        expect(beta!.get('[data-testid="supplier-quotation-form-supplier-id-option-detail"]').text())
            .toBe('\u2068Mona\u2069 · \u20680100\u2069 · \u2068Inactive\u2069');
    });

    // ── F-24 · 1.1: the deal is picked by its `DL-…` code, not pasted as a
    // UUID (debt row "The supplier-offer form's deal field still takes a raw
    // UUID"; D-88 left the picker out). The wire still carries `deal_id`.

    it('searches the server for the offer\'s deal as the user types', async () => {
        const fetchMock = withDealSearch();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();

        const field = view.get('[data-testid="supplier-quotation-form-deal-id"]');
        await field.trigger('focus');
        await flushPromises();

        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        await field.setValue('0007');
        vi.advanceTimersByTime(300);
        vi.useRealTimers();
        await flushPromises();

        expect(dealSearches(fetchMock).at(-1)?.searchParams.get('q')).toBe('0007');
    });

    it('saves the picked deal\'s id and shows its code', async () => {
        const fetchMock = withDealSearch();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await pickSupplier(view);
        await pickDeal(view, 'DL-2026-0007');

        expect((view.get('[data-testid="supplier-quotation-form-deal-id"]').element as HTMLInputElement).value).toBe('DL-2026-0007');

        // Picked again: the shown code did not change, so the box keeps the
        // pick's own text — which must be the code too, not the id.
        await pickDeal(view, 'DL-2026-0007');

        expect((view.get('[data-testid="supplier-quotation-form-deal-id"]').element as HTMLInputElement).value).toBe('DL-2026-0007');

        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        // The id, never the code: `SaveSupplierQuotationRequest` wants a uuid.
        expect(sentBody(fetchMock, 'POST').deal_id).toBe('d7');
    });

    /** D-88: the offer already carries its deal's code, so opening the edit asks for nothing more. */
    it('shows an edited offer\'s deal by its code and keeps it', async () => {
        const fetchMock = withDealSearch(LINKED);
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        expect((view.get('[data-testid="supplier-quotation-form-deal-id"]').element as HTMLInputElement).value).toBe('DL-2026-0003');
        expect(dealSearches(fetchMock)).toHaveLength(0);

        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(sentBody(fetchMock, 'PATCH').deal_id).toBe('d3');
    });

    /** D-51: the link is optional, so the picker must be able to take it away. */
    it('unlinks the deal with No deal', async () => {
        const fetchMock = withDealSearch(LINKED);
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();
        await view.get('[data-testid="supplier-quotation-form-deal-id"]').trigger('focus');
        await flushPromises();

        const none = view.get('[data-testid="supplier-quotation-form-deal-id-all"]');

        expect(none.text()).toBe(en.deals.picker.none);
        await none.trigger('mousedown');

        expect((view.get('[data-testid="supplier-quotation-form-deal-id"]').element as HTMLInputElement).value).toBe('');

        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(sentBody(fetchMock, 'PATCH').deal_id).toBeNull();
    });

    /** A code alone tells nobody which deal it is; each part isolated, as the supplier's detail (2026-09-28). */
    it('describes each deal option by its title and customer', async () => {
        const view = await render(withDealSearch());

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await view.get('[data-testid="supplier-quotation-form-deal-id"]').trigger('focus');
        await flushPromises();

        const detail = (code: string): string => {
            const row = view.findAll('[data-testid="supplier-quotation-form-deal-id-option"]').find((option) => option.text().includes(code));

            expect(row, `no «${code}» option`).toBeDefined();

            return row!.get('[data-testid="supplier-quotation-form-deal-id-option-detail"]').text();
        };

        expect(detail('DL-2026-0007')).toBe('⁨تمديد كابلات⁩ · ⁨فندق النيل⁩');
        // No title: the customer alone, no stray separator.
        expect(detail('DL-2026-0003')).toBe('⁨Nile Hotels⁩');
    });

    /**
     * D-88: `deal_code` is null when the offer's deal was soft-deleted. The id
     * shows — D-83's fallback — and an untouched field saves it unchanged.
     * A guard: the free-text box already behaves so; the picker must not lose it.
     */
    it('falls back to the id when the offer\'s deal has no code, and still saves', async () => {
        const fetchMock = withDealSearch({ ...OFFER, deal_id: 'd9', deal_code: null });
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        expect((view.get('[data-testid="supplier-quotation-form-deal-id"]').element as HTMLInputElement).value).toBe('d9');

        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(sentBody(fetchMock, 'PATCH').deal_id).toBe('d9');
    });

    /** §5.2, §6.5: a saved offer may not belong on the page in view, so the list is asked again. */
    it('closes the dialog and asks the server again once an offer is saved', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);
        const listsBefore = listReads(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();
        await view.get('[data-testid="supplier-quotation-form-notes"]').setValue('Revised');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="supplier-quotation-form-modal"]').exists()).toBe(false);
        // Exactly one write, and the list asked again after it. Counting raw
        // calls would now also count the detail read the edit makes for its
        // lines, which is a different claim.
        expect(writes(fetchMock)).toEqual(['PATCH']);
        expect(listReads(fetchMock)).toBe(listsBefore + 1);
    });
});

/**
 * Module 6, Point 6.4 — §7.2's `Line items` row, "Product · **price** ·
 * quantity (+ to add more)".
 *
 * ── `D-22`: by id **or** by name, and the control makes "both" impossible ──
 *
 * `D-22` — "New products are **added to the catalog automatically, without
 * review**" — is why a line may name a product the catalog does not have.
 * `SaveSupplierQuotationRequest` carries `required_without` on both sides and
 * `prohibits` on the id, so a line sending the pair is a 422. The editor does
 * not *validate* that rule, it makes it unreachable: one control chooses either
 * a catalog item or "type a name instead", and only the chosen key is sent.
 *
 * ── The picker searches the server: active items, the supplier's first ────
 *
 * §10.4's table: a deactivated product is "**Hidden** from selection lists" for
 * new quotations while staying functional on open ones. F-18 · 1.2 (`D-93`):
 * nothing is loaded when the form opens — the product box asks the server
 * when it is opened, active items only, the offer's supplier's items first.
 * The tests read the URL.
 *
 * ── The dangerous case, and the reason it has its own test ────────────────
 *
 * An edit's lines come from `GET /supplier-quotations/{id}` — the list summary
 * has none (`SupplierQuotationPayload::many()` calls `of()`, not `detail()`).
 * `items` is three-valued on a `PATCH`: absent leaves the lines alone, `[]`
 * clears them, a list replaces them. So if that read **fails**, submitting the
 * editor's empty set would erase every line on the offer. The form omits the
 * key entirely instead, and says why.
 */
describe('the supplier quotation line editor', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    /** §10.4: "New quotations — **Hidden** from selection lists"; E1-5: no 100-item page. */
    it('asks the catalog nothing until a product box opens, then active items, the supplier\'s first', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);
        const catalogCalls = (): string[] => fetchMock.mock.calls.map((c) => String(c[0])).filter((url) => url.includes('/catalog-items'));

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        expect(catalogCalls()).toEqual([]);

        await pickSupplier(view);
        await view.get('[data-testid="supplier-quotation-form-add-line"]').trigger('click');
        await pickProduct(view, 0);

        const url = new URL(catalogCalls()[0] ?? '', 'http://localhost');
        expect(url.searchParams.get('filter[is_active]')).toBe('true');
        expect(url.searchParams.get('supplier_first')).toBe('s1');
        expect(url.searchParams.get('per_page')).toBe('20');
    });

    it('adds a line and sends the catalog id, never a name beside it', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await pickSupplier(view);
        await view.get('[data-testid="supplier-quotation-form-add-line"]').trigger('click');

        await pickProduct(view, 0);
        await view.get('[data-testid="supplier-quotation-line-0-unit-price"]').setValue('1500.000000');
        await view.get('[data-testid="supplier-quotation-line-0-quantity"]').setValue('3');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => (c[1] as RequestInit | undefined)?.method === 'POST');
        const sent = JSON.parse(String((call?.[1] as RequestInit).body)) as Record<string, unknown>;

        expect(sent.items).toEqual([{ catalog_item_id: 'ci1', unit_price: '1500.000000', quantity: '3' }]);
    });

    /** `D-22`'s other half: a product the catalog does not have is named, and the server adds it. */
    it('sends a typed product name instead of an id, and never both', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await pickSupplier(view);
        await view.get('[data-testid="supplier-quotation-form-add-line"]').trigger('click');

        // An item first, then "type a name instead", which brings the box back.
        await pickProduct(view, 0);
        expect(view.find('[data-testid="supplier-quotation-line-0-product-name"]').exists()).toBe(false);
        await chooseTypedName(view, 0);
        await view.get('[data-testid="supplier-quotation-line-0-product-name"]').setValue('Breaker 63A');
        await view.get('[data-testid="supplier-quotation-line-0-unit-price"]').setValue('90');
        await view.get('[data-testid="supplier-quotation-line-0-quantity"]').setValue('12');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => (c[1] as RequestInit | undefined)?.method === 'POST');
        const sent = JSON.parse(String((call?.[1] as RequestInit).body)) as Record<string, unknown>;

        expect(sent.items).toEqual([{ product_name: 'Breaker 63A', unit_price: '90', quantity: '12' }]);
    });

    /**
     * F-24 · 1.5: beside price and quantity the product had 161 px of text at
     * 1280 px, too little for "ATEN Enterprise Solutions" (173 px); on its own
     * row it has 478 px. A fresh line holds no item, so its name box shows too.
     */
    it('gives a line’s product the whole line, and its typed name too, with price and quantity below them', async () => {
        const view = await render(respond());

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await pickSupplier(view);
        await view.get('[data-testid="supplier-quotation-form-add-line"]').trigger('click');

        expect(view.get('label[for="supplier-quotation-line-0-product"]').classes()).toContain('basis-full');
        expect(view.get('label[for="supplier-quotation-line-0-product-name"]').classes()).toContain('basis-full');
    });

    /** F-24 · 1.5: a one-line input scrolls a long name out of view as it is typed. */
    it('lets a typed product name wrap and grow as it is typed, instead of scrolling out of view', async () => {
        const view = await render(respond());

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await pickSupplier(view);
        await view.get('[data-testid="supplier-quotation-form-add-line"]').trigger('click');

        const name = view.get('[data-testid="supplier-quotation-line-0-product-name"]');

        expect(name.element.tagName).toBe('TEXTAREA');
        expect(name.classes()).toContain('field-sizing-content');
    });

    /**
     * F-24 · 1.5: the box that wraps must not carry a line break into a product
     * name — an `<input>` strips one; the server's `string|max:255` would keep it.
     */
    it('never sends a line break in a typed product name: Enter adds none, and a pasted one becomes a space', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await pickSupplier(view);
        await view.get('[data-testid="supplier-quotation-form-add-line"]').trigger('click');

        const name = view.get('[data-testid="supplier-quotation-line-0-product-name"]');
        const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });

        name.element.dispatchEvent(enter);
        expect(enter.defaultPrevented).toBe(true);

        await name.setValue('ATEN Enterprise \r\n Solutions\nRack');
        await view.get('[data-testid="supplier-quotation-line-0-unit-price"]').setValue('90');
        await view.get('[data-testid="supplier-quotation-line-0-quantity"]').setValue('12');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(sentBody(fetchMock, 'POST').items)
            .toEqual([{ product_name: 'ATEN Enterprise Solutions Rack', unit_price: '90', quantity: '12' }]);
    });

    /**
     * F-24 · 1.5, the owner's option C: the picked item's name stays one line in
     * its field, so the whole of it wraps underneath. The field already holds the
     * name for a screen reader, so the copy is hidden from one.
     */
    it('shows a picked product’s whole name under the field, and nothing once a name is typed instead', async () => {
        // The longest active name in the dev catalog, 203 characters.
        const long = 'Barcode Reader · Label Printers · Receipt Printers · All in one POS System · Touch Monitor · Cash Drawers · Mobile Computer · Customer Display · Accessories — Birch /Datalogic /SPRT /Epson/GSAN/ZEBRA/TSC';
        const base = respond() as unknown as typeof globalThis.fetch;
        const fetchMock = vi.fn(async (input: string, init?: RequestInit) => (String(input).includes('/catalog-items')
            ? json(200, envelope([{ ...CATALOG_ITEM, name: long }], { pagination: { ...PAGINATION, per_page: 20 } }))
            : base(input, init)));
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await pickSupplier(view);
        await view.get('[data-testid="supplier-quotation-form-add-line"]').trigger('click');

        expect(view.find('[data-testid="supplier-quotation-line-0-product-full"]').exists()).toBe(false);

        await pickProduct(view, 0);

        const full = view.find('[data-testid="supplier-quotation-line-0-product-full"]');
        expect(full.exists(), 'no whole-name line under the picked product').toBe(true);
        expect(full.text()).toBe(long);
        expect(full.attributes('aria-hidden')).toBe('true');

        await chooseTypedName(view, 0);

        expect(view.find('[data-testid="supplier-quotation-line-0-product-full"]').exists()).toBe(false);
    });

    /** F-24 · 1.5: the whole name follows the line's item, not the act of picking it. */
    it('shows an edited line’s whole product name under its field too', async () => {
        const view = await render(respond());

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        const full = view.find('[data-testid="supplier-quotation-line-0-product-full"]');
        expect(full.exists(), 'no whole-name line under the edited product').toBe(true);
        expect(full.text()).toBe('Cable 2.5mm');
    });

    it('loads an offer’s existing lines on edit and sends them back', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        expect((view.get('[data-testid="supplier-quotation-line-0-unit-price"]').element as HTMLInputElement).value)
            .toBe('1500.000000');
        // D-93 (F-18 · 1.2): the name the detail sent, not a page of 100 searched for the id.
        expect((view.get('[data-testid="supplier-quotation-line-0-product"]').element as HTMLInputElement).value)
            .toBe('Cable 2.5mm');

        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => (c[1] as RequestInit | undefined)?.method === 'PATCH');
        const sent = JSON.parse(String((call?.[1] as RequestInit).body)) as Record<string, unknown>;

        expect(sent.items).toEqual(OFFER_LINES);
    });

    /** F-05 (D-81): the roles that may open an offer see all three figures; a new offer has none. */
    it('shows recorded, consumed and available per line when editing an offer, and nothing on a new one', async () => {
        const view = await render(respond());

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        const balance = view.get('[data-testid="supplier-quotation-line-0-balance"]').text();
        // D-82: the three figures are cut after the third decimal; the input above keeps '3.000' digit for digit.
        expect(balance).toContain('3.000');
        expect(balance).toContain('1.000');
        expect(balance).toContain('2.000');
        expect(balance).not.toContain('1.0000');

        await view.find('[data-testid="supplier-quotation-form-cancel"]').trigger('click');
        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await view.get('[data-testid="supplier-quotation-form-add-line"]').trigger('click');

        expect(view.find('[data-testid="supplier-quotation-line-0-quantity"]').exists()).toBe(true);
        expect(view.find('[data-testid="supplier-quotation-line-0-balance"]').exists()).toBe(false);
    });

    /** `[]` is the documented "clear them" case, and it is a different answer from absence. */
    it('sends an empty list when every line is removed from an edit', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();
        await view.get('[data-testid="supplier-quotation-line-0-remove"]').trigger('click');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => (c[1] as RequestInit | undefined)?.method === 'PATCH');
        const sent = JSON.parse(String((call?.[1] as RequestInit).body)) as Record<string, unknown>;

        expect(sent.items).toEqual([]);
    });

    /**
     * The guard. A failed detail read must not become "this offer has no
     * lines": `items` is omitted, so `SupplierQuotationDraft::forUpdate()`
     * leaves the set alone.
     */
    it('omits items entirely when the offer’s lines could not be read', async () => {
        const fetchMock = vi.fn(async (input: string, init?: RequestInit) => {
            if (String(input).includes('/suppliers')) {
                return json(200, envelope([SUPPLIER], { pagination: { ...PAGINATION, per_page: 100 } }));
            }

            if (String(input).includes('/catalog-items')) {
                return json(200, envelope([CATALOG_ITEM], { pagination: { ...PAGINATION, per_page: 100 } }));
            }

            if (isDetailRead(String(input), init)) {
                return json(500, { error: { code: 'server_error', message: 'no' }, meta: { request_id: 'r1' } });
            }

            return json(200, envelope([OFFER], { pagination: PAGINATION }));
        });

        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="supplier-quotation-form-lines-unavailable"]').exists()).toBe(true);
        expect(view.find('[data-testid="supplier-quotation-form-add-line"]').exists()).toBe(false);

        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => (c[1] as RequestInit | undefined)?.method === 'PATCH');
        const sent = JSON.parse(String((call?.[1] as RequestInit).body)) as Record<string, unknown>;

        expect(Object.keys(sent)).not.toContain('items');
    });

    /**
     * The Module Completion Checklist asks for loading and empty states, and
     * these two only exist inside this editor: a create opens with no lines,
     * and an edit shows the detail read in flight before its lines arrive.
     */
    it('draws its own empty and loading states', async () => {
        const view = await render(respond());

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="supplier-quotation-form-lines-none"]').exists()).toBe(true);

        await view.find('[data-testid="supplier-quotation-form-cancel"]').trigger('click');

        // Deliberately **not** flushed: this is the frame between opening the
        // dialog and the detail read resolving.
        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');

        expect(view.find('[data-testid="supplier-quotation-form-lines-loading"]').exists()).toBe(true);
        expect(view.find('[data-testid="supplier-quotation-form-add-line"]').exists()).toBe(false);

        await flushPromises();

        expect(view.find('[data-testid="supplier-quotation-form-lines-loading"]').exists()).toBe(false);
    });

    /** Laravel keys a nested failure `items.0.unit_price`, and `ApiExceptionRenderer::validation()` passes the key through. */
    it('shows a line refusal against the line the server named', async () => {
        const fetchMock = vi.fn(async (input: string, init?: RequestInit) => {
            if (String(input).includes('/suppliers')) {
                return json(200, envelope([SUPPLIER], { pagination: { ...PAGINATION, per_page: 100 } }));
            }

            if (String(input).includes('/catalog-items')) {
                return json(200, envelope([CATALOG_ITEM], { pagination: { ...PAGINATION, per_page: 100 } }));
            }

            if (init?.method === 'POST') {
                return json(422, {
                    error: {
                        code: 'validation_failed',
                        message: 'no',
                        details: [
                            { field: 'items.0.catalog_item_id', message: 'That product is archived.' },
                            { field: 'items.0.unit_price', message: 'The price may not be negative.' },
                            { field: 'items.0.quantity', message: 'The quantity must be above zero.' },
                        ],
                    },
                    meta: { request_id: 'r1' },
                });
            }

            return json(200, envelope([OFFER], { pagination: PAGINATION }));
        });

        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await pickSupplier(view);
        await view.get('[data-testid="supplier-quotation-form-add-line"]').trigger('click');
        await view.get('[data-testid="supplier-quotation-line-0-unit-price"]').setValue('-1');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(view.get('[data-testid="supplier-quotation-line-0-product-error"]').text())
            .toBe('That product is archived.');
        expect(view.get('[data-testid="supplier-quotation-line-0-unit-price-error"]').text())
            .toBe('The price may not be negative.');
        expect(view.get('[data-testid="supplier-quotation-line-0-quantity-error"]').text())
            .toBe('The quantity must be above zero.');
        // A named field means no generic banner — the sentence is already on the control.
        expect(view.find('[data-testid="supplier-quotation-form-error"]').exists()).toBe(false);
    });
});

/**
 * Module 6, Point 6.5 — §7.2's `pdf_file` row, "Scan or PDF of the offer".
 *
 * ── A third grant, and it is not `create` ──────────────────────────────────
 *
 * §3.6 seeds `upload_attachment` beside `view` and `create / edit`, and
 * `POST /supplier-quotations/{id}/documents` carries it. `PermissionMatrix`
 * grants it to the same five roles as `create` **today**, which is why the
 * negative case below is a fixture holding `create` without it rather than the
 * CEO: RBAC is database-backed and dynamic (`SEC-07`), the role screen can
 * revoke one row and not the other, and a control keyed on `create` would
 * survive that revocation.
 *
 * ── The download is a fetch, never a link ──────────────────────────────────
 *
 * `GET /files/{id}/download` authorises through the parent (`D-38`) and the
 * credential is an `Authorization` header (`D-74`), so an `<a href>` to it is a
 * 401. `downloadFile()` is the helper, proved in `services/files.spec.ts`.
 *
 * A file whose scan is not `clean` is refused by `DownloadFile::forActor()`
 * before permission is even considered (`SEC-15`), and answered **404**. So a
 * download control for a `pending` or an `infected` file would be a control
 * that always fails, and there is none.
 *
 * ⚠️ **Stated ceiling, owner's ruling of 2026-09-05.** This panel lists what
 * was attached **in this dialog**, and nothing else. `SupplierQuotationPayload`
 * `::detail()` returns the header and `items`; the module publishes
 * `uploadDocument` and no read, and `grep -c "/files" routes/api.php` is `1`.
 * Nothing in the API can be asked which files an offer has. On the register.
 */
describe('the supplier quotation attachments', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
    });

    function attach(view: Awaited<ReturnType<typeof render>>, name = 'offer.pdf'): Promise<void> {
        const input = view.get('[data-testid="supplier-quotation-form-attachment-file"]').element as HTMLInputElement;

        Object.defineProperty(input, 'files', { configurable: true, value: [new File(['%PDF-1.4'], name, { type: 'application/pdf' })] });

        return view.get('[data-testid="supplier-quotation-form-attachment-file"]').trigger('change');
    }

    /** The route needs an offer id, and a create has none until it is saved. */
    it('offers no upload on a create, and says why', async () => {
        const view = await render(respond());

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="supplier-quotation-form-attachment-file"]').exists()).toBe(false);
        expect(view.find('[data-testid="supplier-quotation-form-attachments-save-first"]').exists()).toBe(true);
    });

    it('posts the file to the offer’s documents route under the field name the server reads', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();
        await attach(view);
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => String(c[0]).includes('/documents'));

        expect(String(call?.[0])).toBe('/api/v1/supplier-quotations/q1/documents');

        const body = (call?.[1] as RequestInit).body as FormData;

        expect(body).toBeInstanceOf(FormData);
        expect((body.get('document') as File).name).toBe('offer.pdf');
    });

    it('offers a download for a clean file, and fetches §17’s route when it is used', async () => {
        vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:t'), revokeObjectURL: vi.fn() }));
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);

        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();
        await attach(view);
        await flushPromises();

        expect(view.get('[data-testid="supplier-quotation-attachment-0-name"]').text()).toBe('offer.pdf');

        await view.get('[data-testid="supplier-quotation-attachment-0-download"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some((c) => String(c[0]) === '/api/v1/files/d1/download')).toBe(true);
    });

    /** `SEC-15`: not clean is not servable, and the endpoint answers 404 — so no control is drawn. */
    it.each([
        ['pending', 'supplier-quotation-attachment-0-pending'],
        ['infected', 'supplier-quotation-attachment-0-infected'],
    ])('draws the %s state and no download control', async (status, testid) => {
        const fetchMock = respond(undefined, 200, undefined, status);
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();
        await attach(view);
        await flushPromises();

        expect(view.find(`[data-testid="${testid}"]`).exists()).toBe(true);
        expect(view.find('[data-testid="supplier-quotation-attachment-0-download"]').exists()).toBe(false);
    });

    it('draws a refusal when the upload is forbidden', async () => {
        const fetchMock = vi.fn(async (input: string, init?: RequestInit) => {
            if (String(input).includes('/suppliers')) {
                return json(200, envelope([SUPPLIER], { pagination: { ...PAGINATION, per_page: 100 } }));
            }

            if (String(input).includes('/catalog-items')) {
                return json(200, envelope([CATALOG_ITEM], { pagination: { ...PAGINATION, per_page: 100 } }));
            }

            if (String(input).includes('/documents')) {
                return json(403, { error: { code: 'forbidden', message: 'no' }, meta: { request_id: 'r1' } });
            }

            if (isDetailRead(String(input), init)) {
                return json(200, envelope({ ...OFFER, items: OFFER_LINES }));
            }

            return json(200, envelope([OFFER], { pagination: PAGINATION }));
        });

        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();
        await attach(view);
        await flushPromises();

        expect(view.find('[data-testid="supplier-quotation-form-attachment-error"]').exists()).toBe(true);
        expect(view.find('[data-testid="supplier-quotation-attachment-0-name"]').exists()).toBe(false);
    });

    /** §3.6's third row, revoked while `create` stands — the case a `create`-keyed control would miss. */
    it('draws no upload control for a writer whose upload_attachment was revoked', async () => {
        const view = await render(respond(), WRITER_WITHOUT_UPLOAD);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="supplier-quotation-form-attachment-file"]').exists()).toBe(false);
    });

    /** The panel is honest about what it cannot know — see the ceiling above. */
    it('says that only this session’s attachments are listed', async () => {
        const view = await render(respond());

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="supplier-quotation-form-attachments-ceiling"]').exists()).toBe(true);
    });
});
