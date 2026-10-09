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

async function render(profile: AuthenticatedUser = VIEWER, id = 'c1', locale = 'en') {
    const { createAppRouter } = await import('@/router');
    const router: Router = createAppRouter();

    await signIn(profile);
    await router.push(`/customers/${id}`);
    await router.isReady();

    const CustomerDetailView = (await import('@/pages/customers/CustomerDetailView.vue')).default;

    return mount(CustomerDetailView, {
        global: {
            plugins: [router, createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })],
        },
    });
}

beforeEach(() => {
    vi.restoreAllMocks();
    useAuth().forgetSession();
    window.localStorage.clear();
});

/**
 * `D-104` (F-38 · 1.3): the detail page prints the contact person as «title
 * name», in the screen's language; no title, the name alone; no name, «—».
 */
describe('CustomerDetailView — D-104 the contact person and their title', () => {
    const TITLES = [
        { code: 'mr', label_en: 'Mr.', label_ar: 'أستاذ', position: 1 },
        { code: 'mrs', label_en: 'Mrs.', label_ar: 'أستاذة', position: 2 },
    ];

    function stubWithTitles(record: unknown): void {
        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string) => {
                if (String(url).includes('/managed-lists/contact_titles')) {
                    return json(200, { data: TITLES, meta: { pagination: PAGINATION } });
                }

                if (String(url).includes('/managed-lists/')) {
                    return json(200, { data: [], meta: { pagination: PAGINATION } });
                }

                return json(200, { data: record, meta: {} });
            }),
        );
    }

    it.each([
        ['en', { contact_title: 'mrs', contact_person: 'Sara' }, 'Contact person', 'Mrs. Sara'],
        ['ar', { contact_title: 'mrs', contact_person: 'Sara' }, 'الشخص المتواصل معه', 'أ. Sara'],
        // D-104 as amended: «أ.» for either title once chosen, a fixed text, not the list's label.
        ['ar', { contact_title: 'mr', contact_person: 'Ali' }, 'الشخص المتواصل معه', 'أ. Ali'],
        ['en', { contact_title: 'mr', contact_person: 'Ali' }, 'Contact person', 'Mr. Ali'],
        ['ar', { contact_title: null, contact_person: 'Mona Adel' }, 'الشخص المتواصل معه', 'Mona Adel'],
        ['ar', { contact_title: 'mr', contact_person: null }, 'الشخص المتواصل معه', '—'],
        ['en', { contact_title: null, contact_person: 'Mona Adel' }, 'Contact person', 'Mona Adel'],
        ['en', { contact_title: 'mr', contact_person: null }, 'Contact person', '—'],
    ])('in %s, %o prints under %s as %s', async (locale, fields, label, shown) => {
        stubWithTitles({ ...CUSTOMER, ...fields });

        const view = await render(VIEWER, 'c1', locale);
        await flushPromises();

        const row = view.find('[data-testid="customer-contact-person"]');
        expect(row.find('dt').text()).toBe(label);
        expect(row.find('dd').text()).toBe(shown);
    });
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

    /** F-19 · 1.1a (`D-83`): the server names the owner; the page prints it for everyone who can view the customer. */
    it('names the sales owner in the summary, a dash when there is none', async () => {
        stubDetail(() => ({ ...CUSTOMER, sales_owner_name: 'Test Indoor Sales' }));

        const owned = await render();
        await flushPromises();

        expect(owned.find('[data-testid="customer-detail-owner"]').text()).toBe('Test Indoor Sales');

        vi.restoreAllMocks();
        stubDetail(() => ({ ...CUSTOMER, sales_owner_id: null, sales_owner_name: null }));

        const ownerless = await render();
        await flushPromises();

        expect(ownerless.find('[data-testid="customer-detail-owner"]').text()).toBe('—');
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

/**
 * F-19 · 1.1 — Flow 10 on the customer's own page. E2-1: no screen assigned an
 * owner, though `PATCH /customers/{customer}/assign` has existed since Point 3.5.
 *
 * The picker is `DealOwnerPicker`, reused rather than copied. It reads
 * `GET /users` on mount, so every stub below answers that path too.
 */

/** `AdministeredUser` in full, as `GET /users` answers it (`UserPayload::of`). */
const EMPLOYEES = [
    {
        id: 'u-indoor', name: 'Test Indoor Sales', email: 'indoor.sales@example.test',
        role_id: 'r5', role: { slug: 'indoor_sales', name: 'Indoor Sales', label: 'Indoor Sales' },
        is_active: true, created_at: '2026-08-01T00:00:00+00:00', updated_at: '2026-08-01T00:00:00+00:00',
    },
];

/** §3.3's `assign` row, which the Manager holds at `All`; `view` so the page opens at all. */
const MANAGER: AuthenticatedUser = {
    ...VIEWER,
    id: '01a0-manager',
    name: 'Test Manager',
    email: 'manager@example.test',
    role: { id: '01a0-role-mgr', slug: 'manager', name: 'Manager' },
    permissions: ['customer.view.all', 'customer.assign.all'],
};

/**
 * The page's four resources, routed by path for the reason `stubDetail` gives:
 * the record, the sector list, the employee list the picker reads on mount,
 * and the write. `assign` answers the write, so each refusal is its own stub.
 */
function stubAssign(
    assign: () => Response = () => json(200, { data: { ...CUSTOMER, sales_owner_id: 'u-indoor' }, meta: {} }),
): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(async (url: string, _init?: RequestInit) => {
        if (String(url).includes('/managed-lists/')) {
            return json(200, { data: [], meta: { pagination: PAGINATION } });
        }

        if (String(url).includes('/users')) {
            return json(200, { data: EMPLOYEES, meta: { pagination: { ...PAGINATION, total: 1 } } });
        }

        if (String(url).endsWith('/assign')) {
            return assign();
        }

        return json(200, { data: CUSTOMER, meta: {} });
    });

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

/** The write, found by its path: the picker's `/users` read comes first. */
function assignCall(fetchMock: ReturnType<typeof vi.fn>): [string, RequestInit] | undefined {
    return fetchMock.mock.calls.find((c) => String(c[0]).endsWith('/assign')) as [string, RequestInit] | undefined;
}

describe('CustomerDetailView — Flow 10 · F-19 · 1.1 assign', () => {
    it('assigns the chosen employee through its own route and says so', async () => {
        const fetchMock = stubAssign();
        const view = await render(MANAGER);
        await flushPromises();

        await view.find('[data-testid="customer-assign-owner"]').setValue('u-indoor');
        await view.find('[data-testid="customer-assign-form"]').trigger('submit');
        await flushPromises();

        const [url, init] = assignCall(fetchMock) ?? ['', {}];

        // `OpenAPI §7.2`'s suffix and `AssignCustomerRequest`'s one field:
        // `sales_owner_id`, not the deal route's `owner_id`.
        expect(url).toContain('/customers/c1/assign');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(String(init.body))).toEqual({ sales_owner_id: 'u-indoor' });
        expect(view.find('[data-testid="customer-assign-done"]').exists()).toBe(true);
    });

    /**
     * F-19 · 1.1a: the page takes the record the write answers with. The first
     * read carries no name, so a page that kept it, or read it again, still
     * shows no owner.
     */
    it('after an assign, the summary names the new owner from the answer', async () => {
        stubAssign(() => json(200, {
            data: { ...CUSTOMER, sales_owner_id: 'u-indoor', sales_owner_name: 'Test Indoor Sales' },
            meta: {},
        }));
        const view = await render(MANAGER);
        await flushPromises();

        await view.find('[data-testid="customer-assign-owner"]').setValue('u-indoor');
        await view.find('[data-testid="customer-assign-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="customer-detail-owner"]').text()).toBe('Test Indoor Sales');
    });

    /**
     * §3.3 gives `assign` its own row beside `edit`: five roles hold `edit`,
     * two hold `assign`. Without the row there is no section, so no
     * `GET /users` either, a list §3.11 gives to `admin.create_user`, so the
     * request could only be refused.
     */
    it('draws the assign section only for §3.3’s own customer.assign row', async () => {
        stubAssign();

        const manager = await render(MANAGER);
        await flushPromises();

        expect(manager.find('[data-testid="customer-assign"]').exists()).toBe(true);

        vi.restoreAllMocks();
        useAuth().forgetSession();
        const fetchMock = stubAssign();

        const editor = await render(EDITOR);
        await flushPromises();

        expect(editor.find('[data-testid="customer-assign"]').exists()).toBe(false);
        expect(fetchMock.mock.calls.filter((c) => String(c[0]).includes('/users'))).toHaveLength(0);
    });

    /** §3.3 has no unassign row, and `AssignCustomerRequest` requires the field. */
    it('refuses a blank owner without asking the server', async () => {
        const fetchMock = stubAssign();
        const view = await render(MANAGER);
        await flushPromises();

        // Nothing chosen: the picker opens on "Choose the employee", valued "".
        await view.find('[data-testid="customer-assign-form"]').trigger('submit');
        await flushPromises();

        expect(assignCall(fetchMock)).toBeUndefined();
        expect(view.find('[data-testid="customer-assign-owner-error"]').exists()).toBe(true);
    });

    /** The page's own message is looked up when drawn, so a language switch reaches it. */
    it('draws the required message in the language the page is in now', async () => {
        stubAssign();
        const view = await render(MANAGER);
        await flushPromises();

        await view.find('[data-testid="customer-assign-form"]').trigger('submit');
        await flushPromises();

        view.vm.$i18n.locale = 'ar';
        await flushPromises();

        expect(view.find('[data-testid="customer-assign-owner-error"]').text()).toBe(ar.customers.assign.ownerRequired);
    });

    /** Design System §6.1: the server's sentence, localised for this request, beside the field. */
    it('renders the server’s own sentence under the field when it refuses the owner', async () => {
        stubAssign(() => json(422, {
            error: {
                code: 'validation_failed',
                message: 'Please correct the highlighted fields.',
                details: [{ field: 'sales_owner_id', code: 'invalid', message: 'That sales owner is not a user of this system.' }],
            },
            meta: { request_id: 'r1' },
        }));
        const view = await render(MANAGER);
        await flushPromises();

        await view.find('[data-testid="customer-assign-owner"]').setValue('u-indoor');
        await view.find('[data-testid="customer-assign-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="customer-assign-owner-error"]').text())
            .toBe('That sales owner is not a user of this system.');
    });

    /** A 403 is about the caller's permission, and says so rather than blaming the field. */
    it('says a refused assignment was a permission problem on a 403', async () => {
        stubAssign(() => json(403, {
            error: { code: 'permission_denied', message: 'You do not have permission to perform this action.', details: [] },
            meta: { request_id: 'r1' },
        }));
        const view = await render(MANAGER);
        await flushPromises();

        await view.find('[data-testid="customer-assign-owner"]').setValue('u-indoor');
        await view.find('[data-testid="customer-assign-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="customer-assign-error"]').text()).toBe(en.customers.assign.forbidden);
    });

    /** `OpenAPI §5.1`'s 404 (the customer left the caller's reach meanwhile) is not a permission problem. */
    it('reports any other refusal as not accepted rather than as a permission problem', async () => {
        stubAssign(() => json(404, {
            error: { code: 'resource_not_found', message: 'The requested resource was not found.', details: [] },
            meta: { request_id: 'r1' },
        }));
        const view = await render(MANAGER);
        await flushPromises();

        await view.find('[data-testid="customer-assign-owner"]').setValue('u-indoor');
        await view.find('[data-testid="customer-assign-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="customer-assign-error"]').text()).toBe(en.customers.assign.rejected);
    });

    /** "Assigned" beside "you do not have permission" would be two answers to one question. */
    it('replaces the last outcome with each new attempt', async () => {
        const assigned = (): Response => json(200, { data: { ...CUSTOMER, sales_owner_id: 'u-indoor' }, meta: {} });
        // The server's refusals, in the order the attempts below meet them; then it accepts.
        const refusals = [
            (): Response => json(422, {
                error: {
                    code: 'validation_failed',
                    message: 'Please correct the highlighted fields.',
                    details: [{ field: 'sales_owner_id', code: 'invalid', message: 'That sales owner is not a user of this system.' }],
                },
                meta: { request_id: 'r1' },
            }),
            (): Response => json(403, {
                error: { code: 'permission_denied', message: 'You do not have permission to perform this action.', details: [] },
                meta: { request_id: 'r2' },
            }),
        ];
        stubAssign(() => (refusals.shift() ?? assigned)());
        const view = await render(MANAGER);
        await flushPromises();

        const submit = async (): Promise<void> => {
            await view.find('[data-testid="customer-assign-form"]').trigger('submit');
            await flushPromises();
        };
        const shown = (testId: string): boolean => view.find(`[data-testid="${testId}"]`).exists();
        const fieldMessage = (): string => view.find('[data-testid="customer-assign-owner-error"]').text();

        // Nothing chosen: the page's own message.
        await submit();
        expect(fieldMessage()).toBe(en.customers.assign.ownerRequired);

        // A 422: the server's sentence replaces the page's own.
        await view.find('[data-testid="customer-assign-owner"]').setValue('u-indoor');
        await submit();
        expect(fieldMessage()).toBe('That sales owner is not a user of this system.');

        // A 403: the field is clear and the alert speaks.
        await submit();
        expect(shown('customer-assign-owner-error')).toBe(false);
        expect(shown('customer-assign-error')).toBe(true);

        // Accepted: the alert is gone and the confirmation shows.
        await submit();
        expect(shown('customer-assign-error')).toBe(false);
        expect(shown('customer-assign-done')).toBe(true);

        // Nothing chosen again: the confirmation is withdrawn.
        await view.find('[data-testid="customer-assign-owner"]').setValue('');
        await submit();
        expect(shown('customer-assign-done')).toBe(false);
        expect(fieldMessage()).toBe(en.customers.assign.ownerRequired);
    });
});
