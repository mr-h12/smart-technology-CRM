import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import ImportModal from '@/components/imports/ImportModal.vue';
import { importCustomers } from '@/services/customers';

/**
 * Module 3, Point 4.6 — the `.csv` import screen. Shared since F-09 · 1.5
 * (`D-85`): suppliers import the same way, so the dialog takes its title and
 * its upload from the caller. These cases drive it with the customers' upload;
 * `SuppliersView.spec.ts` drives it with the suppliers'.
 *
 * ── Why this is a dialog on the list and not a screen of its own ───────────
 *
 * §8 names no *Import* item for any role, and Point 4.5b was the cost of
 * drawing a destination §8 does not name. Import is an action on the Customers
 * screen, so it lives where that screen is. No route, no nav item.
 *
 * ── Four numbers, and the fourth is arithmetic ─────────────────────────────
 *
 * `ImportBatchPayload` sends `row_count`, `imported_count` and
 * `incomplete_count`, and deliberately **no failure count** — its own docblock
 * says why: "A field that can disagree with the two it is derived from is a
 * field that eventually will." So failures are computed here, from the two.
 *
 * ── The link §10 asks for ──────────────────────────────────────────────────
 *
 * §10's edge-case table: an incomplete import is "Flagged 'incomplete' with a
 * **dedicated filter**". Point 4.2 built that filter, so the result offers it
 * rather than describing it. It is an emit and not a `RouterLink` because no
 * filter state reaches the URL (a standing debt) — a link could not carry it.
 *
 * ── What is NOT duplicated here ────────────────────────────────────────────
 *
 * The 30 MB ceiling is `config('files.max_size_bytes')` (`D-71`) and the server
 * refuses an oversized file with its own sentence, which `ApiError.messageFor`
 * surfaces. This screen states the limit because Design System §6.3 requires a
 * file upload to show it — it does not enforce it, because a second copy of a
 * rule is a rule that drifts.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const BATCH = {
    id: 'b1',
    original_filename: 'customers.csv',
    row_count: 10,
    imported_count: 8,
    incomplete_count: 3,
};

function render(locale = 'en') {
    return mount(ImportModal, {
        props: { open: true, title: 'Import customers', upload: importCustomers },
        global: {
            plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })],
        },
    });
}

/** A real `File`, so the component builds a real `FormData` the way the browser would. */
function csv(name = 'customers.csv'): File {
    return new File(['name\nAlpha Trading\n'], name, { type: 'text/csv' });
}

async function choose(view: ReturnType<typeof render>, file: File): Promise<void> {
    const input = view.find('[data-testid="import-file"]');

    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    await input.trigger('change');
    await flushPromises();
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('ImportModal — Point 4.6, shared at F-09 · 1.5', () => {
    it('draws the title its caller passes', () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(201, { data: BATCH })));

        expect(render().find('h2').text()).toBe('Import customers');
    });


    it('sends the file as multipart on the field the server validates', async () => {
        const sent: Array<{ url: string; body: unknown }> = [];

        vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
            sent.push({ url, body: init?.body });

            return json(201, { data: BATCH });
        }));

        const view = render();
        await choose(view, csv());
        await view.find('[data-testid="import-submit"]').trigger('click');
        await flushPromises();

        expect(sent[0]!.url).toBe('/api/v1/customers/import');

        // `ImportFileRequest` validates a field literally named `file`.
        // A FormData built under any other key is a 422 the screen cannot fix.
        const body = sent[0]!.body;
        expect(body).toBeInstanceOf(FormData);
        expect((body as FormData).get('file')).toBeInstanceOf(File);
    });

    it('reports the three counts the server sends and the fourth it does not', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(201, { data: BATCH })));

        const view = render();
        await choose(view, csv());
        await view.find('[data-testid="import-submit"]').trigger('click');
        await flushPromises();

        const result = view.find('[data-testid="import-result"]').text();

        expect(result).toContain('10');
        expect(result).toContain('8');
        expect(result).toContain('3');
        // 10 − 8. The server stores no failure count, so this is the one number
        // on the screen that the screen itself is responsible for.
        expect(view.find('[data-testid="import-failed"]').text()).toContain('2');
    });

    it('offers §10’s dedicated filter only when something was flagged', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(201, { data: BATCH })));

        const view = render();
        await choose(view, csv());
        await view.find('[data-testid="import-submit"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="import-show-incomplete"]').exists()).toBe(true);

        await view.find('[data-testid="import-show-incomplete"]').trigger('click');

        expect(view.emitted('showIncomplete')).toHaveLength(1);
    });

    it('offers no incomplete filter when nothing was flagged', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(201, { data: { ...BATCH, incomplete_count: 0 } })));

        const view = render();
        await choose(view, csv());
        await view.find('[data-testid="import-submit"]').trigger('click');
        await flushPromises();

        // The other half of the test above. A control offering a filter that
        // would match nothing is a control that lies about the import.
        expect(view.find('[data-testid="import-result"]').exists()).toBe(true);
        expect(view.find('[data-testid="import-show-incomplete"]').exists()).toBe(false);
    });

    it('cannot be submitted before a file is chosen', async () => {
        const fetchMock = vi.fn(async () => json(201, { data: BATCH }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();

        expect(view.find('[data-testid="import-submit"]').attributes('disabled')).toBeDefined();

        await choose(view, csv());

        // Both halves: disabled with nothing chosen, enabled once there is.
        expect(view.find('[data-testid="import-submit"]').attributes('disabled')).toBeUndefined();
    });

    /**
     * §6.1: "Display server validation near the affected field and preserve
     * entered values on validation failure." The chosen file survives a 422 —
     * re-picking a file the person already picked is the browser's worst
     * dialog, and the server's own sentence is the one shown.
     */
    it('shows the server’s own refusal and keeps the chosen file', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(422, {
            error: {
                code: 'validation_failed',
                message: 'The given data was invalid.',
                // `OpenAPI §5.1`'s `details` is an ARRAY of `{field, code,
                // message}`, not a map of field to messages. The first version
                // of this fixture used a map, and the screen fell through to
                // its generic sentence while looking exactly like a code bug.
                details: [{
                    field: 'file',
                    code: 'file_too_large',
                    message: 'The file may not be greater than 30720 kilobytes.',
                }],
            },
        })));

        const view = render();
        await choose(view, csv());
        await view.find('[data-testid="import-submit"]').trigger('click');
        await flushPromises();

        expect(view.find('[data-testid="import-error"]').text()).toContain('30720');
        expect(view.find('[data-testid="import-file-name"]').text()).toContain('customers.csv');
        // Still submittable — a refusal is not a reason to make the person start over.
        expect(view.find('[data-testid="import-submit"]').attributes('disabled')).toBeUndefined();
    });

    it('announces the import to the list only when one succeeded', async () => {
        const fetchMock = vi.fn(async () => json(422, {
            error: {
                code: 'validation_failed',
                message: 'no',
                details: [{ field: 'file', code: 'invalid_file', message: 'bad' }],
            },
        }));
        vi.stubGlobal('fetch', fetchMock);

        const view = render();
        await choose(view, csv());
        await view.find('[data-testid="import-submit"]').trigger('click');
        await flushPromises();

        expect(view.emitted('imported')).toBeUndefined();

        fetchMock.mockImplementation(async () => json(201, { data: BATCH }));
        await view.find('[data-testid="import-submit"]').trigger('click');
        await flushPromises();

        expect(view.emitted('imported')).toHaveLength(1);
    });

    /** §6.3: a file upload "shows allowed formats, configured size limit". */
    it('states the format and the limit before anything is chosen', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(201, { data: BATCH })));

        const text = render().text();

        expect(text).toContain('CSV');
        expect(text).toContain('30');
    });

    it('closes on Escape and on Cancel', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(201, { data: BATCH })));

        const view = render();
        await view.find('[data-testid="import-cancel"]').trigger('click');

        expect(view.emitted('cancel')).toHaveLength(1);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();

        expect(view.emitted('cancel')).toHaveLength(2);
    });
});
