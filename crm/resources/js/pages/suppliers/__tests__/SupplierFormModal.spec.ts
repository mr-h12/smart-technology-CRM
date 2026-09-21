import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import SupplierFormModal from '@/pages/suppliers/SupplierFormModal.vue';
import type { Supplier } from '@/services/suppliers';

/**
 * Module 4, Point 4.2 — §7.1's supplier add/edit form.
 *
 * ── What is proved here, and what is proved against the server ─────────────
 *
 * `D-67`: the SPA never owns a rule. Who may write is `catalog.manage` on the
 * route and is proved by `SupplierWriteEndpointTest`; that `linked_quotations`
 * is refused is `SaveSupplierRequest`'s `prohibited` and is proved there. What
 * is proved here is what the screen *sends* and what it *draws*: Design System
 * §5.2's required marker and unsaved-change warning, §6.1's near-the-field
 * server errors, and §7.1's fields — no more of them and no fewer.
 *
 * ── §3.7's write row is one cell, so there is one permission and no action ──
 *
 * "create · edit · deactivate · set colour" is a single cell, and the server
 * publishes no `/deactivate` and no `/color` route. Deactivation and the colour
 * are therefore ordinary fields on the PATCH, and the test below reads the
 * request body to prove the screen agrees.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const EXISTING: Supplier = {
    id: 's1',
    name: 'Alpha Supply',
    type: 'distributor',
    color_rating: 'red',
    phone: '0100',
    contact_person: 'Sara',
    has_open_account: true,
    is_incomplete: false,
    is_active: true,
    created_at: '2026-08-30T00:00:00+00:00',
    updated_at: '2026-08-30T00:00:00+00:00',
};

function render(editing: Supplier | null = null, locale = 'en') {
    return mount(SupplierFormModal, {
        props: { open: true, editing },
        global: {
            plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })],
        },
    });
}

/** The request bodies this form actually sent, decoded. */
function sentBodies(mock: ReturnType<typeof vi.fn>): Record<string, unknown>[] {
    return mock.mock.calls
        .filter((call) => typeof call[1]?.body === 'string')
        .map((call) => JSON.parse(String(call[1].body)) as Record<string, unknown>);
}

/** The method and URL of the nth request, so an action route would be visible. */
function requestOf(mock: ReturnType<typeof vi.fn>, index = 0): { url: string; method: string } {
    const call = mock.mock.calls[index];

    expect(call).toBeDefined();

    return { url: String(call?.[0]), method: String((call?.[1] as RequestInit | undefined)?.method ?? 'GET') };
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('SupplierFormModal — §7.1 fields', () => {
    it('draws every user-entered field, and no control for the derived one', () => {
        const view = render();

        for (const field of ['name', 'type', 'color-rating', 'phone', 'contact-person', 'has-open-account', 'is-active']) {
            expect(view.find(`[data-testid="supplier-form-${field}"]`).exists()).toBe(true);
        }

        // §7.1 marks `linked_quotations` "Automatic" and `SaveSupplierRequest`
        // answers it with a 422. A control here would be a control that cannot
        // save.
        expect(view.find('[data-testid="supplier-form-linked-quotations"]').exists()).toBe(false);
    });

    /** §6.3's "explicit required marker". `SaveSupplierRequest` requires exactly one field. */
    it('marks name required and nothing else', () => {
        const view = render();

        expect(view.find('[data-testid="supplier-form-name-required"]').exists()).toBe(true);
        expect(view.find('[data-testid="supplier-form-phone-required"]').exists()).toBe(false);
    });

    it('refuses a whitespace-only name without asking the server', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: EXISTING }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await view.find('[data-testid="supplier-form-name"]').setValue('   ');
        await view.find('[data-testid="supplier-form"]').trigger('submit');
        await flushPromises();

        // `regex:/\S/` beside `required` on the server, because the table's
        // CHECK would answer a blank name with a 500. Checked here as a
        // courtesy; the server refuses regardless.
        expect(fetchMock).not.toHaveBeenCalled();
        expect(view.find('[data-testid="supplier-form-name-error"]').exists()).toBe(true);
    });
});

describe('SupplierFormModal — what it sends', () => {
    it('creates with the trimmed name, the §7.1 defaults, and null for an unset type', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: EXISTING }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await view.find('[data-testid="supplier-form-name"]').setValue('  Beta Traders  ');
        await view.find('[data-testid="supplier-form"]').trigger('submit');
        await flushPromises();

        const { url, method } = requestOf(fetchMock);

        expect(url).toContain('/suppliers');
        expect(method).toBe('POST');
        expect(sentBodies(fetchMock)[0]).toEqual({
            name: 'Beta Traders',
            // The column is nullable and "" is not the same absence.
            type: null,
            // The table's own defaults, restated so a create is explicit about
            // what §7.1 calls "New / not yet rated".
            color_rating: 'white',
            phone: null,
            contact_person: null,
            has_open_account: false,
            is_active: true,
        });
    });

    it('edits through PATCH on the row, never through an action route', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: EXISTING }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render(EXISTING);
        await view.find('[data-testid="supplier-form-name"]').setValue('Alpha Supply Co');
        await view.find('[data-testid="supplier-form"]').trigger('submit');
        await flushPromises();

        const { url, method } = requestOf(fetchMock);

        expect(method).toBe('PATCH');
        expect(url).toContain('/suppliers/s1');
        expect(url).not.toContain('/deactivate');
        expect(sentBodies(fetchMock)[0]).toMatchObject({ name: 'Alpha Supply Co' });
    });

    /** §3.7: deactivate and set colour live in the same cell as edit, so they are fields. */
    it('sends deactivation and the colour as fields on the same PATCH', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: EXISTING }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render(EXISTING);
        await view.find('[data-testid="supplier-form-color-rating"]').setValue('green');
        await view.find('[data-testid="supplier-form-is-active"]').setValue(false);
        await view.find('[data-testid="supplier-form"]').trigger('submit');
        await flushPromises();

        expect(sentBodies(fetchMock)).toHaveLength(1);
        expect(sentBodies(fetchMock)[0]).toMatchObject({ color_rating: 'green', is_active: false });
    });

    it('opens an edit with the record already in the boxes', () => {
        const view = render(EXISTING);

        expect((view.find('[data-testid="supplier-form-name"]').element as HTMLInputElement).value).toBe('Alpha Supply');
        expect((view.find('[data-testid="supplier-form-type"]').element as HTMLSelectElement).value).toBe('distributor');
        expect((view.find('[data-testid="supplier-form-color-rating"]').element as HTMLSelectElement).value).toBe('red');
        expect((view.find('[data-testid="supplier-form-has-open-account"]').element as HTMLInputElement).checked).toBe(true);
    });
});

describe('SupplierFormModal — §6.1 server refusals', () => {
    /** `OpenAPI §5`: `details[]` names the field, so the sentence lands on the input. */
    it('puts a 422 sentence on the field the server named', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(422, {
            error: {
                code: 'validation_failed',
                message: 'The given data was invalid.',
                details: [{ field: 'name', code: 'invalid', message: 'The name has already been taken.' }],
            },
        })));

        const view = render();
        await view.find('[data-testid="supplier-form-name"]').setValue('Alpha Supply');
        await view.find('[data-testid="supplier-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="supplier-form-name-error"]').text()).toContain('already been taken');
        expect(view.find('[data-testid="supplier-form-error"]').exists()).toBe(false);
    });

    /**
     * A form-level refusal is a **key**, not the server's sentence.
     *
     * `UserFormModal` settled this: a server sentence was localised once, when
     * the request was answered, so a banner holding one stops re-translating
     * when the reader switches AR/EN. A field sentence has no key to fall back
     * on and is used as sent; a form-level one does.
     */
    it('draws a translated refusal for a 403 rather than the server sentence', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(403, { error: { code: 'forbidden', message: 'This action is unauthorized.' } })));

        const view = render();
        await view.find('[data-testid="supplier-form-name"]').setValue('Beta');
        await view.find('[data-testid="supplier-form"]').trigger('submit');
        await flushPromises();

        const banner = view.find('[data-testid="supplier-form-error"]');

        expect(banner.text()).toBe(en.suppliers.form.forbidden);
        expect(banner.text()).not.toContain('unauthorized');
    });

    it('draws a translated sentence when the server cannot be reached at all', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('Failed to fetch'); }));

        const view = render();
        await view.find('[data-testid="supplier-form-name"]').setValue('Beta');
        await view.find('[data-testid="supplier-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="supplier-form-error"]').text()).toBe(en.suppliers.form.unreachable);
    });
});

describe('SupplierFormModal — §5.2 unsaved changes', () => {
    it('asks before discarding something typed, and closes at once when nothing was', async () => {
        const untouched = render();
        await untouched.find('[data-testid="supplier-form-cancel"]').trigger('click');

        expect(untouched.emitted('cancel')).toBeTruthy();
        expect(untouched.find('[data-testid="supplier-form-unsaved"]').exists()).toBe(false);

        const typed = render();
        await typed.find('[data-testid="supplier-form-name"]').setValue('Beta');
        await typed.find('[data-testid="supplier-form-cancel"]').trigger('click');

        expect(typed.emitted('cancel')).toBeFalsy();
        expect(typed.find('[data-testid="supplier-form-unsaved"]').exists()).toBe(true);

        await typed.find('[data-testid="supplier-form-discard"]').trigger('click');

        expect(typed.emitted('cancel')).toBeTruthy();
    });

    /** §6.1: "Escape closes dialogs/menus without discarding silently." */
    it('takes the same path on Escape as on Cancel', async () => {
        const view = render();
        await view.find('[data-testid="supplier-form-name"]').setValue('Beta');
        await view.find('[data-testid="supplier-form"]').trigger('keydown.escape');

        expect(view.emitted('cancel')).toBeFalsy();
        expect(view.find('[data-testid="supplier-form-unsaved"]').exists()).toBe(true);
    });
});

describe('SupplierFormModal — both languages', () => {
    /**
     * Arabic script, not "different from English". Point 4.0's chip test could
     * not tell a translation from a raw key, and this is the check that can:
     * `suppliers.form.createTitle` contains no Arabic letter, a translation does.
     */
    it('draws its title and its colour options in Arabic', () => {
        const view = render(null, 'ar');
        const text = view.find('[data-testid="supplier-form"]').text();

        expect(text).toMatch(/[؀-ۿ]/);
        expect(text).not.toContain('suppliers.form');
    });
});
