import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import FileViewerDialog from '@/components/FileViewerDialog.vue';
import { downloadFile, fetchFileUrl } from '@/services/files';

/**
 * F-47 · 1 (D-116): an attached PDF or image opens in an in-page viewer, fetched
 * through the same permission-checked route as a download (D-38, §17). The
 * route is not asserted here — `files.spec.ts` owns the transport.
 */
vi.mock('@/services/files', () => ({
    fetchFileUrl: vi.fn(),
    downloadFile: vi.fn(),
}));

/** happy-dom loads an iframe's src; a blob: it cannot fetch only logs noise. The value is opaque to the viewer. */
const PDF_URL = 'about:blank';

type Viewed = { id: string; name: string; mimeType: string };

function render(file: Viewed | null) {
    return mount(FileViewerDialog, {
        attachTo: document.body,
        props: { file },
        global: { plugins: [createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, ar } })] },
    });
}

beforeEach(() => {
    vi.mocked(fetchFileUrl).mockReset();
    vi.mocked(downloadFile).mockReset().mockResolvedValue(undefined);
    vi.stubGlobal('URL', Object.assign(URL, { revokeObjectURL: vi.fn() }));
});

describe('FileViewerDialog', () => {
    it('shows a PDF in an iframe and names the file', async () => {
        vi.mocked(fetchFileUrl).mockResolvedValue(PDF_URL);
        const view = render({ id: 'f1', name: 'عرض المورّد.pdf', mimeType: 'application/pdf' });
        await flushPromises();

        expect(fetchFileUrl).toHaveBeenCalledWith('f1');
        expect(view.get('[data-testid="file-viewer"]').element.tagName).toBe('DIALOG');
        expect(view.get('iframe').attributes('src')).toBe(PDF_URL);
        expect(view.find('img').exists()).toBe(false);
        expect(view.text()).toContain('عرض المورّد.pdf');
        view.unmount();
    });

    it('shows an image in an img, not an iframe', async () => {
        vi.mocked(fetchFileUrl).mockResolvedValue('blob:png');
        const view = render({ id: 'f2', name: 'site.png', mimeType: 'image/png' });
        await flushPromises();

        expect(view.get('img').attributes('src')).toBe('blob:png');
        expect(view.find('iframe').exists()).toBe(false);
        view.unmount();
    });

    it('shows loading while the file is fetched, then the error state when it is refused', async () => {
        let refuse: (reason: unknown) => void = () => {};
        vi.mocked(fetchFileUrl).mockReturnValue(new Promise((_, reject) => { refuse = reject; }));
        const view = render({ id: 'f3', name: 'a.pdf', mimeType: 'application/pdf' });
        await nextTick();

        expect(view.find('[data-testid="loading-state"]').exists()).toBe(true);

        refuse(new Error('404'));
        await flushPromises();

        expect(view.find('[data-testid="loading-state"]').exists()).toBe(false);
        expect(view.text()).toContain(en.files.viewFailed);
        expect(view.find('iframe').exists()).toBe(false);
        view.unmount();
    });

    it('closes on Escape (the dialog\'s cancel event) and on its close button; its download saves under the file\'s name', async () => {
        vi.mocked(fetchFileUrl).mockResolvedValue(PDF_URL);
        const view = render({ id: 'f4', name: 'po.pdf', mimeType: 'application/pdf' });
        await flushPromises();

        const escape = new Event('cancel', { cancelable: true });
        view.get('[data-testid="file-viewer"]').element.dispatchEvent(escape);
        expect(escape.defaultPrevented).toBe(true);
        expect(view.emitted('close')).toHaveLength(1);

        await view.get('[data-testid="file-viewer-close"]').trigger('click');
        expect(view.emitted('close')).toHaveLength(2);

        await view.get('[data-testid="file-viewer-download"]').trigger('click');
        expect(downloadFile).toHaveBeenCalledWith('f4', 'po.pdf');
        view.unmount();
    });

    /** An object URL pins its blob for the life of the document until it is revoked. */
    it('revokes the object URL when the viewer closes', async () => {
        vi.mocked(fetchFileUrl).mockResolvedValue(PDF_URL);
        const view = render({ id: 'f5', name: 'a.pdf', mimeType: 'application/pdf' });
        await flushPromises();

        await view.setProps({ file: null });

        expect(URL.revokeObjectURL).toHaveBeenCalledWith(PDF_URL);
        expect(view.find('dialog').exists()).toBe(false);
        view.unmount();
    });
});
