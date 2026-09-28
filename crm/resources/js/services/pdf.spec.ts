import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, setBearerTokenProvider } from '@/api';
import { readQuotationPdf, requestQuotationPdf } from '@/services/pdf';

/**
 * Module 9 · 5.1 — the two calls behind the PDF panel, as transport: 4.2's
 * `GET …/pdf` and 3.5's `POST …/pdf` (`OpenAPI §4.3`'s `202`, the language
 * named in the body, Q15). Who may make them is the API's answer (§3.5), not
 * this file's.
 */

const TOKEN = 'a'.repeat(64);

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

beforeEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    setBearerTokenProvider(() => TOKEN);
});

describe('the quotation PDF service', () => {
    it('reads the state of one quotation’s PDF with the bearer token', async () => {
        const state = { generation: null, latest_file_id: null };
        const fetchMock = vi.fn(async (_input: string, _init?: RequestInit) => json(200, { data: state, meta: { request_id: 'r1' } }));
        vi.stubGlobal('fetch', fetchMock);

        await expect(readQuotationPdf('q1')).resolves.toEqual(state);

        const call = fetchMock.mock.calls[0];
        expect(String(call?.[0])).toBe('/api/v1/quotations/q1/pdf');
        expect(call?.[1]?.method).toBe('GET');
        expect((call?.[1]?.headers as Record<string, string>).Authorization).toBe(`Bearer ${TOKEN}`);
    });

    it('asks for a render in the chosen language', async () => {
        const fetchMock = vi.fn(async (_input: string, _init?: RequestInit) =>
            json(202, { data: { job_id: 'j1', status: 'queued' }, meta: { request_id: 'r1' } }));
        vi.stubGlobal('fetch', fetchMock);

        await expect(requestQuotationPdf('q1', 'en')).resolves.toBeUndefined();

        const call = fetchMock.mock.calls[0];
        expect(String(call?.[0])).toBe('/api/v1/quotations/q1/pdf');
        expect(call?.[1]?.method).toBe('POST');
        expect(JSON.parse(String(call?.[1]?.body))).toEqual({ locale: 'en' });
    });

    it('carries a refusal as the API’s own error, status and code intact', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(403, {
            error: { code: 'permission_denied', message: 'No.', details: [{ code: 'unauthorized_action', message: 'No.' }] },
            meta: { request_id: 'r1' },
        })));

        const refusal = await requestQuotationPdf('q1', 'ar').catch((error: unknown) => error);

        expect(refusal).toBeInstanceOf(ApiError);
        expect((refusal as ApiError).status).toBe(403);
        expect((refusal as ApiError).detailCodes).toEqual(['unauthorized_action']);
    });
});
