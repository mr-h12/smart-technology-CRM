// The single place the SPA talks to the backend.
//
// AP-07 requires web and mobile to share one API, and D-67 is explicit that the
// SPA consumes /api/v1 and never owns a calculation, a permission decision or a
// state transition. Routing every call through here keeps that reviewable: if
// business logic ever appears on this side, it appears in one file.
//
// OpenAPI §4.1 defines the envelope, so the client unwraps `data` and keeps
// `meta` available for the request id that §3.3 puts on every response.

export interface Envelope<T> {
    data: T;
    meta?: { request_id?: string };
}

export interface ApiResult<T> {
    data: T;
    requestId: string | null;
}

export async function apiGet<T>(path: string): Promise<ApiResult<T>> {
    const response = await fetch(`/api/v1${path}`, {
        headers: {
            Accept: 'application/json',
            // OpenAPI §2: the client states its language; stable machine codes
            // stay English while user-facing messages are localised.
            'Accept-Language': document.documentElement.lang || 'en',
        },
    });

    if (!response.ok) {
        throw new Error(`GET ${path} failed with ${response.status}`);
    }

    const body = (await response.json()) as Envelope<T>;

    return {
        data: body.data,
        requestId: response.headers.get('X-Request-Id') ?? body.meta?.request_id ?? null,
    };
}
