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
 *
 * `D-124`: no Save, Edit or Cancel. A note saves itself 2 s after typing stops
 * and at once when its box is left; an empty box at the top creates a note on
 * its first save; a cleared note is never saved.
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
    it('shows loading, then the notes in the server order', async () => {
        const fetchMock = stub(() => listOf(DATED, UNDATED));

        const view = render(false);
        expect(view.find('[data-testid="customer-notes-loading"]').exists()).toBe(true);
        await flushPromises();

        expect(String(fetchMock.mock.calls[0]?.[0])).toMatch(/\/customers\/c1\/notes(\?|$)/);

        const items = view.findAll('[data-testid="customer-note"]');
        expect(items).toHaveLength(2);
        expect(items[0]?.text()).toContain(DATED.body);
        expect(items[0]?.find('time').attributes('datetime')).toBe('2026-10-12');
        expect(items[1]?.text()).toContain(UNDATED.body);
        expect(items[1]?.find('time').exists()).toBe(false);
    });

    it('says so when there are no notes', async () => {
        stub(() => listOf());

        const view = render(false);
        await flushPromises();

        expect(view.find('[data-testid="customer-notes-empty"]').exists()).toBe(true);
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

    it('without customer.edit, shows the notes as text: no boxes and no remove', async () => {
        stub(() => listOf(DATED));

        const view = render(false);
        await flushPromises();

        expect(view.find('textarea').exists()).toBe(false);
        expect(view.find('[data-testid="customer-note-new"]').exists()).toBe(false);
        expect(view.find('[data-testid="customer-note-remove"]').exists()).toBe(false);
    });

    it('draws its heading in Arabic on an Arabic page', async () => {
        stub(() => listOf());

        const view = render(true, 'ar');
        await flushPromises();

        expect(view.text()).toContain(ar.customers.notes.title);
    });
});

describe('CustomerNotesSection — D-124, notes save themselves', () => {
    it('offers no Save, Edit or Cancel button, and each note is a box holding its text and date', async () => {
        stub(() => listOf(DATED));

        const view = render();
        await flushPromises();

        expect(view.findAll('button').map((button) => button.attributes('data-testid'))).toEqual(['customer-note-remove']);

        const note = view.find('[data-testid="customer-note"]');
        expect((note.find('[data-testid="customer-note-body"]').element as HTMLTextAreaElement).value).toBe(DATED.body);
        expect((note.find('[data-testid="customer-note-date"]').element as HTMLInputElement).value).toBe('2026-10-12');
    });

    it('saves a changed note 2 seconds after typing stops, once', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });

        try {
            const fetchMock = stub((_url, init) => (init?.method === 'PATCH'
                ? json(200, { data: { ...DATED, body: 'Second wording.' }, meta: {} })
                : listOf(DATED)));

            const view = render();
            await vi.advanceTimersByTimeAsync(0);

            const box = view.find('[data-testid="customer-note-body"]');
            await box.setValue('Second');
            await vi.advanceTimersByTimeAsync(1000);
            await box.setValue('Second wording.');
            await vi.advanceTimersByTimeAsync(1999);
            expect(sent(fetchMock, 'PATCH')).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(1);

            expect(sent(fetchMock, 'PATCH')).toEqual([
                { url: expect.stringMatching(/\/customers\/c1\/notes\/n1$/), body: { body: 'Second wording.', note_date: '2026-10-12' } },
            ]);
            expect(view.find('[data-testid="customer-note-status"]').text()).toBe(en.customers.notes.saved);
        } finally {
            vi.useRealTimers();
        }
    });

    it('saves at once when the box is left, and sends nothing when nothing changed', async () => {
        const fetchMock = stub((_url, init) => (init?.method === 'PATCH' ? json(200, { data: { ...DATED, note_date: null }, meta: {} }) : listOf(DATED)));

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customer-note-body"]').trigger('blur');
        await flushPromises();
        expect(sent(fetchMock, 'PATCH')).toHaveLength(0);

        await view.find('[data-testid="customer-note-date"]').setValue('');
        await view.find('[data-testid="customer-note-date"]').trigger('blur');
        await flushPromises();

        expect(sent(fetchMock, 'PATCH')).toEqual([
            { url: expect.stringMatching(/\/notes\/n1$/), body: { body: DATED.body, note_date: null } },
        ]);
    });

    it('never saves a cleared note: it says the note is required and keeps the stored text', async () => {
        const fetchMock = stub(() => listOf(DATED));

        const view = render();
        await flushPromises();

        const box = view.find('[data-testid="customer-note-body"]');
        await box.setValue('   ');
        await box.trigger('blur');
        await flushPromises();

        expect(sent(fetchMock, 'PATCH')).toHaveLength(0);
        expect(view.find('[data-testid="customer-note-body-error"]').text()).toBe(en.customers.notes.required);
        expect(box.attributes('aria-invalid')).toBe('true');
    });

    it('after a save, a cleared note shows the required error and no longer says saved', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });

        try {
            const fetchMock = stub((_url, init) => (init?.method === 'PATCH'
                ? json(200, { data: { ...DATED, body: 'Second wording.' }, meta: {} })
                : listOf(DATED)));

            const view = render(true, 'ar');
            await vi.advanceTimersByTimeAsync(0);

            const box = view.find('[data-testid="customer-note-body"]');
            await box.setValue('Second wording.');
            await vi.advanceTimersByTimeAsync(2000);
            expect(sent(fetchMock, 'PATCH')).toHaveLength(1);
            expect(view.find('[data-testid="customer-note-status"]').text()).toBe(ar.customers.notes.saved);

            await box.setValue('');
            await vi.advanceTimersByTimeAsync(2000);

            expect(sent(fetchMock, 'PATCH')).toHaveLength(1);
            expect(view.find('[data-testid="customer-note-body-error"]').text()).toBe(ar.customers.notes.required);
            expect(view.find('[data-testid="customer-note-status"]').text()).not.toContain(ar.customers.notes.saved);
        } finally {
            vi.useRealTimers();
        }
    });

    it("puts the server's sentence under the box when it refuses, and keeps what was typed", async () => {
        stub((_url, init) => (init?.method === 'PATCH'
            ? json(422, { error: { code: 'validation_failed', message: 'Invalid.', details: [{ field: 'body', message: 'The note may not be longer than 5000 characters.' }] } })
            : listOf(DATED)));

        const view = render();
        await flushPromises();

        const box = view.find('[data-testid="customer-note-body"]');
        await box.setValue('too long');
        await box.trigger('blur');
        await flushPromises();

        expect(view.find('[data-testid="customer-note-body-error"]').text()).toBe('The note may not be longer than 5000 characters.');
        expect((box.element as HTMLTextAreaElement).value).toBe('too long');
        expect(view.find('[data-testid="customer-note-status"]').text()).toBe(en.customers.notes.notSaved);
    });

    it('creates a note from the empty box at the top on its first save, then empties the box', async () => {
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

        const fresh = view.find('[data-testid="customer-note-new"]');
        await fresh.find('[data-testid="customer-note-new-date"]').setValue('2026-10-12');
        await fresh.find('[data-testid="customer-note-new-body"]').setValue('  Visited; asked for a revised offer.  ');
        await fresh.find('[data-testid="customer-note-new-body"]').trigger('blur');
        await flushPromises();

        expect(sent(fetchMock, 'POST')).toEqual([
            { url: expect.stringMatching(/\/customers\/c1\/notes$/), body: { body: 'Visited; asked for a revised offer.', note_date: '2026-10-12' } },
        ]);
        expect(view.findAll('[data-testid="customer-note"]')).toHaveLength(2);
        expect((view.find('[data-testid="customer-note-new-body"]').element as HTMLTextAreaElement).value).toBe('');
    });

    it('keeps a pause mid-sentence in one note: the first save creates it, the next updates it, and it joins the list only when the box is left', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });

        try {
            const created = { ...DATED, id: 'n9', body: 'First half', note_date: null };
            const fetchMock = stub((_url, init) => {
                if (init?.method === 'POST') {
                    return json(201, { data: created, meta: {} });
                }

                if (init?.method === 'PATCH') {
                    return json(200, { data: { ...created, body: 'First half, second half.' }, meta: {} });
                }

                return listOf();
            });

            const view = render();
            await vi.advanceTimersByTimeAsync(0);

            const box = view.find('[data-testid="customer-note-new-body"]');
            await box.setValue('First half');
            await vi.advanceTimersByTimeAsync(2000);
            await box.setValue('First half, second half.');
            await vi.advanceTimersByTimeAsync(2000);

            expect(sent(fetchMock, 'POST')).toHaveLength(1);
            expect(sent(fetchMock, 'PATCH')).toEqual([
                { url: expect.stringMatching(/\/notes\/n9$/), body: { body: 'First half, second half.', note_date: null } },
            ]);
            // Still the box being typed in, not yet a row of the list.
            expect((box.element as HTMLTextAreaElement).value).toBe('First half, second half.');
            expect(view.findAll('[data-testid="customer-note"]')).toHaveLength(0);
        } finally {
            vi.useRealTimers();
        }
    });

    it('creates nothing from the empty box while it stays empty, and asks for nothing', async () => {
        const fetchMock = stub(() => listOf());

        const view = render();
        await flushPromises();

        await view.find('[data-testid="customer-note-new-date"]').setValue('2026-10-12');
        await view.find('[data-testid="customer-note-new-body"]').trigger('blur');
        await flushPromises();

        expect(sent(fetchMock, 'POST')).toHaveLength(0);
        expect(view.find('[data-testid="customer-note-body-error"]').exists()).toBe(false);
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
