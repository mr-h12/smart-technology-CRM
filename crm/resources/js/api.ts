// The single place the SPA talks to the backend.
//
// AP-07 requires web and mobile to share one API, and D-67 is explicit that the
// SPA consumes /api/v1 and never owns a calculation, a permission decision or a
// state transition. Routing every call through here keeps that reviewable: if
// business logic ever appears on this side, it appears in one file.
//
// OpenAPI §4.1 defines the envelope, so the client unwraps `data` and keeps
// `meta` available for the request id that §3.3 puts on every response. §5
// defines the error envelope, and ApiError below is that shape read back —
// a caller never parses a response body itself.

export interface Pagination {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
    has_next_page: boolean;
    has_previous_page: boolean;
}

export interface EnvelopeMeta {
    request_id?: string;
    /** `OpenAPI §4.2` — present on every list endpoint, absent on a single resource. */
    pagination?: Pagination;
    /**
     * `meta` is open by §4.1, and an endpoint may add to it: `D-35`'s
     * `similar_customers` is the first. Declared as `unknown` so a reader has
     * to narrow rather than trust — the alternative is every service editing
     * this interface for a key only it knows about.
     */
    [key: string]: unknown;
}

export interface Envelope<T> {
    data: T;
    meta?: EnvelopeMeta;
}

export interface ApiResult<T> {
    data: T;
    requestId: string | null;
    /**
     * The rest of `meta`, kept rather than discarded.
     *
     * §4.2 puts the pagination block there and nowhere else, so a caller that
     * only received `data` had to re-fetch or count the rows itself — and
     * counting the rows of one page is how a paginator ends up reporting the
     * page size as the total.
     */
    meta: EnvelopeMeta;
}

/**
 * One entry of `OpenAPI §5.1`'s `details`, kept whole.
 *
 * `field` is the submitted path the server refused, and `message` is the
 * sentence it wrote for a person — localised by the server for this request's
 * `Accept-Language`, which is why the client does not compose one. The stable
 * `code` beside them stays English and is what a client matches on.
 */
export interface ApiErrorDetail {
    field?: string;
    code?: string;
    message?: string;
}

/** `OpenAPI §5` — `{ error: { code, message, details } }`. */
interface ErrorEnvelope {
    error?: {
        code?: string;
        message?: string;
        details?: { field?: string; code?: string; message?: string }[];
    };
}

/**
 * A non-2xx response, carried as data rather than as a string.
 *
 * §5.1 closes the set of HTTP codes and puts the specific reason in
 * `details[].code`, so a screen that wants to tell "wrong password" from
 * "account locked" needs both. Throwing `new Error(status)` — which this file
 * used to do — discards exactly the half that distinguishes them.
 */
export class ApiError extends Error {
    constructor(
        readonly status: number,
        /** §5.1's top-level code, e.g. `permission_denied`. */
        readonly code: string,
        /** §5.1's specific stable codes, e.g. `account_locked`. */
        readonly detailCodes: readonly string[],
        message: string,
        readonly requestId: string | null,
        /**
         * §5.1's `details`, whole — added with Point 5.1.
         *
         * `detailCodes` above answers *what rule was broken* and is what every
         * Module 1 screen reads. It cannot answer *which field*, and a form
         * with eight inputs needs that: a single banner saying "validation
         * failed" makes the person hunt for the one control they got wrong.
         *
         * Additive on purpose — `detailCodes` is unchanged and still derived
         * from the same array, so no existing caller moves.
         */
        readonly details: readonly ApiErrorDetail[] = [],
    ) {
        super(message);
        this.name = 'ApiError';
    }

    /** True when any of §5.1's detail codes on this response is `code`. */
    is(code: string): boolean {
        return this.code === code || this.detailCodes.includes(code);
    }

    /**
     * The server's sentence for `field`, or null.
     *
     * The server's and not one composed here: it is localised for this
     * request's `Accept-Language`, and a client that wrote its own would be a
     * second copy of a validation rule — the copy that is wrong is always the
     * one in the screen.
     */
    messageFor(field: string): string | null {
        for (const detail of this.details) {
            if (detail.field === field && typeof detail.message === 'string') {
                return detail.message;
            }
        }

        return null;
    }
}

type TokenProvider = () => string | null;
type UnauthorizedHandler = () => void;

// Registered by the auth store rather than imported from it. api.ts is the
// lowest layer here and the store sits on top of it; importing upward would be
// a cycle, and a cycle in module initialisation is a `undefined is not a
// function` that only appears in the built bundle.
let bearerToken: TokenProvider = () => null;
let onUnauthorized: UnauthorizedHandler = () => {};

export function setBearerTokenProvider(provider: TokenProvider): void {
    bearerToken = provider;
}

/** `D-29`/`SEC-05` — what to do when the server says the session is gone. */
export function setUnauthorizedHandler(handler: UnauthorizedHandler): void {
    onUnauthorized = handler;
}

export async function apiGet<T>(path: string): Promise<ApiResult<T>> {
    return request<T>('GET', path);
}

export async function apiPost<T>(path: string, body?: unknown): Promise<ApiResult<T>> {
    return request<T>('POST', path, body);
}

export async function apiPatch<T>(path: string, body?: unknown): Promise<ApiResult<T>> {
    return request<T>('PATCH', path, body);
}

/**
 * `SEC-05`'s revocations are the only DELETEs this API has.
 *
 * `DB-01` is not bypassed by the verb: the server soft-deletes the
 * `user_sessions` row, and a session is not business data. Every other
 * "removal" in this product is `PATCH …/deactivate` or `…/archive`, which is
 * why this helper arrives with Point 5.4 and not before.
 */
export async function apiDelete<T>(path: string): Promise<ApiResult<T>> {
    return request<T>('DELETE', path);
}

/**
 * A multipart upload — the one call shape `request()` cannot make.
 *
 * Measured, not assumed: `request()` JSON-stringifies every body and pins
 * `Content-Type: application/json`, so a `FormData` would arrive as `{}`. The
 * header is deliberately **absent** here: the browser writes
 * `multipart/form-data` with the boundary it generated, and a hand-written one
 * would have the wrong boundary.
 *
 * Everything else is `request()`'s: the bearer token, `Accept-Language`,
 * §4.1's envelope, §5's error shape and `D-29`'s unauthorized handler.
 */
export async function apiUpload<T>(path: string, form: FormData): Promise<ApiResult<T>> {
    return request<T>('POST', path, form);
}

/**
 * A binary read — `GET /files/{id}/download`, §17's permission-checking
 * endpoint, and the only response in this API that is not an envelope.
 *
 * It cannot go through `request()`, which ends in `response.json()`: a PDF
 * parsed as JSON is a thrown `SyntaxError` where a file should be. What it does
 * share is the part that matters — `headersFor()` supplies the same bearer,
 * the same `Accept-Language` and `D-29`'s unauthorized handling, so the two
 * paths cannot drift on authentication.
 *
 * ⚠️ **A plain `<a href>` cannot do this.** The credential is an `Authorization`
 * header (`D-74`), which a browser navigation does not send, so a link to this
 * path is a 401. The blob and its object URL are what turn an authenticated
 * fetch back into a saveable file.
 *
 * A non-clean file answers **404**, not 403: `DownloadFile::forActor()` refuses
 * anything `! isScannedClean()` before it looks at permission at all
 * (`SEC-15`), and `OpenAPI` deliberately does not say which case a 404 is.
 */
export async function apiDownload(path: string): Promise<Blob> {
    const token = bearerToken();
    const response = await fetch(`/api/v1${path}`, { method: 'GET', headers: headersFor(token) });

    if (!response.ok) {
        if (response.status === 401 && token !== null) {
            onUnauthorized();
        }

        throw await errorFrom(response, 'GET', path, response.headers.get('X-Request-Id'));
    }

    return response.blob();
}

/**
 * `OpenAPI §4.2`'s collection envelope, unwrapped once for every service.
 *
 * The pagination fallback matters: a client that computed the total from the
 * rows it received would report the page size as the total, which is the
 * defect §4.2 avoids by sending all six keys.
 */
export function collection<T>(result: ApiResult<unknown>): { items: T[]; pagination: Pagination } {
    const items = Array.isArray(result.data) ? (result.data as T[]) : [];

    return {
        items,
        pagination: result.meta.pagination ?? {
            page: 1,
            per_page: items.length,
            total: items.length,
            total_pages: 1,
            has_next_page: false,
            has_previous_page: false,
        },
    };
}

/**
 * The headers every call shares, extracted when `apiDownload()` became the
 * second caller. A second hand-written copy of the bearer line is the kind of
 * duplicate that is correct in both places until one of them changes.
 */
function headersFor(token: string | null): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        // OpenAPI §2: the client states its language; stable machine codes
        // stay English while user-facing messages are localised.
        'Accept-Language': document.documentElement.lang || 'en',
    };

    if (token !== null) {
        // D-74. The token is opaque to this client — 64 hex characters of
        // server-issued randomness. The SHA-256 in that decision is what the
        // *server* stores in `user_sessions.session_id`; nothing here hashes
        // anything, and a client that did would be sending a credential the
        // server has never seen.
        headers.Authorization = `Bearer ${token}`;
    }

    return headers;
}

async function request<T>(method: string, path: string, body?: unknown): Promise<ApiResult<T>> {
    const token = bearerToken();
    const headers = headersFor(token);

    // FormData writes its own `Content-Type`, boundary included. Setting one
    // here would name a boundary the body does not use, and the server would
    // read zero parts.
    const multipart = body instanceof FormData;

    if (body !== undefined && !multipart) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(`/api/v1${path}`, {
        method,
        headers,
        ...(body === undefined ? {} : { body: multipart ? body : JSON.stringify(body) }),
    });

    const requestId = response.headers.get('X-Request-Id');

    if (!response.ok) {
        // Only when a credential was actually presented. A 401 on the login
        // request itself is a wrong password (§10.1, RefusalReason), and
        // treating that as an expired session would clear state the user never
        // had and bounce them to the page they are already on.
        if (response.status === 401 && token !== null) {
            onUnauthorized();
        }

        throw await errorFrom(response, method, path, requestId);
    }

    const envelope = (await response.json()) as Envelope<T>;

    return {
        data: envelope.data,
        requestId: requestId ?? envelope.meta?.request_id ?? null,
        meta: envelope.meta ?? {},
    };
}

async function errorFrom(
    response: Response,
    method: string,
    path: string,
    requestId: string | null,
): Promise<ApiError> {
    let parsed: ErrorEnvelope = {};

    try {
        parsed = (await response.json()) as ErrorEnvelope;
    } catch {
        // A body that is not JSON. The status is still the answer, and losing
        // it to a parse failure would turn every proxy error page into a
        // silent one.
        parsed = {};
    }

    const details: string[] = [];
    const entries = parsed.error?.details ?? [];

    for (const detail of entries) {
        if (typeof detail.code === 'string') {
            details.push(detail.code);
        }
    }

    return new ApiError(
        response.status,
        parsed.error?.code ?? 'unknown_error',
        details,
        parsed.error?.message ?? `${method} ${path} failed with ${response.status}`,
        requestId ?? null,
        entries,
    );
}
