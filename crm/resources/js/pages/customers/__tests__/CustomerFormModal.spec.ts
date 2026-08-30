import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import CustomerFormModal from '@/pages/customers/CustomerFormModal.vue';
import type { Customer } from '@/services/customers';
import { createAppRouter } from '@/router';

/**
 * Module 3, Point 4.3 — §4.2's add/edit form.
 *
 * ── What is proved here, and what is proved against the server ─────────────
 *
 * `D-67`: the SPA never owns a rule. Every refusal below — the three
 * prohibited columns, the row scope, who may create — is the server's and is
 * proved by `CustomerWriteEndpointTest`. What is proved here is what the
 * screen *sends* and what it *draws*: Design System §5.2's required markers,
 * inline validation and unsaved-change warning, §6.1's near-the-field server
 * errors, §4.5's read-only status and §10.2's yellow warning.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const EXISTING: Customer = {
    id: 'c1',
    name: 'Alpha Trading',
    customer_status: 'prospect',
    sector: 'commercial',
    region: 'Cairo',
    contact_person: 'Mona Adel',
    phone: '+20 100 000 0000',
    phone2: null,
    whatsapp: null,
    email: 'mona@alpha.test',
    sales_owner_id: 'u1',
    start_date: '2024-01-15',
    notes: null,
    is_archived: false,
    is_incomplete: false,
    created_at: '2026-01-01T09:00:00Z',
    updated_at: '2026-01-01T09:00:00Z',
};

const SECTORS = [
    { code: 'commercial', label_en: 'Commercial', label_ar: 'تجاري', position: 1 },
    { code: 'medical', label_en: 'Medical', label_ar: 'طبي', position: 2 },
];

function render(editing: Customer | null = null, locale = 'en') {
    return mount(CustomerFormModal, {
        props: { open: true, editing, sectors: SECTORS },
        global: {
            // §10.2's similar customers are `RouterLink`s as of Point 4.4;
            // without a router they render as nothing and the assertions below
            // would still pass.
            plugins: [createAppRouter(), createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })],
        },
    });
}

/** The request bodies this form actually sent, decoded. */
function sentBodies(mock: ReturnType<typeof vi.fn>): Record<string, unknown>[] {
    return mock.mock.calls
        .filter((call) => typeof call[1]?.body === 'string')
        .map((call) => JSON.parse(String(call[1].body)) as Record<string, unknown>);
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('CustomerFormModal — §4.2 fields and §6.3 controls', () => {
    /** §6.3: "explicit required marker". §4.2 marks exactly one field required. */
    it('renders every user-entered field and marks name required and nothing else', () => {
        const view = render();

        for (const field of [
            'name', 'sector', 'region', 'contact-person',
            'phone', 'phone2', 'whatsapp', 'email', 'start-date', 'notes',
        ]) {
            expect(view.find(`[data-testid="customer-form-${field}"]`).exists()).toBe(true);
        }

        expect(view.find('[data-testid="customer-form-name-required"]').exists()).toBe(true);
        expect(view.find('[data-testid="customer-form-email-required"]').exists()).toBe(false);
    });

    /** `sales_owner_id` has its own route and its own permission (§3.3, Point 3.5). */
    it('offers no owner control, because assignment is a separate permission', () => {
        expect(render().find('[data-testid="customer-form-sales-owner-id"]').exists()).toBe(false);
    });

    /**
     * §4.5 · `D-49` — "read-only in the UI, with an icon explaining the reason".
     * A record being created has no derived status yet, so there is nothing to show.
     */
    it('shows the derived status read-only with its reason when editing, and not when creating', () => {
        const editing = render(EXISTING);

        expect(editing.find('[data-testid="customer-form-status"]').exists()).toBe(true);
        expect(editing.find('[data-testid="customer-form-status-reason"]').exists()).toBe(true);
        expect(editing.find('input[data-testid="customer-form-status"]').exists()).toBe(false);

        expect(render(null).find('[data-testid="customer-form-status"]').exists()).toBe(false);
    });

    it('fills the fields from the record being edited and leaves them blank on a create', () => {
        expect((render(EXISTING).find('[data-testid="customer-form-name"]').element as HTMLInputElement).value)
            .toBe('Alpha Trading');
        expect((render(null).find('[data-testid="customer-form-name"]').element as HTMLInputElement).value)
            .toBe('');
    });
});

describe('CustomerFormModal — what it sends', () => {
    /** §5.2: "inline validation". A blank name is refused before a round trip, and a real one is not. */
    it('refuses a blank name without asking the server, and sends once the name is there', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: EXISTING, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await view.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="customer-form-name-error"]').exists()).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();

        await view.find('[data-testid="customer-form-name"]').setValue('Beta Medical');
        await view.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(view.find('[data-testid="customer-form-name-error"]').exists()).toBe(false);
    });

    it('posts a create and patches an edit', async () => {
        // Declared, not inferred: `vi.fn(async () => …)` types `mock.calls` as
        // the empty tuple, and every index into it becomes a type error.
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => json(200, { data: EXISTING, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const creating = render(null);
        await creating.find('[data-testid="customer-form-name"]').setValue('Beta Medical');
        await creating.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        expect(String(fetchMock.mock.calls[0]?.[0])).toMatch(/\/customers$/);
        expect(fetchMock.mock.calls[0]?.[1]?.method).toBe('POST');

        const editing = render(EXISTING);
        await editing.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        expect(String(fetchMock.mock.calls[1]?.[0])).toMatch(/\/customers\/c1$/);
        expect(fetchMock.mock.calls[1]?.[1]?.method).toBe('PATCH');
    });

    /**
     * §4.5 · `D-49` · §3.3 — the four the boundary answers with a 422.
     * `SaveCustomerRequest` refuses them regardless; sending them would simply
     * turn every save into a 422.
     */
    it('never sends the three derived columns, nor the owner on an edit', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: EXISTING, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render(EXISTING);
        await view.find('[data-testid="customer-form-name"]').setValue('Alpha Trading Co');
        await view.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        const body = sentBodies(fetchMock)[0] ?? {};

        // The half that proves the assertion is not vacuous: the write did happen.
        expect(body.name).toBe('Alpha Trading Co');

        for (const forbidden of ['customer_status', 'is_archived', 'is_incomplete', 'sales_owner_id']) {
            expect(body).not.toHaveProperty(forbidden);
        }
    });
});

describe('CustomerFormModal — §6.1 server refusals and §10.2 duplicates', () => {
    /**
     * §6.1 — "Display server validation near the affected field and preserve
     * entered values on validation failure." `ApiError.messageFor()` carries
     * the server's own localised sentence, so the screen never writes a second
     * copy of a rule.
     */
    it('puts the server sentence beside the named field and keeps what was typed', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(422, {
            error: {
                code: 'validation_failed',
                message: 'The given data was invalid.',
                details: [{ field: 'email', code: 'invalid_email', message: 'This email address is not valid.' }],
            },
        })));

        const view = render();
        await view.find('[data-testid="customer-form-name"]').setValue('Beta Medical');
        await view.find('[data-testid="customer-form-email"]').setValue('not-an-email');
        await view.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="customer-form-email-error"]').text())
            .toContain('This email address is not valid.');
        expect((view.find('[data-testid="customer-form-name"]').element as HTMLInputElement).value)
            .toBe('Beta Medical');
    });

    /**
     * §10.2 · `D-35` · §6.4 — "yellow warning listing the similar customers",
     * "No blocking". The server has already written the row when this arrives.
     */
    it('lists the similar customers as a warning and still reports the save', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(201, {
            data: EXISTING,
            meta: { similar_customers: [{ ...EXISTING, id: 'c9', name: 'Alpha Trade' }] },
        })));

        const view = render();
        await view.find('[data-testid="customer-form-name"]').setValue('Alpha Trading');
        await view.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        const warning = view.find('[data-testid="customer-form-similar"]');

        expect(warning.exists()).toBe(true);
        expect(warning.text()).toContain('Alpha Trade');
        expect(view.emitted('saved')).toHaveLength(1);

        // §10.2: "or open the existing customer instead" — Point 4.4's half.
        expect(view.find('[data-testid="customer-form-similar-link"]').attributes('href')).toBe('/customers/c9');
    });

    it('raises no duplicate warning when the server named none', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(201, { data: EXISTING, meta: {} })));

        const view = render();
        await view.find('[data-testid="customer-form-name"]').setValue('Zeta Industrial');
        await view.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="customer-form-similar"]').exists()).toBe(false);
        expect(view.emitted('saved')).toHaveLength(1);
    });
});

describe('CustomerFormModal — Design System §5.2 unsaved-change warning', () => {
    /**
     * §5.2's Form/Builder requires an "unsaved-change warning"; §6.1 requires
     * that Escape "closes dialogs/menus without discarding silently". Both are
     * the same rule: work is not thrown away without being mentioned.
     */
    it('warns before discarding an edited form, and closes at once when nothing was typed', async () => {
        const untouched = render();
        await untouched.find('[data-testid="customer-form-cancel"]').trigger('click');

        expect(untouched.find('[data-testid="customer-form-unsaved"]').exists()).toBe(false);
        expect(untouched.emitted('cancel')).toHaveLength(1);

        const edited = render();
        await edited.find('[data-testid="customer-form-name"]').setValue('Half a name');
        await edited.find('[data-testid="customer-form-cancel"]').trigger('click');

        expect(edited.find('[data-testid="customer-form-unsaved"]').exists()).toBe(true);
        expect(edited.emitted('cancel')).toBeUndefined();
    });

    it('discards only when the warning is confirmed, and stays open when it is dismissed', async () => {
        const view = render();
        await view.find('[data-testid="customer-form-name"]').setValue('Half a name');
        await view.find('[data-testid="customer-form-cancel"]').trigger('click');

        await view.find('[data-testid="customer-form-keep-editing"]').trigger('click');
        expect(view.emitted('cancel')).toBeUndefined();
        expect(view.find('[data-testid="customer-form-unsaved"]').exists()).toBe(false);

        await view.find('[data-testid="customer-form-cancel"]').trigger('click');
        await view.find('[data-testid="customer-form-discard"]').trigger('click');
        expect(view.emitted('cancel')).toHaveLength(1);
    });

    /** §6.1 — Escape takes the same path as Cancel, not a shorter one. */
    it('does not let Escape discard an edited form silently', async () => {
        const view = render();
        await view.find('[data-testid="customer-form-name"]').setValue('Half a name');
        await view.find('[data-testid="customer-form"]').trigger('keydown.escape');

        expect(view.find('[data-testid="customer-form-unsaved"]').exists()).toBe(true);
        expect(view.emitted('cancel')).toBeUndefined();
    });
});
