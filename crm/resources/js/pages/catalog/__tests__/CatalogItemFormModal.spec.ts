import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import CatalogItemFormModal from '@/pages/catalog/CatalogItemFormModal.vue';
import type { CatalogItem } from '@/services/catalog';

/**
 * Module 4, Point 4.4 — §7.3's catalog add/edit form.
 *
 * ── The two tabs are two forms, because §7.3 lists two field sets ──────────
 *
 * "Product code · Product name · Category · Unit · Description" against
 * "Service type · Service description · Providing team/company · Active ·
 * Notes". `SaveCatalogItemRequest` turns that into three `required_if` rules,
 * so the form that draws the wrong set is a form the server refuses.
 *
 * ── `kind` is always sent, and that is a rule not a habit ──────────────────
 *
 * `required_if:kind,product` fires **only when `kind` is in the payload**. A
 * PATCH of `{"unit": null}` alone therefore blanks a product's unit, because a
 * partial update has no view of the stored row — the boundary says so in its
 * own docblock and the gap is recorded in `CHECKLIST.md`. This form's answer is
 * to name the kind on every write, create and edit alike, so the conditional
 * rules always have something to fire on. The assertion below is the guard on
 * that promise.
 *
 * ── What is proved here, and what is proved against the server ────────────
 *
 * `D-67`: the SPA never owns a rule. Who may write is `catalog.manage` on the
 * route and is proved by `CatalogWriteEndpointTest`; that price, cost and
 * margin are refused is `prohibited` and is proved there. What is proved here
 * is what the screen sends and what it draws.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const PRODUCT: CatalogItem = {
    id: 'c1',
    kind: 'product',
    name: 'Copper cable',
    product_code: 'P-100',
    category: 'wiring',
    unit: 'metre',
    service_type: null,
    company: 'Acme Industrial',
    description: 'Single core',
    notes: null,
    is_active: true,
    created_at: '2026-08-30T00:00:00+00:00',
    updated_at: '2026-08-30T00:00:00+00:00',
};

const SERVICE: CatalogItem = {
    ...PRODUCT,
    id: 'c2',
    kind: 'service',
    name: null,
    product_code: null,
    category: null,
    unit: null,
    service_type: 'installation',
    notes: 'Two technicians',
};

function render(editing: CatalogItem | null = null, kind: 'product' | 'service' = 'product', locale = 'en') {
    return mount(CatalogItemFormModal, {
        props: { open: true, editing, kind },
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

function requestOf(mock: ReturnType<typeof vi.fn>, index = 0): { url: string; method: string } {
    const call = mock.mock.calls[index];

    expect(call).toBeDefined();

    return { url: String(call?.[0]), method: String((call?.[1] as RequestInit | undefined)?.method ?? 'GET') };
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('CatalogItemFormModal — §7.3 draws two field sets, not one union', () => {
    it('draws the product fields for a product and none of the service ones', () => {
        const view = render(null, 'product');

        for (const field of ['name', 'product-code', 'category', 'unit', 'company', 'description', 'is-active']) {
            expect(view.find(`[data-testid="catalog-form-${field}"]`).exists()).toBe(true);
        }

        expect(view.find('[data-testid="catalog-form-service-type"]').exists()).toBe(false);
        // §7.3 lists Notes on the Service row only.
        expect(view.find('[data-testid="catalog-form-notes"]').exists()).toBe(false);
    });

    it('draws the service fields for a service and none of the product ones', () => {
        const view = render(null, 'service');

        for (const field of ['service-type', 'description', 'company', 'notes', 'is-active']) {
            expect(view.find(`[data-testid="catalog-form-${field}"]`).exists()).toBe(true);
        }

        for (const field of ['unit', 'product-code', 'category']) {
            expect(view.find(`[data-testid="catalog-form-${field}"]`).exists()).toBe(false);
        }
    });

    /** §6.3's "explicit required marker", against `SaveCatalogItemRequest`'s three `required_if`s. */
    it('marks exactly the fields the server makes conditional on the kind', () => {
        const product = render(null, 'product');

        expect(product.find('[data-testid="catalog-form-name-required"]').exists()).toBe(true);
        expect(product.find('[data-testid="catalog-form-unit-required"]').exists()).toBe(true);
        expect(product.find('[data-testid="catalog-form-category-required"]').exists()).toBe(false);

        const service = render(null, 'service');

        expect(service.find('[data-testid="catalog-form-service-type-required"]').exists()).toBe(true);
        expect(service.find('[data-testid="catalog-form-description-required"]').exists()).toBe(false);
    });

    /**
     * §7.3's first clause and `D-21`. All three are `prohibited` on the server —
     * a 422, not a silent drop — so a control for any of them could never save.
     */
    it('offers no price, cost or margin control', () => {
        const view = render(null, 'product');

        for (const field of ['price', 'cost', 'margin']) {
            expect(view.find(`[data-testid="catalog-form-${field}"]`).exists()).toBe(false);
        }

        expect(view.find('[data-testid="catalog-form"]').text()).not.toMatch(/price|cost|margin/i);
    });
});

describe('CatalogItemFormModal — the client mirrors the three required_if rules', () => {
    it('refuses a product with no name without asking the server', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: PRODUCT }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render(null, 'product');
        await view.find('[data-testid="catalog-form-unit"]').setValue('metre');
        await view.find('[data-testid="catalog-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(view.find('[data-testid="catalog-form-name-error"]').exists()).toBe(true);
    });

    it('refuses a product with no unit', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: PRODUCT }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render(null, 'product');
        await view.find('[data-testid="catalog-form-name"]').setValue('Copper cable');
        await view.find('[data-testid="catalog-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(view.find('[data-testid="catalog-form-unit-error"]').exists()).toBe(true);
    });

    it('refuses a service with no service type', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: SERVICE }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render(null, 'service');
        await view.find('[data-testid="catalog-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(view.find('[data-testid="catalog-form-service-type-error"]').exists()).toBe(true);
    });

    /** A whitespace-only name is what the server's `regex:/\S/` exists for. */
    it('refuses a whitespace-only product name', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: PRODUCT }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render(null, 'product');
        await view.find('[data-testid="catalog-form-name"]').setValue('   ');
        await view.find('[data-testid="catalog-form-unit"]').setValue('metre');
        await view.find('[data-testid="catalog-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(view.find('[data-testid="catalog-form-name-error"]').exists()).toBe(true);
    });
});

describe('CatalogItemFormModal — what it sends', () => {
    it('creates a product with kind named and empty boxes sent as null', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: PRODUCT }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render(null, 'product');
        await view.find('[data-testid="catalog-form-name"]').setValue('  Copper cable  ');
        await view.find('[data-testid="catalog-form-unit"]').setValue('metre');
        await view.find('[data-testid="catalog-form"]').trigger('submit');
        await flushPromises();

        const { url, method } = requestOf(fetchMock);

        expect(url).toContain('/catalog-items');
        expect(method).toBe('POST');
        expect(sentBodies(fetchMock)[0]).toEqual({
            kind: 'product',
            name: 'Copper cable',
            product_code: null,
            category: null,
            unit: 'metre',
            company: null,
            description: null,
            is_active: true,
        });
    });

    it('creates a service with its own field set and no product fields', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: SERVICE }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render(null, 'service');
        await view.find('[data-testid="catalog-form-service-type"]').setValue('installation');
        await view.find('[data-testid="catalog-form"]').trigger('submit');
        await flushPromises();

        const body = sentBodies(fetchMock)[0];

        expect(body).toMatchObject({ kind: 'service', service_type: 'installation' });
        expect(body).not.toHaveProperty('unit');
        expect(body).not.toHaveProperty('product_code');
    });

    /**
     * The guard on this point's answer to the boundary's own warning: without
     * `kind` in the payload, `required_if` never fires and `{"unit": null}`
     * blanks a product's unit.
     */
    it('names the kind on an edit too, so required_if always has something to fire on', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: PRODUCT }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render(PRODUCT, 'product');
        await view.find('[data-testid="catalog-form-name"]').setValue('Copper cable II');
        await view.find('[data-testid="catalog-form"]').trigger('submit');
        await flushPromises();

        const { url, method } = requestOf(fetchMock);

        expect(method).toBe('PATCH');
        expect(url).toContain('/catalog-items/c1');
        expect(sentBodies(fetchMock)[0]).toMatchObject({ kind: 'product', name: 'Copper cable II' });
    });

    /** §3.7 grants deactivation under edit, and the server publishes no action route. */
    it('sends deactivation as a field on the same PATCH', async () => {
        const fetchMock = vi.fn(async () => json(200, { data: PRODUCT }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render(PRODUCT, 'product');
        await view.find('[data-testid="catalog-form-is-active"]').setValue(false);
        await view.find('[data-testid="catalog-form"]').trigger('submit');
        await flushPromises();

        expect(requestOf(fetchMock).url).not.toContain('/deactivate');
        expect(sentBodies(fetchMock)[0]).toMatchObject({ is_active: false });
    });

    it('opens an edit with the record already in the boxes, on the record’s own kind', () => {
        // The screen's active tab is deliberately the *other* one, to prove the
        // form follows the row it was handed and not the tab behind it.
        const view = render(SERVICE, 'product');

        expect(view.find('[data-testid="catalog-form-service-type"]').exists()).toBe(true);
        expect((view.find('[data-testid="catalog-form-service-type"]').element as HTMLInputElement).value).toBe('installation');
        expect((view.find('[data-testid="catalog-form-notes"]').element as HTMLTextAreaElement).value).toBe('Two technicians');
        expect(view.find('[data-testid="catalog-form-unit"]').exists()).toBe(false);
    });
});

describe('CatalogItemFormModal — §6.1 server refusals', () => {
    it('puts a 422 sentence on the field the server named', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(422, {
            error: {
                code: 'validation_failed',
                message: 'The given data was invalid.',
                details: [{ field: 'unit', code: 'invalid', message: 'The unit field is required when kind is product.' }],
            },
        })));

        const view = render(null, 'product');
        await view.find('[data-testid="catalog-form-name"]').setValue('Copper cable');
        await view.find('[data-testid="catalog-form-unit"]').setValue('metre');
        await view.find('[data-testid="catalog-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="catalog-form-unit-error"]').text()).toContain('required when kind is product');
        expect(view.find('[data-testid="catalog-form-error"]').exists()).toBe(false);
    });

    /** A form-level refusal is a key, so it keeps re-translating on an AR/EN switch. */
    it('draws a translated refusal for a 403 rather than the server sentence', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(403, { error: { code: 'forbidden', message: 'This action is unauthorized.' } })));

        const view = render(null, 'service');
        await view.find('[data-testid="catalog-form-service-type"]').setValue('repair');
        await view.find('[data-testid="catalog-form"]').trigger('submit');
        await flushPromises();

        const banner = view.find('[data-testid="catalog-form-error"]');

        expect(banner.text()).toBe(en.catalog.form.forbidden);
        expect(banner.text()).not.toContain('unauthorized');
    });

    it('draws a translated sentence when the server cannot be reached at all', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('Failed to fetch'); }));

        const view = render(null, 'service');
        await view.find('[data-testid="catalog-form-service-type"]').setValue('repair');
        await view.find('[data-testid="catalog-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="catalog-form-error"]').text()).toBe(en.catalog.form.unreachable);
    });
});

describe('CatalogItemFormModal — §5.2 unsaved changes', () => {
    it('asks before discarding something typed, and closes at once when nothing was', async () => {
        const untouched = render(null, 'product');
        await untouched.find('[data-testid="catalog-form-cancel"]').trigger('click');

        expect(untouched.emitted('cancel')).toBeTruthy();

        const typed = render(null, 'product');
        await typed.find('[data-testid="catalog-form-name"]').setValue('Copper');
        await typed.find('[data-testid="catalog-form-cancel"]').trigger('click');

        expect(typed.emitted('cancel')).toBeFalsy();
        expect(typed.find('[data-testid="catalog-form-unsaved"]').exists()).toBe(true);

        await typed.find('[data-testid="catalog-form-discard"]').trigger('click');

        expect(typed.emitted('cancel')).toBeTruthy();
    });

    /** §6.1: "Escape closes dialogs/menus without discarding silently." */
    it('takes the same path on Escape as on Cancel', async () => {
        const view = render(null, 'product');
        await view.find('[data-testid="catalog-form-name"]').setValue('Copper');
        await view.find('[data-testid="catalog-form"]').trigger('keydown.escape');

        expect(view.emitted('cancel')).toBeFalsy();
        expect(view.find('[data-testid="catalog-form-unsaved"]').exists()).toBe(true);
    });
});

describe('CatalogItemFormModal — both languages', () => {
    it('draws its title and labels in Arabic', () => {
        const view = render(null, 'product', 'ar');
        const text = view.find('[data-testid="catalog-form"]').text();

        expect(text).toMatch(/[؀-ۿ]/);
        expect(text).not.toContain('catalog.form');
    });
});
