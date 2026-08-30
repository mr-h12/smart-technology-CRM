import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import type { Router } from 'vue-router';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 3, Point 4.4 — Design System §5.2's Detail view.
 *
 * ── The 404 is the interesting assertion ───────────────────────────────────
 *
 * `OpenAPI §5.1`: 404 means "Resource does not exist **or is not visible to
 * the caller**. Do not reveal which case applies." So the not-found state must
 * be one state and must never say which of the two happened — a screen that
 * said "you do not have access to this customer" would leak the row's
 * existence, which is exactly what `CustomerNotFound` exists to prevent.
 *
 * A 403 is a different answer: it means the caller lacks `customer.view`
 * outright, which is not a statement about any row.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const PAGINATION = { page: 1, per_page: 25, total: 0, total_pages: 1, has_next_page: false, has_previous_page: false };

/**
 * The screen asks for two things on mount — the record and the sector options.
 * A stub that answers every URL with the record hands `listEntries` a customer
 * and `items` stops being an array, which is how three of this suite's first
 * failures were produced. Route by resource, never by call order.
 */
function stubDetail(record: () => unknown, status = 200): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(async (url: string, _init?: RequestInit) => {
        if (String(url).includes('/managed-lists/')) {
            return json(200, { data: [], meta: { pagination: PAGINATION } });
        }

        return status === 200
            ? json(200, { data: record(), meta: {} })
            : json(status, { error: { code: 'x', message: 'no' } });
    });

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

const CUSTOMER = {
    id: 'c1',
    name: 'Alpha Trading',
    customer_status: 'customer',
    sector: 'commercial',
    region: 'Cairo',
    contact_person: 'Mona Adel',
    phone: '+20 100 000 0000',
    phone2: null,
    whatsapp: null,
    email: 'mona@alpha.test',
    sales_owner_id: 'u1',
    start_date: '2024-01-15',
    notes: 'Called on the 3rd; asked for a revised offer.',
    is_archived: false,
    is_incomplete: false,
    created_at: '2026-01-01T09:00:00Z',
    updated_at: '2026-02-01T09:00:00Z',
};

const VIEWER: AuthenticatedUser = {
    id: '01a0-sales',
    name: 'Indoor Sales',
    email: 'indoor.sales@example.test',
    is_active: true,
    role: { id: '01a0-role-ind', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['customer.view.own'],
    unconditional_access: false,
};

/** The same person, plus §3.3's `edit` row. */
const EDITOR: AuthenticatedUser = { ...VIEWER, permissions: ['customer.view.own', 'customer.edit.own'] };

async function signIn(profile: AuthenticatedUser): Promise<void> {
    const delegate = globalThis.fetch as typeof globalThis.fetch;

    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
            }))
            : delegate(input as unknown as RequestInfo, init));

    await useAuth().login(profile.email, 'Passw0rd123');
}

async function render(profile: AuthenticatedUser = VIEWER, id = 'c1') {
    const { createAppRouter } = await import('@/router');
    const router: Router = createAppRouter();

    await signIn(profile);
    await router.push(`/customers/${id}`);
    await router.isReady();

    const CustomerDetailView = (await import('@/pages/customers/CustomerDetailView.vue')).default;

    return mount(CustomerDetailView, {
        global: {
            plugins: [router, createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } })],
        },
    });
}

beforeEach(() => {
    vi.restoreAllMocks();
    useAuth().forgetSession();
    window.localStorage.clear();
});

describe('CustomerDetailView — Design System §5.2 Detail', () => {
    it('shows the loading state before the record arrives', async () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>(() => {})));

        const view = await render();

        expect(view.find('[data-testid="loading-state"]').exists()).toBe(true);
    });

    /** §5.2: "Summary first". §4.2's fields, and an em-dash where a nullable one is empty. */
    it('summarises the record and prints a blank field as an em-dash, not as null', async () => {
        stubDetail(() => CUSTOMER);

        const view = await render();
        await flushPromises();

        const summary = view.find('[data-testid="customer-summary"]');

        // The name is the page heading (§5.2's "summary first" starts with it),
        // so it is asserted on the view; the fields are asserted on the card.
        expect(view.text()).toContain('Alpha Trading');
        expect(summary.text()).toContain('Mona Adel');
        expect(summary.text()).toContain('mona@alpha.test');
        // `phone2` is null on this record and must not read as "null".
        expect(summary.text()).not.toContain('null');
        expect(summary.text()).toContain('—');
    });

    /** §4.5 · `D-49`: derived, read-only, with the reason. Never a control. */
    it('states the derived status with its reason and offers nothing to change it', async () => {
        stubDetail(() => CUSTOMER);

        const view = await render();
        await flushPromises();

        expect(view.find('[data-testid="customer-detail-status"]').text()).toContain('Customer');
        expect(view.find('[data-testid="customer-detail-status-reason"]').exists()).toBe(true);
        expect(view.find('select[data-testid="customer-detail-status"]').exists()).toBe(false);
    });

    /** `D-16`: notes carry the communication history — §5.2's "related data second". */
    it('shows the notes as the second section, and says so when there are none', async () => {
        stubDetail(() => CUSTOMER);

        const withNotes = await render();
        await flushPromises();

        expect(withNotes.find('[data-testid="customer-notes"]').text()).toContain('revised offer');

        vi.restoreAllMocks();
        stubDetail(() => ({ ...CUSTOMER, notes: null }));

        const without = await render();
        await flushPromises();

        expect(without.find('[data-testid="customer-notes-empty"]').exists()).toBe(true);
    });
});

describe('CustomerDetailView — OpenAPI §5.1 refusals', () => {
    /**
     * The whole point of `CustomerNotFound` being one exception for two cases.
     * A screen that distinguished them would undo it.
     */
    it('shows one not-found state on a 404 and never says whether the record exists', async () => {
        stubDetail(() => null, 404);

        const view = await render();
        await flushPromises();

        const notFound = view.find('[data-testid="customer-not-found"]');

        expect(notFound.exists()).toBe(true);
        expect(view.find('[data-testid="permission-denied-state"]').exists()).toBe(false);
        expect(notFound.text().toLowerCase()).not.toContain('permission');
        expect(notFound.text().toLowerCase()).not.toContain('access');
    });

    /** A 403 is about the caller, not about a row, so it gets the other screen. */
    it('shows permission denied on a 403, and not the not-found state', async () => {
        stubDetail(() => null, 403);

        const view = await render();
        await flushPromises();

        expect(view.find('[data-testid="permission-denied-state"]').exists()).toBe(true);
        expect(view.find('[data-testid="customer-not-found"]').exists()).toBe(false);
    });

    it('shows the error state on a 500 and retries on demand', async () => {
        let broken = true;
        const fetchMock = vi.fn(async (url: string, _init?: RequestInit) => {
            if (String(url).includes('/managed-lists/')) {
                return json(200, { data: [], meta: { pagination: PAGINATION } });
            }

            return broken
                ? json(500, { error: { code: 'internal_error', message: 'no' } })
                : json(200, { data: CUSTOMER, meta: {} });
        });
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        expect(view.find('[data-testid="error-state"]').exists()).toBe(true);

        broken = false;
        await view.find('[data-testid="error-retry"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="error-state"]').exists()).toBe(false);
        expect(view.text()).toContain('Alpha Trading');
    });
});

describe('CustomerDetailView — §5.2 action controls only by permission', () => {
    it('offers Edit to a holder of customer.edit and to nobody else', async () => {
        stubDetail(() => CUSTOMER);

        const editor = await render(EDITOR);
        await flushPromises();

        expect(editor.find('[data-testid="customer-detail-edit"]').exists()).toBe(true);

        vi.restoreAllMocks();
        useAuth().forgetSession();
        stubDetail(() => CUSTOMER);

        const reader = await render(VIEWER);
        await flushPromises();

        expect(reader.find('[data-testid="customer-detail-edit"]').exists()).toBe(false);
    });

    /** The page must show what was saved, not what it loaded before the edit. */
    it('re-reads the record after the form saves', async () => {
        let name = 'Alpha Trading';
        stubDetail(() => ({ ...CUSTOMER, name }));

        const view = await render(EDITOR);
        await flushPromises();

        await view.find('[data-testid="customer-detail-edit"]').trigger('click');
        name = 'Alpha Trading Co';
        await view.find('[data-testid="customer-form-name"]').setValue(name);
        await view.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        expect(view.text()).toContain('Alpha Trading Co');
    });
});
