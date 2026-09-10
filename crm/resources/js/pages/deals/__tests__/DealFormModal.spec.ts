import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import DealFormModal from '@/pages/deals/DealFormModal.vue';
import type { Deal } from '@/services/deals';
import type { Customer } from '@/services/customers';
import { useAuth, type AuthenticatedUser } from '@/stores/auth';

/**
 * Module 5, Point 6.3 — §4.3's deal, created and edited in one dialog.
 *
 * ── What is proved, and what belongs to the server ─────────────────────────
 *
 * That the dialog sends the fields the server accepts and **omits the ones it
 * prohibits**; that a field refusal is rendered as the server's own sentence
 * and a form-level one as a local key; and that an unsaved change is never
 * discarded silently. Validation itself is the server's (`D-67`), and
 * `DealWriteEndpointTest` proves it there — including that `code`, `status`,
 * `approval_status` and the rest answer 422 rather than being dropped.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const CUSTOMERS: Customer[] = [
    {
        id: 'c1', name: 'Acme Industrial', customer_status: 'prospect', sector: null, region: null,
        contact_person: null, phone: null, phone2: null, whatsapp: null, email: null,
        sales_owner_id: null, start_date: null, notes: null, is_archived: false, is_incomplete: false,
        created_at: '2026-08-01T00:00:00+00:00', updated_at: '2026-08-01T00:00:00+00:00',
    },
];

const DEAL: Deal = {
    id: 'd1',
    code: 'DL-2026-0001',
    customer_id: 'c1',
    title: 'Twelve pumps',
    source: 'employee_entry',
    service_type: 'product',
    status: 'lead',
    owner_id: 'u9',
    approval_status: 'pending',
    rejection_reason: null,
    lost_reason: null,
    last_activity_at: '2026-09-08T09:00:00+00:00',
    created_at: '2026-09-08T09:00:00+00:00',
    updated_at: '2026-09-08T09:00:00+00:00',
};

function envelope(data: unknown): unknown {
    return { data, meta: { request_id: 'r1' } };
}

const EMPLOYEES = [
    {
        id: 'u-indoor', name: 'Test Indoor Sales', email: 'indoor.sales@example.test',
        role_id: 'r5', role: { slug: 'indoor_sales', name: 'Indoor Sales', label: 'Indoor Sales' },
        is_active: true, created_at: '2026-08-01T00:00:00+00:00', updated_at: '2026-08-01T00:00:00+00:00',
    },
];

/** §3.4 gives the Manager `assign_owner`; an owner is theirs to choose. */
const ASSIGNER: AuthenticatedUser = {
    id: 'u1', name: 'Test Manager', email: 'manager@example.test',
    role: { id: 'r1', slug: 'manager', name: 'Manager' },
    permissions: ['deal.create.all', 'deal.edit.all', 'deal.assign_owner.all'],
    is_active: true, unconditional_access: false,
};

/**
 * §3.4 gives Indoor Sales `create` as `Own` and **no** `assign_owner`.
 * `SaveDeal::ownedWithinScope` refuses any owner but themselves and assigns
 * them automatically when none is named — so there is no choice to draw.
 */
const OWN_CREATOR: AuthenticatedUser = {
    ...ASSIGNER, id: 'u2', role: { id: 'r5', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['deal.create.own', 'deal.edit.own'],
};

async function signIn(profile: AuthenticatedUser): Promise<void> {
    vi.stubGlobal('fetch', () => Promise.resolve(json(201, {
        data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user: profile },
    })));

    await useAuth().login(profile.email, 'Passw0rd123');
}

/**
 * @param writeResponse what the create/update call answers — the picker's own
 *        `/users` read is always answered separately, so a test forcing a
 *        refusal cannot accidentally refuse the employee list too.
 */
async function mountModal(
    editing: Deal | null = null,
    profile: AuthenticatedUser = ASSIGNER,
    usersStatus = 200,
    writeResponse?: () => Promise<Response>,
) {
    await signIn(profile);

    const fetchMock = vi.fn(async (input: string) => {
        if (String(input).includes('/users')) {
            return usersStatus === 200
                ? json(200, {
                    data: EMPLOYEES,
                    meta: { request_id: 'r1', pagination: { page: 1, per_page: 25, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false } },
                })
                : json(usersStatus, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } });
        }

        return writeResponse ? writeResponse() : json(201, envelope(DEAL));
    });

    vi.stubGlobal('fetch', fetchMock);

    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });

    const wrapper = mount(DealFormModal, {
        props: { open: true, editing, customers: CUSTOMERS },
        global: { plugins: [i18n] },
    });

    await flushPromises();

    return wrapper;
}



describe('the deal form dialog', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.unstubAllGlobals();
        useAuth().forgetSession();
        window.localStorage.clear();
        fetchMock = vi.fn(async () => json(201, envelope(DEAL)));
    });

    /** The write call, found by path — the picker reads `/users` on open. */
    function writeCall(): [string, RequestInit] | undefined {
        const mock = (globalThis.fetch as unknown as ReturnType<typeof vi.fn>).mock;

        return mock.calls.find((c) => !String(c[0]).includes('/users') && !String(c[0]).includes('/auth/')) as [string, RequestInit] | undefined;
    }

    // ──────────────────────────────────────────────────────────────── creating

    it('posts §4.3’s five fields, turning an unset one into null', async () => {
        const wrapper = await mountModal();

        await wrapper.find('[data-testid="deal-form-customer-id"]').setValue('c1');
        await wrapper.find('[data-testid="deal-form-title"]').setValue('Twelve pumps');
        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        // "" means "not given", which on the wire is null and never an empty
        // string — the server's own vocabularies have no empty member.
        expect(JSON.parse(String(writeCall()![1].body))).toEqual({
            customer_id: 'c1',
            owner_id: null,
            title: 'Twelve pumps',
            source: null,
            service_type: null,
        });
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });

    it('refuses to post without a customer, and says so before the round trip', async () => {
        const wrapper = await mountModal();

        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="deal-form-customer-id-error"]').text()).toBe('The customer is required.');
    });

    // ───────────────────────────────────────────────────────────────── editing

    it('opens filled from the row it was given', async () => {
        const wrapper = await mountModal(DEAL);

        expect((wrapper.find('[data-testid="deal-form-title"]').element as HTMLInputElement).value)
            .toBe('Twelve pumps');
        expect(wrapper.text()).toContain('Edit deal');
    });

    it('never sends customer_id or owner_id on an edit, because PATCH prohibits both', async () => {
        const wrapper = await mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form-title"]').setValue('Fourteen pumps');
        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        const body = JSON.parse(String(writeCall()![1].body));

        expect(body).toEqual({ title: 'Fourteen pumps', source: 'employee_entry', service_type: 'product' });
        // Sending the unchanged value would 422 the save outright.
        expect(body).not.toHaveProperty('customer_id');
        expect(body).not.toHaveProperty('owner_id');
    });

    it('draws neither control on an edit rather than drawing them disabled', async () => {
        const wrapper = await mountModal(DEAL);

        // A disabled control invites the question "why", and the answer is a
        // different screen: the owner moves through `assign_owner`'s own route.
        expect(wrapper.find('[data-testid="deal-form-customer-id"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="deal-form-owner-id"]').exists()).toBe(false);
    });

    it('offers an employee picker to a role that may choose an owner', async () => {
        const wrapper = await mountModal();

        const control = wrapper.find('[data-testid="deal-form-owner-id"]');

        // The owner reported a UUID box here, and the picker Point 6.7a gave
        // the assign panel belongs on the create form too.
        expect(control.element.tagName).toBe('SELECT');
        expect(control.findAll('option').map((o) => o.attributes('value'))).toContain('u-indoor');
    });

    it('draws no owner control at all for a creator who cannot choose one', async () => {
        const wrapper = await mountModal(null, OWN_CREATOR);

        // ⚠️ The defect the owner found. §3.4 gives Indoor Sales no
        // `assign_owner`, and `SaveDeal::ownedWithinScope` refuses any owner
        // but themselves (`permission_denied`) while assigning them
        // automatically when none is named. A field that looks like a choice
        // and answers 403 to every value but one is worse than no field.
        expect(wrapper.find('[data-testid="deal-form-owner-id"]').exists()).toBe(false);
        // The rest of the dialog is theirs, and stays.
        expect(wrapper.find('[data-testid="deal-form-customer-id"]').exists()).toBe(true);
    });

    it('never asks for the employee list without the permission to use it', async () => {
        await mountModal(null, OWN_CREATOR);

        const mock = (globalThis.fetch as unknown as ReturnType<typeof vi.fn>).mock;

        expect(mock.calls.filter((c) => String(c[0]).includes('/users'))).toHaveLength(0);
    });

    it('falls back to the identifier box when the employee list is refused', async () => {
        const wrapper = await mountModal(null, ASSIGNER, 403);

        const control = wrapper.find('[data-testid="deal-form-owner-id"]');

        expect(control.element.tagName).toBe('INPUT');
        // "Refused" and "empty" are different facts — the same hole a probe
        // found in Point 6.7a's panel.
        expect(wrapper.find('[data-testid="deal-form-owner-id-unavailable"]').exists()).toBe(true);
    });

    it('never asks for the employee list on an edit, where owner_id is prohibited', async () => {
        await mountModal(DEAL);

        const mock = (globalThis.fetch as unknown as ReturnType<typeof vi.fn>).mock;

        expect(mock.calls.filter((c) => String(c[0]).includes('/users'))).toHaveLength(0);
    });

    it('draws both on a create, for a role that may set either', async () => {
        const wrapper = await mountModal();

        expect(wrapper.find('[data-testid="deal-form-customer-id"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="deal-form-owner-id"]').exists()).toBe(true);
    });

    // ───────────────────────────────────────────────────── the server's refusals

    it('renders a field refusal as the server’s own sentence', async () => {
        const wrapper = await mountModal(DEAL, ASSIGNER, 200, async () => json(422, {
            error: {
                code: 'validation_failed',
                message: 'The given data was invalid.',
                details: [{ field: 'title', message: 'The request may not be longer than 255 characters.' }],
            },
            meta: { request_id: 'r1' },
        }));

        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        // Already localised by the server; rendered verbatim rather than
        // re-translated into a guess at what it meant.
        expect(wrapper.find('[data-testid="deal-form-title-error"]').text())
            .toBe('The request may not be longer than 255 characters.');
        expect(wrapper.emitted('saved')).toBeUndefined();
    });

    it('renders a 403 as a form-level key, not as a field error', async () => {
        const wrapper = await mountModal(DEAL, ASSIGNER, 200, async () => json(403, {
            error: { code: 'forbidden' }, meta: { request_id: 'r1' },
        }));

        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-form-error"]').text())
            .toBe('You do not have permission to save this deal.');
    });

    it('says the server could not be reached when the request never lands', async () => {
        const wrapper = await mountModal(DEAL, ASSIGNER, 200, () => Promise.reject(new TypeError('network down')));

        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-form-error"]').text())
            .toBe('The server could not be reached. Nothing was saved.');
    });

    // ─────────────────────────────────────────────── §5.2's unsaved-change warning

    it('closes straight away when nothing was changed', async () => {
        const wrapper = await mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form-cancel"]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(wrapper.find('[data-testid="deal-form-discard"]').exists()).toBe(false);
    });

    it('asks before discarding an edit, in the dialog and not through confirm()', async () => {
        const wrapper = await mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form-title"]').setValue('Changed');
        await wrapper.find('[data-testid="deal-form-cancel"]').trigger('click');

        // A native confirm() is neither translatable nor mirrored for RTL.
        expect(wrapper.find('[data-testid="deal-form-discard"]').exists()).toBe(true);
        expect(wrapper.emitted('cancel')).toBeUndefined();

        await wrapper.find('[data-testid="deal-form-discard-confirm"]').trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('takes the same path on Escape as on Cancel', async () => {
        const wrapper = await mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form-title"]').setValue('Changed');
        await wrapper.find('[data-testid="deal-form"]').trigger('keydown.escape');

        // §6.1: Escape closes dialogs "without discarding silently".
        expect(wrapper.find('[data-testid="deal-form-discard"]').exists()).toBe(true);
        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('keeps editing when the discard is declined', async () => {
        const wrapper = await mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form-title"]').setValue('Changed');
        await wrapper.find('[data-testid="deal-form-cancel"]').trigger('click');
        await wrapper.find('[data-testid="deal-form-discard-keep"]').trigger('click');

        expect(wrapper.find('[data-testid="deal-form-discard"]').exists()).toBe(false);
        expect(wrapper.emitted('cancel')).toBeUndefined();
        expect((wrapper.find('[data-testid="deal-form-title"]').element as HTMLInputElement).value).toBe('Changed');
    });

    it('is dirty against what it opened with, not against blank', async () => {
        const wrapper = await mountModal(DEAL);

        // The dialog opened with a title already in it; that is not a change.
        await wrapper.find('[data-testid="deal-form-cancel"]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });
});
