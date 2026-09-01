import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import ManagedListsView from '@/pages/lists/ManagedListsView.vue';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 2, Point 5.4 — `DB-05`'s four managed lists.
 *
 * ── Not an authorization suite ─────────────────────────────────────────────
 *
 * §3.12 rule 1 and `SEC-09`: enforcement is the API's, and
 * `ManagedListEndpointTest` proves the refusals against the server. What is
 * proved here is what the screen **draws and sends** — and for this screen that
 * matters more than usual, because the read and the write answer to two
 * different authorities. A screen that drew the add form for a reader who
 * cannot write would send a request guaranteed to 403.
 *
 * ── The read is open, the write is not ─────────────────────────────────────
 *
 * `routes/api.php` carries the reasoning: §3.11 has no row for managed lists,
 * so the read is authentication alone (§8 puts Customers on six roles' screens
 * and not one renders without a sector) and the write is
 * `admin.system_settings`. Both halves are asserted below with a real profile
 * for each.
 */

const SECTORS = [
    { code: 'government', label_en: 'Government', label_ar: 'حكومي', position: 1 },
    { code: 'banks', label_en: 'Banks', label_ar: 'بنوك', position: 2 },
];

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function page(items: unknown[], pagination?: Record<string, unknown>): Response {
    return json(200, {
        data: items,
        meta: { request_id: 'req_test', ...(pagination === undefined ? {} : { pagination }) },
    });
}

function i18n(locale = 'en') {
    return createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } });
}

/**
 * A role with a session and no administration row at all.
 *
 * §3.12 rule 5 makes such a role real rather than a fixture convenience, and it
 * is the whole reason the read carries no permission: this is the person who
 * needs to see the sector list and will never edit it.
 */
const INDOOR_SALES: AuthenticatedUser = {
    id: '01a0-ind',
    name: 'Test Indoor Sales',
    email: 'indoor.sales@example.test',
    is_active: true,
    role: { id: '01a0-role-ind', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['customer.view.own'],
    unconditional_access: false,
};

/** §3.11's *system settings* row, which is what the write names. */
const SETTINGS_ADMIN: AuthenticatedUser = {
    id: '01a0-set',
    name: 'Settings Administrator',
    email: 'settings.admin@example.test',
    is_active: true,
    role: { id: '01a0-role-set', slug: 'settings_admin', name: 'Settings Administrator' },
    permissions: ['admin.system_settings.all'],
    unconditional_access: false,
};

async function render(locale = 'en', profile: AuthenticatedUser = SETTINGS_ADMIN) {
    const delegate = globalThis.fetch as typeof globalThis.fetch;

    vi.stubGlobal('fetch', (input: string, init?: RequestInit) =>
        /\/auth\/login$/.test(String(input))
            ? Promise.resolve(json(201, {
                data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
            }))
            : delegate(input as unknown as RequestInfo, init));

    await useAuth().login(profile.email, 'Passw0rd123');

    return mount(ManagedListsView, { global: { plugins: [i18n(locale)] } });
}

beforeEach(() => {
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('ManagedListsView', () => {
    it('shows the loading state before the entries arrive', async () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>(() => {})));

        const view = await render();

        expect(view.find('[data-testid="loading-state"]').exists()).toBe(true);
    });

    /**
     * `DB-05` names four; `companies` is the fifth, added by the owner's ruling
     * of 2026-08-31 (Point 5.1) because §7.3 groups the catalog by company and
     * the owner asked to filter by it — and a value that is grouped *and*
     * filtered cannot stay free text without fragmenting.
     *
     * This is a count-asserting guard and it broke on purpose. Updated with the
     * reason rather than widened.
     */
    it('offers exactly the lists this system keeps', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page(SECTORS)));

        const view = await render();
        await flushPromises();

        expect(view.findAll('[data-list-name]').map((b) => b.attributes('data-list-name'))).toEqual([
            'sectors',
            'units',
            'service_types',
            'delivery_terms',
            'companies',
        ]);
    });

    it('opens on sectors and asks for that list', async () => {
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => page(SECTORS));
        vi.stubGlobal('fetch', fetchMock);

        await render();
        await flushPromises();

        expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/managed-lists/sectors'))).toBe(true);
    });

    it('renders both labels on every row, because §14.2 requires both languages', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page(SECTORS)));

        const view = await render();
        await flushPromises();

        const rows = view.findAll('[data-testid="lists-row"]');

        expect(rows).toHaveLength(2);
        expect(rows[0]?.text()).toContain('government');
        expect(rows[0]?.text()).toContain('Government');
        expect(rows[0]?.text()).toContain('حكومي');
    });

    it('fetches the chosen list when another one is picked', async () => {
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => page(SECTORS));
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.find('[data-list-name="units"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/managed-lists/units'))).toBe(true);
    });

    it('draws the empty state for a list with no entries', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page([])));

        const view = await render();
        await flushPromises();

        expect(view.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(view.find('[data-testid="lists-table"]').exists()).toBe(false);
    });

    it('draws the add form for a role holding the settings row', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page(SECTORS)));

        const view = await render();
        await flushPromises();

        expect(view.find('[data-testid="lists-add"]').exists()).toBe(true);
    });

    it('draws no add form for a role that may only read, and sends no write', async () => {
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => page(SECTORS));
        vi.stubGlobal('fetch', fetchMock);

        const view = await render('en', INDOOR_SALES);
        await flushPromises();

        expect(view.find('[data-testid="lists-add"]').exists()).toBe(false);
        // The table is still there — the read is open, and that is the point.
        expect(view.find('[data-testid="lists-table"]').exists()).toBe(true);
        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
    });

    it('posts every field the boundary requires', async () => {
        const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'POST'
                ? json(201, { data: { entry: { code: 'education', label_en: 'Education', label_ar: 'تعليم', position: 3 } } })
                : page(SECTORS));
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.find('[data-testid="lists-code"]').setValue('education');
        await view.find('[data-testid="lists-label-en"]').setValue('Education');
        await view.find('[data-testid="lists-label-ar"]').setValue('تعليم');
        await view.find('[data-testid="lists-position"]').setValue('3');
        await view.find('[data-testid="lists-add"]').trigger('submit');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST');

        expect(post).toBeDefined();
        expect(String(post?.[0])).toContain('/managed-lists/sectors');
        expect(JSON.parse(String(post?.[1]?.body))).toEqual({
            code: 'education',
            label_en: 'Education',
            label_ar: 'تعليم',
            position: 3,
        });
    });

    it("shows the server's own sentence when a code is already taken", async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'POST'
                ? json(422, {
                    error: {
                        code: 'validation_failed',
                        message: 'The given data was invalid.',
                        details: [{ field: 'code', code: 'duplicate', message: 'This code is already used in this list.' }],
                    },
                })
                : page(SECTORS)));

        const view = await render();
        await flushPromises();

        await view.find('[data-testid="lists-code"]').setValue('banks');
        await view.find('[data-testid="lists-label-en"]').setValue('Banks');
        await view.find('[data-testid="lists-label-ar"]').setValue('بنوك');
        await view.find('[data-testid="lists-position"]').setValue('3');
        await view.find('[data-testid="lists-add"]').trigger('submit');
        await flushPromises();

        expect(view.text()).toContain('This code is already used in this list.');
    });

    it('asks for the next page and nothing else', async () => {
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => page(SECTORS, {
            page: 1, per_page: 25, total: 30, total_pages: 2, has_next_page: true, has_previous_page: false,
        }));
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.find('[data-testid="lists-next"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some(([url]) => String(url).includes('page=2'))).toBe(true);
    });

    it('draws no pager when there is only one page', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page(SECTORS, {
            page: 1, per_page: 25, total: 2, total_pages: 1, has_next_page: false, has_previous_page: false,
        })));

        const view = await render();
        await flushPromises();

        expect(view.find('[data-testid="lists-pagination"]').exists()).toBe(false);
    });

    it('offers a retry when the list cannot be loaded', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(500, { error: { code: 'server_error', message: 'boom' } })));

        const view = await render();
        await flushPromises();

        expect(view.find('[data-testid="error-state"]').exists()).toBe(true);
    });

    // ── archiving an entry (Step 6 Point 6.2) ───────────────────────────────

    /**
     * `SEC-09`'s presentation half. `DELETE /managed-lists/{list}/{code}` carries
     * `admin.system_settings`, so drawing the control for a reader would offer a
     * button whose only possible answer is 403 — the same reasoning this file
     * already applies to the add form.
     */
    it('draws no archive control for a reader who cannot write', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page(SECTORS)));

        const view = await render('en', INDOOR_SALES);
        await flushPromises();

        expect(view.find('[data-testid="lists-table"]').exists()).toBe(true);
        expect(view.find('[data-testid="lists-archive"]').exists()).toBe(false);
    });

    /** Withdrawing a choice every form offers is not a click to take on trust. */
    it('asks before archiving, and sends nothing until the answer is yes', async () => {
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => page(SECTORS));
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.find('[data-testid="lists-archive"]').trigger('click');

        expect(view.find('[data-testid="confirm-dialog"]').exists()).toBe(true);
        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'DELETE')).toBe(false);
    });

    it('cancelling the confirmation sends nothing', async () => {
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => page(SECTORS));
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.find('[data-testid="lists-archive"]').trigger('click');
        await view.find('[data-testid="confirm-cancel"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="confirm-dialog"]').exists()).toBe(false);
        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'DELETE')).toBe(false);
    });

    /** The entry's own address, not a body — `OpenAPI §7.1`'s resource route. */
    it('archives the entry at its own address', async () => {
        const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'DELETE'
                ? json(200, { data: { archived: true, list: 'sectors', code: 'government' } })
                : page(SECTORS));
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.find('[data-testid="lists-archive"]').trigger('click');
        await view.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        const sent = fetchMock.mock.calls.find(([, init]) => init?.method === 'DELETE');

        expect(sent).toBeDefined();
        // SECTORS[0] is the row the first control belongs to.
        expect(String(sent?.[0])).toContain('/managed-lists/sectors/government');
    });

    /** The archived row has to leave the table, and the server is what says so. */
    it('reloads the list once the entry is archived', async () => {
        const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'DELETE'
                ? json(200, { data: { archived: true, list: 'sectors', code: 'government' } })
                : page(SECTORS));
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        const readsBefore = fetchMock.mock.calls.filter(([, init]) => init?.method !== 'DELETE').length;

        await view.find('[data-testid="lists-archive"]').trigger('click');
        await view.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        const readsAfter = fetchMock.mock.calls.filter(([, init]) => init?.method !== 'DELETE').length;

        expect(readsAfter).toBeGreaterThan(readsBefore);
    });

    /** A refusal is drawn, never swallowed — the row is still there and must look it. */
    it('reports a refused archive instead of pretending it worked', async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'DELETE'
                ? json(403, { error: { code: 'forbidden', message: 'This action is not allowed.' } })
                : page(SECTORS)));

        const view = await render();
        await flushPromises();

        await view.find('[data-testid="lists-archive"]').trigger('click');
        await view.find('[data-testid="confirm-accept"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="lists-archive-error"]').exists()).toBe(true);
    });

    // ── restoring from the archive (Step 6 Point 6.2c) ──────────────────────

    /**
     * `GET /managed-lists/{list}/archived` carries `admin.system_settings`,
     * unlike the live list which carries none. So the control that reaches it
     * is drawn for a writer only — offering a reader a view whose every request
     * is a 403 is the defect this screen already avoids for the add form.
     */
    it('draws no archived view for a reader who cannot write', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page(SECTORS)));

        const view = await render('en', INDOOR_SALES);
        await flushPromises();

        expect(view.find('[data-testid="lists-view"]').exists()).toBe(false);
    });

    it('asks the archived collection when the view is switched', async () => {
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => page(SECTORS));
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.find('[data-testid="lists-view"]').setValue('archived');
        await flushPromises();

        const asked = fetchMock.mock.calls.map(([url]) => String(url));

        expect(asked.some((url) => url.includes('/managed-lists/sectors/archived'))).toBe(true);
    });

    /** The view decides which action a row offers; an archived row cannot be archived again. */
    it('offers Restore in the archived view and Archive in the live one', async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, _init?: RequestInit) => page(SECTORS)));

        const view = await render();
        await flushPromises();

        expect(view.find('[data-testid="lists-archive"]').exists()).toBe(true);
        expect(view.find('[data-testid="lists-restore"]').exists()).toBe(false);

        await view.find('[data-testid="lists-view"]').setValue('archived');
        await flushPromises();

        expect(view.find('[data-testid="lists-restore"]').exists()).toBe(true);
        expect(view.find('[data-testid="lists-archive"]').exists()).toBe(false);
    });

    /**
     * **No confirmation, deliberately.** §6.6 lists what must be confirmed —
     * archive, deactivate, rejection, return, approval — and a single restore
     * is none of them. `CustomersView` already reads §6.6 the same way.
     */
    it('restores without asking, at the entry own address', async () => {
        const fetchMock = vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH'
                ? json(200, { data: { restored: true, list: 'sectors', code: 'government' } })
                : page(SECTORS));
        vi.stubGlobal('fetch', fetchMock);

        const view = await render();
        await flushPromises();

        await view.find('[data-testid="lists-view"]').setValue('archived');
        await flushPromises();

        await view.find('[data-testid="lists-restore"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="confirm-dialog"]').exists()).toBe(false);

        const sent = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH');

        expect(sent).toBeDefined();
        expect(String(sent?.[0])).toContain('/managed-lists/sectors/government/restore');
    });

    /**
     * `OpenAPI §5.1`'s 409 `state_transition_invalid`: the code this entry wants
     * back was taken while it was away. A generic "try again" would send the
     * person round a loop that cannot succeed.
     */
    it('explains a refused restore rather than offering a retry', async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) =>
            init?.method === 'PATCH'
                ? json(409, { error: { code: 'state_transition_invalid', message: 'That code is already used in this list.' } })
                : page(SECTORS)));

        const view = await render();
        await flushPromises();

        await view.find('[data-testid="lists-view"]').setValue('archived');
        await flushPromises();
        await view.find('[data-testid="lists-restore"]').trigger('click');
        await flushPromises();

        expect(view.text()).toContain(en.lists.error.restoreTaken);
    });

    /** Adding into a list you are not looking at lands the new row out of sight. */
    it('hides the add form while the archived view is showing', async () => {
        vi.stubGlobal('fetch', vi.fn(async (_url: string, _init?: RequestInit) => page(SECTORS)));

        const view = await render();
        await flushPromises();

        expect(view.find('[data-testid="lists-add"]').exists()).toBe(true);

        await view.find('[data-testid="lists-view"]').setValue('archived');
        await flushPromises();

        expect(view.find('[data-testid="lists-add"]').exists()).toBe(false);
    });

    it('renders the column headings in Arabic when the locale is Arabic', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => page(SECTORS)));

        const view = await render('ar');
        await flushPromises();

        // Asserted against the lang file rather than a literal: a heading that
        // silently fell back to English would otherwise read as a pass.
        expect(view.text()).toContain(ar.lists.column.code);
        expect(view.text()).not.toContain(en.lists.column.position);
    });
});
