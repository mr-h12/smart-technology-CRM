import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import CustomerNotesSection from '@/components/customers/CustomerNotesSection.vue';

/**
 * `D-122` (F-50 · 3) — a customer's notes as separate entries, each with an
 * optional date. The list comes from `GET /customers/{id}/notes` in the
 * server's order (newest date first, undated last); this section never
 * re-sorts it. Writing needs `customer.edit`, which the page passes in.
 */

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const PAGINATION = { page: 1, per_page: 100, total: 2, total_pages: 1, has_next_page: false, has_previous_page: false };

const DATED = { id: 'n1', body: 'Visited; asked for a revised offer.', note_date: '2026-10-12', created_by: 'u1', created_at: '2026-10-12T09:00:00Z', updated_at: '2026-10-12T09:00:00Z' };
const UNDATED = { id: 'n2', body: 'Old notes from the single box.', note_date: null, created_by: null, created_at: '2026-10-01T09:00:00Z', updated_at: '2026-10-01T09:00:00Z' };

type Handler = (url: string, init?: RequestInit) => Response;

function stub(handler: Handler): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => handler(String(url), init));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

function listOf(...notes: unknown[]): Response {
    return json(200, { data: notes, meta: { pagination: { ...PAGINATION, total: notes.length } } });
}

function render(canEdit = true, locale = 'en') {
    return mount(CustomerNotesSection, {
        props: { customerId: 'c1', canEdit },
        global: { plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, ar } })] },
    });
}

function sent(mock: ReturnType<typeof vi.fn>, method: string): { url: string; body: unknown }[] {
    return mock.mock.calls
        .filter((call) => (call[1] as RequestInit | undefined)?.method === method)
        .map((call) => ({
            url: String(call[0]),
            body: typeof call[1]?.body === 'string' ? JSON.parse(String(call[1].body)) : null,
        }));
}

beforeEach(() => {
    vi.restoreAllMocks();
});

describe('CustomerNotesSection — the list', () => {
    it('shows loading, then the notes in the server order, each with its date or none', async () => {
        const fetchMock = stub(() => listOf(DATED, UNDATED));

        const view = render();
        expect(view.find('[data-testid="customer-notes-loading"]').exists()).toBe(true);
        await flushPromises();

        expect(String(fetchMock.mock.calls[0]?.[0])).toMatch(/\/customers\/c1\/notes(\?|$)/);

        const items = view.findAll('[data-testid="customer-note"]');
        expect(items).toHaveLength(2);
        expect(items[0]?.text()).toContain('Visited; asked for a revised offer.');
        expect(items[0]?.find('time').attributes('datetime')).toBe('2026-10-12');
        expect(items[1]?.text()).toContain('Old notes from the single box.');
        expect(items[1]?.find('time').exists()).toBe(false);
    });

    it('says so when there are no notes', async () => {
        stub(() => listOf());

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="customer-notes-empty"]').exists()).toBe(true);
        expect(view.findAll('[data-testid="customer-note"]')).toHaveLength(0);
    });

    it('shows the error state on a failure and reads again on Retry', async () => {
        let fail = true;
        const fetchMock = stub(() => (fail ? json(500, { error: { code: 'x', message: 'no' } }) : listOf(DATED)));

        const view = render();
        await flushPromises();

        expect(view.find('[data-testid="customer-notes-error"]').exists()).toBe(true);

        fail = false;
        await view.find('[data-testid="customer-notes-error"] button').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(view.findAll('[data-testid="customer-note"]')).toHaveLength(1);
    });

    it('offers no add, edit or remove without customer.edit', async () => {
        stub(() => listOf(DATED));

        const view = render(false);
        await flushPromises();

        expect(view.find('[data-testid="customer-note-add"]').exists()).toBe(false);
        expect(view.find('[data-testid="customer-note-edit"]').exists()).toBe(false);
        expect(view.find('[data-testid="customer-note-remove"]').exists()).toBe(false);
    });

    it('draws its heading in Arabic on an Arabic page', async () => {
        stub(() => listOf());

        const view = render(true, 'ar');
        await flushPromises();

        expect(view.text()).toContain(ar.customers.notes.title);
    });
});

describe('CustomerNotesSection — writing', () => {
    it('adds a note with its date, then reads the list again', async () => {
        let notes: unknown[] = [UNDATED];
        const fetchMock = stub((_url, init) => {
            if (init?.method === 'POST') {
                notes = [DATED, UNDATED];

                return json(201, { data: DATED, meta: {} });
            }

            return listOf(...notes);
        });

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customer-note-add"]').trigger('click');
        await view.find('[data-testid="customer-note-body"]').setValue('  Visited; asked for a revised offer.  ');
        await view.find('[data-testid="customer-note-date"]').setValue('2026-10-12');
        await view.find('[data-testid="customer-note-form"]').trigger('submit');
        await flushPromises();

        expect(sent(fetchMock, 'POST')).toEqual([
            { url: expect.stringMatching(/\/customers\/c1\/notes$/), body: { body: 'Visited; asked for a revised offer.', note_date: '2026-10-12' } },
        ]);
        expect(view.findAll('[data-testid="customer-note"]')).toHaveLength(2);
    });

    it('sends an empty date as null', async () => {
        const fetchMock = stub((_url, init) => (init?.method === 'POST' ? json(201, { data: UNDATED, meta: {} }) : listOf()));

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customer-note-add"]').trigger('click');
        await view.find('[data-testid="customer-note-body"]').setValue('No date.');
        await view.find('[data-testid="customer-note-form"]').trigger('submit');
        await flushPromises();

        expect(sent(fetchMock, 'POST')[0]?.body).toEqual({ body: 'No date.', note_date: null });
    });

    it("puts the server's sentence under the body and keeps what was typed", async () => {
        stub((_url, init) => (init?.method === 'POST'
            ? json(422, { error: { code: 'validation_failed', message: 'Invalid.', details: [{ field: 'body', message: 'The note may not be longer than 5000 characters.' }] } })
            : listOf()));

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customer-note-add"]').trigger('click');
        await view.find('[data-testid="customer-note-body"]').setValue('too long');
        await view.find('[data-testid="customer-note-form"]').trigger('submit');
        await flushPromises();

        expect(view.find('[data-testid="customer-note-body-error"]').text()).toBe('The note may not be longer than 5000 characters.');
        expect((view.find('[data-testid="customer-note-body"]').element as HTMLTextAreaElement).value).toBe('too long');
    });

    it('edits a note in place, opening on its saved body and date', async () => {
        const fetchMock = stub((_url, init) => (init?.method === 'PATCH'
            ? json(200, { data: { ...DATED, body: 'Second wording.' }, meta: {} })
            : listOf(DATED)));

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customer-note-edit"]').trigger('click');
        expect((view.find('[data-testid="customer-note-body"]').element as HTMLTextAreaElement).value).toBe(DATED.body);
        expect((view.find('[data-testid="customer-note-date"]').element as HTMLInputElement).value).toBe('2026-10-12');

        await view.find('[data-testid="customer-note-body"]').setValue('Second wording.');
        await view.find('[data-testid="customer-note-form"]').trigger('submit');
        await flushPromises();

        expect(sent(fetchMock, 'PATCH')).toEqual([
            { url: expect.stringMatching(/\/customers\/c1\/notes\/n1$/), body: { body: 'Second wording.', note_date: '2026-10-12' } },
        ]);
    });

    it('removes a note only after it is confirmed', async () => {
        let notes: unknown[] = [DATED, UNDATED];
        const fetchMock = stub((_url, init) => {
            if (init?.method === 'DELETE') {
                notes = [UNDATED];

                return new Response(null, { status: 204 });
            }

            return listOf(...notes);
        });

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customer-note-remove"]').trigger('click');
        expect(sent(fetchMock, 'DELETE')).toHaveLength(0);

        await view.find('[data-testid="customer-note-remove-confirm"]').trigger('click');
        await flushPromises();

        expect(sent(fetchMock, 'DELETE')).toEqual([{ url: expect.stringMatching(/\/customers\/c1\/notes\/n1$/), body: null }]);
        expect(view.findAll('[data-testid="customer-note"]')).toHaveLength(1);
    });
});
