import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import DealFormModal from '@/pages/deals/DealFormModal.vue';
import type { Deal } from '@/services/deals';
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

/**
 * F-08 · 1.3: the row the picker's own `/customers` read answers. Its id is
 * deliberately not one a `<select>` filled from a hundred-row page would have
 * held (`D-84`): position 183 by name in the dev data.
 */
const FOUND = { id: 'c-183', name: 'المركز القومي للمرأة', region: 'القاهرة', contact_person: null, phone: null };

const DEAL: Deal = {
    id: 'd1',
    code: 'DL-2026-0001',
    customer_id: 'c1',
    title: 'Twelve pumps',
    details: 'Site visit on Tuesday.\nTwo floors.',
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
    customer?: { id: string; name: string },
) {
    await signIn(profile);

    const fetchMock = vi.fn(async (input: string) => {
        if (String(input).includes('/customers')) {
            return json(200, {
                data: [FOUND],
                meta: { request_id: 'r1', pagination: { page: 1, per_page: 20, total: 1, total_pages: 1, has_next_page: false, has_previous_page: false } },
            });
        }

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
        props: { open: true, editing, customer },
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

    /** The write call, found by path — the pickers read `/users` and `/customers`. */
    function writeCall(): [string, RequestInit] | undefined {
        const mock = (globalThis.fetch as unknown as ReturnType<typeof vi.fn>).mock;

        return mock.calls.find((c) => !['/users', '/customers', '/auth/'].some((path) => String(c[0]).includes(path))) as [string, RequestInit] | undefined;
    }

    /** Focus the customer picker and take the row the server answered. */
    async function pickCustomer(wrapper: Awaited<ReturnType<typeof mountModal>>): Promise<void> {
        await wrapper.find('[data-testid="deal-form-customer-id"]').trigger('focus');
        await flushPromises();
        await wrapper.find('[data-testid="deal-form-customer-id-option"]').trigger('mousedown');
    }

    // ──────────────────────────────────────────────────────────────── creating

    it('posts §4.3’s five fields, turning an unset one into null', async () => {
        const wrapper = await mountModal();

        await pickCustomer(wrapper);
        await wrapper.find('[data-testid="deal-form-title"]').setValue('Twelve pumps');
        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        // "" means "not given", which on the wire is null and never an empty
        // string — the server's own vocabularies have no empty member.
        expect(JSON.parse(String(writeCall()![1].body))).toEqual({
            customer_id: 'c-183',
            owner_id: null,
            title: 'Twelve pumps',
            details: null,
            source: null,
            service_type: null,
        });
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });

    it('offers the customer the server found, never a page of a hundred (F-08 · 1.3, D-84)', async () => {
        const wrapper = await mountModal();
        const mock = (globalThis.fetch as unknown as ReturnType<typeof vi.fn>).mock;

        // Nothing is read before the field is touched, and the placeholder
        // is the form's own «اختر العميل» — no clear button, the field is required.
        expect(mock.calls.some((c) => String(c[0]).includes('/customers'))).toBe(false);
        expect(wrapper.find('[data-testid="deal-form-customer-id"]').attributes('placeholder')).toBe('Choose the customer');
        expect(wrapper.find('[data-testid="deal-form-customer-id-clear"]').exists()).toBe(false);

        await pickCustomer(wrapper);

        expect(mock.calls.some((c) => String(c[0]).includes('per_page=100'))).toBe(false);
        expect(wrapper.find('[data-testid="deal-form-customer-id"]').attributes('aria-expanded')).toBe('false');
        expect((wrapper.find('[data-testid="deal-form-customer-id"]').element as HTMLInputElement).value).toBe('المركز القومي للمرأة');
    });

    it('refuses to post without a customer, and says so before the round trip', async () => {
        const wrapper = await mountModal();

        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="deal-form-customer-id-error"]').text()).toBe('The customer is required.');
    });

    /** `D-114`: opened from a customer's page, the customer is fixed — named, not picked — and sent. */
    it('a preset customer replaces the picker and is sent as customer_id', async () => {
        const wrapper = await mountModal(null, ASSIGNER, 200, undefined, { id: 'c-7', name: 'Alpha Trading' });

        expect(wrapper.find('[data-testid="deal-form-customer-id"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="deal-form-customer-fixed"]').text()).toBe('Alpha Trading');

        await wrapper.find('[data-testid="deal-form-title"]').setValue('Twelve pumps');
        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(JSON.parse(String(writeCall()![1].body)).customer_id).toBe('c-7');
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

        expect(body).toEqual({
            title: 'Fourteen pumps', details: 'Site visit on Tuesday.\nTwo floors.', source: 'employee_entry', service_type: 'product',
        });
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

    // ─────────────────────────────────────────── F-41 · 1.5: details (D-108)

    it('posts details as typed, and caps the box at §4.3’s 2000 characters', async () => {
        const wrapper = await mountModal();
        const box = wrapper.find('[data-testid="deal-form-details"]');

        expect(box.element.tagName).toBe('TEXTAREA');
        // The owner's «أ», 2026-10-11: the box stops at the limit; the
        // server's `max:2000` and the column's CHECK stay the rule.
        expect(box.attributes('maxlength')).toBe('2000');
        // Typed text may be in either language, whatever the screen's: the
        // box takes its direction from the text (rtl-ui-verifier, 1.5).
        expect(box.attributes('dir')).toBe('auto');

        await pickCustomer(wrapper);
        await box.setValue('Site visit on Tuesday.\nTwo floors.');
        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(JSON.parse(String(writeCall()![1].body)).details).toBe('Site visit on Tuesday.\nTwo floors.');
    });

    it('opens an edit with the deal’s details and sends a cleared box as null', async () => {
        const wrapper = await mountModal(DEAL);
        const box = wrapper.find('[data-testid="deal-form-details"]');

        expect((box.element as HTMLTextAreaElement).value).toBe('Site visit on Tuesday.\nTwo floors.');

        // `PATCH` takes null as "erase" (OpenAPI, #306).
        await box.setValue('');
        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(JSON.parse(String(writeCall()![1].body)).details).toBeNull();
    });

    it('renders a details refusal under its own box', async () => {
        const wrapper = await mountModal(DEAL, ASSIGNER, 200, async () => json(422, {
            error: {
                code: 'validation_failed',
                message: 'The given data was invalid.',
                details: [{ field: 'details', message: 'The details may not be longer than 2000 characters.' }],
            },
            meta: { request_id: 'r1' },
        }));

        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-form-details-error"]').text())
            .toBe('The details may not be longer than 2000 characters.');
    });

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
        // A-04: the browser turns Escape into the <dialog>'s own cancel event.
        wrapper.get('dialog').element.dispatchEvent(new Event('cancel', { cancelable: true }));
        await flushPromises();

        // §6.1: Escape closes dialogs "without discarding silently".
        expect(wrapper.find('[data-testid="deal-form-discard"]').exists()).toBe(true);
        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    // F-18 · 1.1, the owner's ruling (2026-09-24): the open list takes the
    // Escape, so one press closes the menu and not the dialog around it (§6.1).
    it('closes only the customer list on Escape, not the form', async () => {
        const wrapper = await mountModal();
        const customer = wrapper.find('[data-testid="deal-form-customer-id"]');

        await customer.trigger('focus');
        await flushPromises();

        // A-04: the open list prevents the key's default, which is what stops
        // the browser turning it into the dialog's cancel.
        const first = new KeyboardEvent('keydown', { key: 'Escape', cancelable: true, bubbles: true });
        customer.element.dispatchEvent(first);
        await flushPromises();

        expect(first.defaultPrevented).toBe(true);
        expect(customer.attributes('aria-expanded')).toBe('false');
        expect(wrapper.emitted('cancel')).toBeUndefined();

        // The list is closed now, so the next Escape is left to the dialog.
        const second = new KeyboardEvent('keydown', { key: 'Escape', cancelable: true, bubbles: true });
        customer.element.dispatchEvent(second);
        expect(second.defaultPrevented).toBe(false);

        wrapper.get('dialog').element.dispatchEvent(new Event('cancel', { cancelable: true }));
        await flushPromises();
        expect(wrapper.emitted('cancel')).toHaveLength(1);
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

    // ─────────────────────────────────────────────── after F-41 (F-42 · P6)

    it('opens as a native modal <dialog> named by its title (A-04)', async () => {
        const wrapper = await mountModal();
        const dialog = wrapper.get('dialog');

        expect(dialog.attributes('data-testid')).toBe('deal-form-modal');
        expect(dialog.attributes('aria-labelledby')).toBe('deal-form-title');
        expect(wrapper.find('[aria-modal="true"]').exists()).toBe(false);
    });

    it('turns off the browser’s autofill on the request title (DC-69)', async () => {
        const wrapper = await mountModal();

        expect(wrapper.get('[data-testid="deal-form-title"]').attributes('autocomplete')).toBe('off');
    });

    it('ties a refused field to its sentence, counts it, and focuses it (A-11)', async () => {
        const wrapper = await mountModal(null, ASSIGNER, 200);
        document.body.appendChild(wrapper.element);

        await wrapper.get('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        const error = wrapper.get('[data-testid="deal-form-customer-id-error"]');
        const field = wrapper.get('[data-testid="deal-form-customer-id"]');

        expect(error.attributes('id')).toBeTruthy();
        expect(field.attributes('aria-describedby')).toBe(error.attributes('id'));
        expect(field.attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('[data-testid="deal-form-error-count"]').text()).toBe('Fix 1 field before saving.');
        expect(document.activeElement).toBe(field.element);
        wrapper.unmount();
    });

});
