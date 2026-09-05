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
 * The declared surface is `SupplierQuotationListCriteria`'s: **two** filters
 * (`supplier_id`, `deal_id`), two sorts (`offer_date`, `created_at`),
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
    total_price: '4500.000000',
    currency_id: 'c1',
    offer_date: '2026-09-01',
    valid_until: null,
    notes: null,
};

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
    detail: unknown = { ...OFFER, items: OFFER_LINES },
    scanStatus = 'clean',
): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string, init?: RequestInit) => {
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
    it('renders the total as the server sent it, digit for digit', async () => {
        const wrapper = await render(respond());

        const cell = wrapper.find('[data-testid="supplier-quotations-total"]');

        expect(cell.text()).toBe('4500.000000');
        expect(cell.classes()).toContain('tabular-nums');
    });
});

/**
 * Module 6, Point 6.3 — §7.2's header form, create and edit in one dialog.
 *
 * ── The total and the currency are **not** here, and that is measured ──────
 *
 * §7.2 lists both, and this form carries neither. `currency_id` is a UUID the
 * SPA cannot obtain: `CurrencyController::payload()` publishes `code`,
 * `rounding_unit`, `rounding_enabled` and `is_base` and **no `id`**, and
 * `GET /currencies` sits behind `admin.system_settings`, which
 * `PermissionMatrix` grants to the Super Admin alone — so every role holding
 * `supplier_quotation.create` is refused the lookup as well. The two columns
 * are a pair (`SaveSupplierQuotationRequest`'s mutual `required_with`, over
 * Point 1.1's `CHECK ((total_price IS NULL) = (currency_id IS NULL))`), so
 * neither can be sent alone. Owner's ruling of 2026-09-05: ship the rest with
 * the ceiling stated. The pair returns in a later point.
 *
 * The consequence the tests below pin: an **edit never mentions either key**,
 * so `SupplierQuotationDraft::only()`'s `array_key_exists` leaves both columns
 * exactly as they were rather than erasing a total the form cannot show.
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
    });

    it('opens the dialog empty for a create and filled for an edit', async () => {
        const view = await render(respond());

        expect(view.find('[data-testid="supplier-quotation-form-modal"]').exists()).toBe(false);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        expect((view.get('[data-testid="supplier-quotation-form-supplier-id"]').element as HTMLSelectElement).value).toBe('');

        await view.find('[data-testid="supplier-quotation-form-cancel"]').trigger('click');
        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');

        expect((view.get('[data-testid="supplier-quotation-form-supplier-id"]').element as HTMLSelectElement).value).toBe('s1');
        expect((view.get('[data-testid="supplier-quotation-form-offer-date"]').element as HTMLInputElement).value).toBe('2026-09-01');
    });

    /** §7.2's `code` is "Automatic" and the boundary answers a supplied one with `prohibited`. */
    it('creates with §7.2 header fields only — no code, no total, no currency', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await view.get('[data-testid="supplier-quotation-form-supplier-id"]').setValue('s1');
        await view.get('[data-testid="supplier-quotation-form-offer-date"]').setValue('2026-09-04');
        await view.get('[data-testid="supplier-quotation-form-notes"]').setValue('From the PDF');
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
            // Point 6.4: a create always states its line set, and `forCreate()`
            // folds absent and `[]` together anyway. Still no `code`, no
            // `total_price`, no `currency_id`.
            items: [],
        });
    });

    /**
     * The pair is absent from the body, not null in it: `array_key_exists` in
     * `SupplierQuotationDraft::only()` is what makes an absent key mean "leave
     * the column alone", and a `null` would erase the offer's total instead.
     */
    it('edits without naming the total or the currency, so neither is erased', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await view.get('[data-testid="supplier-quotation-form-notes"]').setValue('Revised');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => (c[1] as RequestInit | undefined)?.method === 'PATCH');

        expect(String(call?.[0])).toContain('/supplier-quotations/q1');

        const sent = JSON.parse(String((call?.[1] as RequestInit).body)) as Record<string, unknown>;

        expect(Object.keys(sent)).not.toContain('total_price');
        expect(Object.keys(sent)).not.toContain('currency_id');
        expect(sent.notes).toBe('Revised');
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
        await view.get('[data-testid="supplier-quotation-form-supplier-id"]').setValue('s1');
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
 * ── The picker offers active items only (§10.4) ───────────────────────────
 *
 * §10.4's table: a deactivated product is "**Hidden** from selection lists" for
 * new quotations while staying functional on open ones. So the catalog call
 * carries `filter[is_active]=true`, and the test reads the URL.
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

    /** §10.4: "New quotations — **Hidden** from selection lists". */
    it('asks the catalog for active items only', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => String(c[0]).includes('/catalog-items'));

        expect(String(call?.[0])).toContain('filter%5Bis_active%5D=true');
    });

    it('adds a line and sends the catalog id, never a name beside it', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await flushPromises();
        await view.get('[data-testid="supplier-quotation-form-supplier-id"]').setValue('s1');
        await view.get('[data-testid="supplier-quotation-form-add-line"]').trigger('click');

        await view.get('[data-testid="supplier-quotation-line-0-product"]').setValue('ci1');
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
        await view.get('[data-testid="supplier-quotation-form-supplier-id"]').setValue('s1');
        await view.get('[data-testid="supplier-quotation-form-add-line"]').trigger('click');

        // "" is the "type a name instead" option, which is what reveals the box.
        await view.get('[data-testid="supplier-quotation-line-0-product"]').setValue('');
        await view.get('[data-testid="supplier-quotation-line-0-product-name"]').setValue('Breaker 63A');
        await view.get('[data-testid="supplier-quotation-line-0-unit-price"]').setValue('90');
        await view.get('[data-testid="supplier-quotation-line-0-quantity"]').setValue('12');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => (c[1] as RequestInit | undefined)?.method === 'POST');
        const sent = JSON.parse(String((call?.[1] as RequestInit).body)) as Record<string, unknown>;

        expect(sent.items).toEqual([{ product_name: 'Breaker 63A', unit_price: '90', quantity: '12' }]);
    });

    it('loads an offer’s existing lines on edit and sends them back', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await flushPromises();

        expect((view.get('[data-testid="supplier-quotation-line-0-unit-price"]').element as HTMLInputElement).value)
            .toBe('1500.000000');

        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        const call = [...fetchMock.mock.calls].find((c) => (c[1] as RequestInit | undefined)?.method === 'PATCH');
        const sent = JSON.parse(String((call?.[1] as RequestInit).body)) as Record<string, unknown>;

        expect(sent.items).toEqual(OFFER_LINES);
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
        await view.get('[data-testid="supplier-quotation-form-supplier-id"]').setValue('s1');
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
