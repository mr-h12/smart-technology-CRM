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
    contact_title: null,
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

/** `D-104` as amended: the box offers the full words «أستاذ» / «أستاذة» in Arabic. */
const TITLES = [
    { code: 'mr', label_en: 'Mr.', label_ar: 'أستاذ', position: 1 },
    { code: 'mrs', label_en: 'Mrs.', label_ar: 'أستاذة', position: 2 },
];

function render(editing: Customer | null = null, locale = 'en') {
    return mount(CustomerFormModal, {
        props: { open: true, editing, sectors: SECTORS, titles: TITLES },
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

describe('CustomerFormModal — D-104 the contact person and their title', () => {
    it('names the field «الشخص المتواصل معه» and draws the title box before the name, empty first, in the screen language', () => {
        for (const [locale, label, mr, mrs, none] of [['ar', 'الشخص المتواصل معه', 'أستاذ', 'أستاذة', 'بدون لقب'], ['en', 'Contact person', 'Mr.', 'Mrs.', 'No title']]) {
            const view = render(null, locale);
            const box = view.find('[data-testid="customer-form-contact-title"]');

            expect(box.element.tagName).toBe('SELECT');
            expect(box.findAll('option').map((option) => [option.attributes('value'), option.text()])).toEqual([
                ['', none],  // A-50: a prompt, never a blank option
                ['mr', mr],
                ['mrs', mrs],
            ]);
            expect(box.attributes('aria-label')).toBe(locale === 'ar' ? 'اللقب' : 'Title');

            // Before the name, in document order — the row the employee reads.
            const html = view.html();
            expect(html.indexOf('customer-form-contact-title')).toBeLessThan(html.indexOf('customer-form-contact-person'));
            expect(view.text()).toContain(label);
        }
    });

    it('sends the chosen title on a create, and null when the empty choice clears it on an edit', async () => {
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => json(200, { data: EXISTING, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const creating = render(null);
        await creating.find('[data-testid="customer-form-name"]').setValue('Beta Medical');
        await creating.find('[data-testid="customer-form-contact-title"]').setValue('mrs');
        await creating.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        expect(sentBodies(fetchMock)[0]?.contact_title).toBe('mrs');

        const editing = render({ ...EXISTING, contact_title: 'mr' });
        await editing.find('[data-testid="customer-form-contact-title"]').setValue('');
        await editing.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        const body = sentBodies(fetchMock)[1] ?? {};
        expect(body).toHaveProperty('contact_title');
        expect(body.contact_title).toBeNull();
    });

    it('opens an edit on the saved title', () => {
        const view = render({ ...EXISTING, contact_title: 'mrs' });
        const box = view.find('[data-testid="customer-form-contact-title"]').element as HTMLSelectElement;

        expect(box.value).toBe('mrs');
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
        view.get('dialog').element.dispatchEvent(new Event('cancel', { cancelable: true }));
        await flushPromises();

        expect(view.find('[data-testid="customer-form-unsaved"]').exists()).toBe(true);
        expect(view.emitted('cancel')).toBeUndefined();
    });
});

/** F-42 · P4 — A-11, A-50 and A-02 (docs/ux-upgrade/plan.md). */
describe('CustomerFormModal — F-42 · P4', () => {
    function attached(editing: Customer | null = null) {
        return mount(CustomerFormModal, {
            props: { open: true, editing, sectors: SECTORS, titles: TITLES },
            global: { plugins: [createAppRouter(), createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } })] },
            attachTo: document.body,
        });
    }

    /** A-11: focus to the first invalid field, a count at the top, each error tied to its field. */
    it('focuses the first invalid field after a failed save, counts the fields, and ties each error by aria-describedby', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(422, {
            error: {
                code: 'validation_failed',
                message: 'The given data was invalid.',
                details: [
                    { field: 'email', code: 'invalid_email', message: 'This email address is not valid.' },
                    { field: 'start_date', code: 'invalid_date', message: 'This date is not valid.' },
                ],
            },
        })));

        const view = attached();
        await view.find('[data-testid="customer-form-name"]').setValue('Beta Medical');
        await view.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        // start_date is drawn before email, so it is the first invalid field.
        expect(document.activeElement?.id).toBe('customer-form-start_date');
        expect(view.find('[data-testid="customer-form-error-count"]').text()).toBe('Fix 2 fields before saving.');

        for (const field of ['start-date', 'email']) {
            const input = view.find(`[data-testid="customer-form-${field}"]`);
            const describedBy = input.attributes('aria-describedby') ?? '';

            expect(input.attributes('aria-invalid')).toBe('true');
            expect(describedBy).not.toBe('');
            expect(view.find(`#${describedBy}`).text()).toBe(view.find(`[data-testid="customer-form-${field}-error"]`).text());
        }

        view.unmount();
    });

    /** A-50: one marker — the word, in danger, with aria-required; no "*"; no blank option. */
    it('marks every required field with the word and aria-required, leaves no "*", and gives every option a label', () => {
        const view = render();
        const name = view.find('[data-testid="customer-form-name"]');

        expect(view.find('[data-testid="customer-form-name-required"]').text()).toBe('(required)');
        expect(name.attributes('aria-required')).toBe('true');
        expect(view.findAll('[aria-required="true"]')).toHaveLength(1);
        expect(view.text()).not.toContain('*');
        expect(view.findAll('option').filter((option) => option.text().trim() === '')).toEqual([]);
    });

    /** A-02: a create that found similar customers turns the dialog into an edit of the saved row. */
    it('after a create answered with similar customers, retitles to an edit, focuses the warning, and the next Save patches the new id', async () => {
        const created = { ...EXISTING, id: 'c7', name: 'Alpha Trading' };
        const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => (init?.method === 'POST'
            ? json(201, { data: created, meta: { similar_customers: [{ ...EXISTING, id: 'c9', name: 'Alpha Trade' }] } })
            : json(200, { data: created, meta: {} })));
        vi.stubGlobal('fetch', fetchMock);

        const view = attached();
        await view.find('[data-testid="customer-form-name"]').setValue('Alpha Trading');
        await view.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('#customer-form-title').text()).toBe('Edit customer “Alpha Trading”');
        expect(document.activeElement).toBe(view.find('[data-testid="customer-form-similar"]').element);

        await view.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        const [, second] = fetchMock.mock.calls;
        expect(second?.[1]?.method).toBe('PATCH');
        expect(String(second?.[0])).toMatch(/\/customers\/c7$/);
        expect(fetchMock.mock.calls.filter((call) => call[1]?.method === 'POST')).toHaveLength(1);

        view.unmount();
    });
});

/** `D-113` (F-44 · 2): the sector is typed, with the list as suggestions; the server turns it into a code. */
describe('CustomerFormModal — a typed sector', () => {
    it('offers the sectors as suggestions in the screen language, not as a closed list', () => {
        const view = render(null, 'ar');
        const field = view.find('[data-testid="customer-form-sector"]');

        expect(field.element.tagName).toBe('INPUT');
        const list = view.find(`datalist#${field.attributes('list')}`);
        expect(list.exists()).toBe(true);
        expect(list.findAll('option').map((option) => option.attributes('value'))).toEqual(['تجاري', 'طبي']);
    });

    it('opens an edit on the sector\'s label, and on the code itself when the list no longer names it', () => {
        expect((render(EXISTING, 'ar').find('[data-testid="customer-form-sector"]').element as HTMLInputElement).value).toBe('تجاري');
        expect((render({ ...EXISTING, sector: 'retired' }, 'en').find('[data-testid="customer-form-sector"]').element as HTMLInputElement).value)
            .toBe('retired');
    });

    it('sends a typed sector as typed, and an untouched one as its stored code', async () => {
        const fetchMock = vi.fn(async (_url: string, _init?: RequestInit) => json(200, { data: EXISTING, meta: {} }));
        vi.stubGlobal('fetch', fetchMock);

        const creating = render(null, 'ar');
        await creating.find('[data-testid="customer-form-name"]').setValue('Delta Build');
        await creating.find('[data-testid="customer-form-sector"]').setValue('مقاولات');
        await creating.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        const editing = render(EXISTING, 'ar');
        await editing.find('[data-testid="customer-form"]').trigger('submit');
        await flushPromises();

        const [created, edited] = sentBodies(fetchMock);
        expect(created?.sector).toBe('مقاولات');
        expect(edited?.sector ?? 'commercial').toBe('commercial');
    });
});
