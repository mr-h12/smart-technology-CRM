import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import DealFormModal from '@/pages/deals/DealFormModal.vue';
import type { Deal } from '@/services/deals';
import type { Customer } from '@/services/customers';

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

function mountModal(editing: Deal | null = null) {
    const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } });

    return mount(DealFormModal, {
        props: { open: true, editing, customers: CUSTOMERS },
        global: { plugins: [i18n] },
    });
}

function bodyOf(fetchMock: ReturnType<typeof vi.fn>, call = 0): unknown {
    const [, init] = fetchMock.mock.calls[call] as [string, RequestInit];

    return JSON.parse(String(init.body));
}

describe('the deal form dialog', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetchMock = vi.fn(async () => json(201, envelope(DEAL)));
        vi.stubGlobal('fetch', fetchMock);
    });

    // ──────────────────────────────────────────────────────────────── creating

    it('posts §4.3’s five fields, turning an unset one into null', async () => {
        const wrapper = mountModal();

        await wrapper.find('[data-testid="deal-form-customer-id"]').setValue('c1');
        await wrapper.find('[data-testid="deal-form-title"]').setValue('Twelve pumps');
        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        // "" means "not given", which on the wire is null and never an empty
        // string — the server's own vocabularies have no empty member.
        expect(bodyOf(fetchMock)).toEqual({
            customer_id: 'c1',
            owner_id: null,
            title: 'Twelve pumps',
            source: null,
            service_type: null,
        });
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });

    it('refuses to post without a customer, and says so before the round trip', async () => {
        const wrapper = mountModal();

        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="deal-form-customer-id-error"]').text()).toBe('The customer is required.');
    });

    // ───────────────────────────────────────────────────────────────── editing

    it('opens filled from the row it was given', async () => {
        const wrapper = mountModal(DEAL);

        expect((wrapper.find('[data-testid="deal-form-title"]').element as HTMLInputElement).value)
            .toBe('Twelve pumps');
        expect(wrapper.text()).toContain('Edit deal');
    });

    it('never sends customer_id or owner_id on an edit, because PATCH prohibits both', async () => {
        const wrapper = mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form-title"]').setValue('Fourteen pumps');
        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        const body = bodyOf(fetchMock);

        expect(body).toEqual({ title: 'Fourteen pumps', source: 'employee_entry', service_type: 'product' });
        // Sending the unchanged value would 422 the save outright.
        expect(body).not.toHaveProperty('customer_id');
        expect(body).not.toHaveProperty('owner_id');
    });

    it('draws neither control on an edit rather than drawing them disabled', async () => {
        const wrapper = mountModal(DEAL);

        // A disabled control invites the question "why", and the answer is a
        // different screen: the owner moves through `assign_owner`'s own route.
        expect(wrapper.find('[data-testid="deal-form-customer-id"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="deal-form-owner-id"]').exists()).toBe(false);
    });

    it('draws both on a create', async () => {
        const wrapper = mountModal();

        expect(wrapper.find('[data-testid="deal-form-customer-id"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="deal-form-owner-id"]').exists()).toBe(true);
    });

    // ───────────────────────────────────────────────────── the server's refusals

    it('renders a field refusal as the server’s own sentence', async () => {
        fetchMock.mockResolvedValue(json(422, {
            error: {
                code: 'validation_failed',
                message: 'The given data was invalid.',
                details: [{ field: 'title', message: 'The request may not be longer than 255 characters.' }],
            },
            meta: { request_id: 'r1' },
        }));

        const wrapper = mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        // Already localised by the server; rendered verbatim rather than
        // re-translated into a guess at what it meant.
        expect(wrapper.find('[data-testid="deal-form-title-error"]').text())
            .toBe('The request may not be longer than 255 characters.');
        expect(wrapper.emitted('saved')).toBeUndefined();
    });

    it('renders a 403 as a form-level key, not as a field error', async () => {
        fetchMock.mockResolvedValue(json(403, { error: { code: 'forbidden' }, meta: { request_id: 'r1' } }));

        const wrapper = mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-form-error"]').text())
            .toBe('You do not have permission to save this deal.');
    });

    it('says the server could not be reached when the request never lands', async () => {
        fetchMock.mockRejectedValue(new TypeError('network down'));

        const wrapper = mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-testid="deal-form-error"]').text())
            .toBe('The server could not be reached. Nothing was saved.');
    });

    // ─────────────────────────────────────────────── §5.2's unsaved-change warning

    it('closes straight away when nothing was changed', async () => {
        const wrapper = mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form-cancel"]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(wrapper.find('[data-testid="deal-form-discard"]').exists()).toBe(false);
    });

    it('asks before discarding an edit, in the dialog and not through confirm()', async () => {
        const wrapper = mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form-title"]').setValue('Changed');
        await wrapper.find('[data-testid="deal-form-cancel"]').trigger('click');

        // A native confirm() is neither translatable nor mirrored for RTL.
        expect(wrapper.find('[data-testid="deal-form-discard"]').exists()).toBe(true);
        expect(wrapper.emitted('cancel')).toBeUndefined();

        await wrapper.find('[data-testid="deal-form-discard-confirm"]').trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('takes the same path on Escape as on Cancel', async () => {
        const wrapper = mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form-title"]').setValue('Changed');
        await wrapper.find('[data-testid="deal-form"]').trigger('keydown.escape');

        // §6.1: Escape closes dialogs "without discarding silently".
        expect(wrapper.find('[data-testid="deal-form-discard"]').exists()).toBe(true);
        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('keeps editing when the discard is declined', async () => {
        const wrapper = mountModal(DEAL);

        await wrapper.find('[data-testid="deal-form-title"]').setValue('Changed');
        await wrapper.find('[data-testid="deal-form-cancel"]').trigger('click');
        await wrapper.find('[data-testid="deal-form-discard-keep"]').trigger('click');

        expect(wrapper.find('[data-testid="deal-form-discard"]').exists()).toBe(false);
        expect(wrapper.emitted('cancel')).toBeUndefined();
        expect((wrapper.find('[data-testid="deal-form-title"]').element as HTMLInputElement).value).toBe('Changed');
    });

    it('is dirty against what it opened with, not against blank', async () => {
        const wrapper = mountModal(DEAL);

        // The dialog opened with a title already in it; that is not a change.
        await wrapper.find('[data-testid="deal-form-cancel"]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });
});
