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
    permissions: ['supplier_quotation.view.all', 'supplier_quotation.create.all'],
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

function envelope(data: unknown, extra: Record<string, unknown> = {}): unknown {
    return { data, meta: { request_id: 'r1', ...extra } };
}

/** Answers the offers call, and the suppliers call the name column needs. */
function respond(offers: unknown = [OFFER], status = 200): ReturnType<typeof vi.fn> {
    return vi.fn(async (input: string) => {
        if (String(input).includes('/suppliers')) {
            return json(200, envelope([SUPPLIER], { pagination: { ...PAGINATION, per_page: 100 } }));
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
        const before = fetchMock.mock.calls.length;

        await view.find('[data-testid="supplier-quotations-create"]').trigger('click');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock.mock.calls.length).toBe(before);
        expect(view.get('[data-testid="supplier-quotation-form-supplier-id-error"]').text())
            .toBe(en.supplierQuotations.form.supplierRequired);
    });

    /** §5.2, §6.5: a saved offer may not belong on the page in view, so the list is asked again. */
    it('closes the dialog and asks the server again once an offer is saved', async () => {
        const fetchMock = respond();
        const view = await render(fetchMock);
        const before = fetchMock.mock.calls.length;

        await view.find('[data-testid="supplier-quotations-row-edit"]').trigger('click');
        await view.get('[data-testid="supplier-quotation-form-notes"]').setValue('Revised');
        await view.get('[data-testid="supplier-quotation-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="supplier-quotation-form-modal"]').exists()).toBe(false);
        // The PATCH, and then the list again.
        expect(fetchMock.mock.calls.length).toBe(before + 2);
    });
});
