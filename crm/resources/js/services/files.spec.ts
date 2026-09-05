import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, setBearerTokenProvider } from '@/api';
import { downloadFile } from '@/services/files';

/**
 * Module 6, Point 6.5 — §17's one file route, exercised as transport.
 *
 * ── Why this is a service suite and not a screen one ───────────────────────
 *
 * The screen's question is "is a download offered for this file?"; this one's
 * is "does the request carry the credential, and does the blob become a save?".
 * The second is `apiDownload()`'s contract and it is the reason a plain
 * `<a href>` will not do: the token is an `Authorization` header (`D-74`) and a
 * browser navigation does not send one, so a link to this path is a 401.
 *
 * ── No authorization is proved here ────────────────────────────────────────
 *
 * §3.12 rule 1 and `SEC-09` put that at the API. `DownloadFile::forActor()`
 * refuses a file whose scan is not clean **before** it looks at permission
 * (`SEC-15`) and answers both cases with 404, which `OpenAPI` requires so the
 * caller cannot tell "no such file" from "not yours".
 */

const TOKEN = 'a'.repeat(64);

beforeEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    setBearerTokenProvider(() => TOKEN);

    // jsdom implements neither, and the point of the helper is that it uses
    // both — so they are stubbed rather than worked around.
    vi.stubGlobal('URL', Object.assign(URL, {
        createObjectURL: vi.fn(() => 'blob:test'),
        revokeObjectURL: vi.fn(),
    }));
});

describe('downloadFile', () => {
    it('asks §17’s route with the bearer token and saves under the given name', async () => {
        // The parameters are declared so `mock.calls` is typed as a real tuple:
        // an inferred `() => …` gives `[][]`, where `calls[0]?.[0]` is an error
        // rather than a value. `vue-tsc` caught exactly that here.
        const fetchMock = vi.fn(async (_input: string, _init?: RequestInit) => new Response('%PDF-1.4', { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);

        const clicks: string[] = [];
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            clicks.push(this.download);
        });

        await downloadFile('f1', 'عرض المورّد.pdf');

        const call = fetchMock.mock.calls[0];

        expect(call).toBeDefined();
        expect(String(call?.[0])).toBe('/api/v1/files/f1/download');
        expect(call?.[1]?.method).toBe('GET');
        expect((call?.[1]?.headers as Record<string, string>).Authorization).toBe(`Bearer ${TOKEN}`);

        // The Arabic name survives, because it never goes through a header here.
        expect(clicks).toEqual(['عرض المورّد.pdf']);
    });

    /** An object URL pins its blob for the life of the document until it is revoked. */
    it('revokes the object URL even when the save gesture throws', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('%PDF-1.4', { status: 200 })));
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {
            throw new Error('no');
        });

        await expect(downloadFile('f1', 'a.pdf')).rejects.toThrow('no');
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:test');
    });

    /** A non-clean or unreachable file is a 404, and it must arrive as an `ApiError`, not as a saved blob. */
    it('throws rather than saving when the server refuses', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(
            JSON.stringify({ error: { code: 'resource_not_found', message: 'no' } }),
            { status: 404, headers: { 'Content-Type': 'application/json' } },
        )));

        await expect(downloadFile('f1', 'a.pdf')).rejects.toBeInstanceOf(ApiError);
        expect(URL.createObjectURL).not.toHaveBeenCalled();
    });
});
