import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AuthenticatedUser } from '@/stores/auth';

/**
 * Point 5.1 — the session as the SPA holds it.
 *
 * `D-74` · `D-29` · `SEC-01` · `SEC-03` · `SEC-05` · `SEC-09` · §3.12 rule 1 ·
 * §9 Flow 0 · §10.1 · `OpenAPI §5.1`.
 *
 * ── The store is a module singleton, so every test gets a new module ───────
 *
 * `reactive()` state lives in the module body. Importing once and reusing it
 * would let a signed-in test leak a token into the next one, and the test that
 * then passed would be proving nothing. `vi.resetModules()` before each dynamic
 * import is what makes each case start signed out.
 */

interface Refusal {
    status: number;
    code: string;
    detail?: string;
}

const PROFILE: AuthenticatedUser = {
    id: '01a0-user',
    name: 'Test Indoor Sales',
    email: 'indoor.sales@example.test',
    is_active: true,
    role: { id: '01a0-role', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['customer.view.own', 'quotation.create.own'],
    unconditional_access: false,
};

function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json', 'X-Request-Id': 'test-request-id' },
    });
}

function loginSuccess(user: AuthenticatedUser | null = PROFILE): Response {
    return jsonResponse(201, {
        data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user },
        meta: { request_id: 'test-request-id' },
    });
}

/** `OpenAPI §5` — the error envelope, with §5.1's specific code in `details`. */
function refusal({ status, code, detail }: Refusal): Response {
    return jsonResponse(status, {
        error: {
            code,
            message: 'localised message from the server',
            ...(detail === undefined ? {} : { details: [{ code: detail, message: 'detail' }] }),
        },
    });
}

async function freshStore() {
    vi.resetModules();
    window.localStorage.clear();

    return import('@/stores/auth');
}

beforeEach(() => {
    window.localStorage.clear();
});

describe('login', () => {
    it('persists the token and the profile on success', async () => {
        const fetchMock = vi.fn().mockResolvedValue(loginSuccess());
        vi.stubGlobal('fetch', fetchMock);

        const { useAuth, TOKEN_STORAGE_KEY } = await freshStore();
        const auth = useAuth();

        await expect(auth.login('indoor.sales@example.test', 'Passw0rd123')).resolves.toBe(true);

        expect(auth.isAuthenticated.value).toBe(true);
        expect(auth.role.value).toBe('indoor_sales');
        expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('a'.repeat(64));
    });

    it('never writes the password anywhere it is kept', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(loginSuccess()));

        const { useAuth } = await freshStore();
        await useAuth().login('indoor.sales@example.test', 'Passw0rd123');

        // Coding Standards §9. The credential is sent and forgotten; a store
        // that kept it would put it in every devtools snapshot.
        expect(JSON.stringify(window.localStorage)).not.toContain('Passw0rd123');
        expect(JSON.stringify(useAuth().state)).not.toContain('Passw0rd123');
    });

    it('sends the token as an opaque bearer credential and hashes nothing', async () => {
        // D-74: the SHA-256 in that decision is what the *server* stores. A
        // client that hashed the token would present a credential the server
        // has never seen.
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(loginSuccess())
            .mockResolvedValueOnce(jsonResponse(200, { data: PROFILE }));
        vi.stubGlobal('fetch', fetchMock);

        const { useAuth, installAuthTransport } = await freshStore();
        installAuthTransport(() => {});

        const auth = useAuth();
        await auth.login('indoor.sales@example.test', 'Passw0rd123');

        const { apiGet } = await import('@/api');
        await apiGet('/roles');

        const headers = fetchMock.mock.calls[1]?.[1]?.headers as Record<string, string>;

        expect(headers.Authorization).toBe(`Bearer ${'a'.repeat(64)}`);
    });

    it.each([
        ['§10.1 wrong credentials', { status: 401, code: 'authentication_required', detail: 'invalid_credentials' }, 'auth.error.invalidCredentials'],
        ['§10.1 deactivated', { status: 403, code: 'permission_denied', detail: 'account_suspended' }, 'auth.error.accountSuspended'],
        ['SEC-03 locked', { status: 423, code: 'account_locked', detail: 'account_locked' }, 'auth.error.accountLocked'],
        ['SEC-11 throttled', { status: 429, code: 'rate_limit_exceeded' }, 'auth.error.rateLimited'],
        ['a bad form', { status: 422, code: 'validation_failed' }, 'auth.error.validationFailed'],
    ])('maps %s to its own message', async (_name, response: Refusal, expected) => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(refusal(response)));

        const { useAuth } = await freshStore();
        const auth = useAuth();

        await expect(auth.login('someone@example.test', 'wrong')).resolves.toBe(false);

        // Three different sentences, because §10.1 fixes the suspended wording
        // and a locked account is a state retyping cannot fix.
        expect(auth.errorKey.value).toBe(expected);
        expect(auth.isAuthenticated.value).toBe(false);
    });

    it('does not call a refused login a wrong password when the network failed', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

        const { useAuth } = await freshStore();
        const auth = useAuth();

        await auth.login('someone@example.test', 'Passw0rd123');

        expect(auth.errorKey.value).toBe('auth.error.unreachable');
    });

    it('refuses a session the server could not describe', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(loginSuccess(null)));

        const { useAuth, TOKEN_STORAGE_KEY } = await freshStore();
        const auth = useAuth();

        await expect(auth.login('someone@example.test', 'Passw0rd123')).resolves.toBe(false);
        expect(auth.isAuthenticated.value).toBe(false);
        expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    });
});

describe('hasPermission (SEC-09 — a menu, never a gate)', () => {
    it.each([
        ['an exact §3.2 triple', 'customer.view.own', true],
        ['a resource.action pair matching any scope', 'customer.view', true],
        ['a triple with the wrong scope', 'customer.view.all', false],
        ['something the role does not hold', 'supplier.edit.all', false],
    ])('answers %s', async (_name, ability, expected) => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(loginSuccess()));

        const { useAuth } = await freshStore();
        const auth = useAuth();
        await auth.login('indoor.sales@example.test', 'Passw0rd123');

        expect(auth.hasPermission(ability)).toBe(expected);
    });

    it('answers true for §3.1 unconditional access without consulting the list', async () => {
        const superAdmin: AuthenticatedUser = {
            ...PROFILE,
            role: { id: '01a0-sa', slug: 'super_admin', name: 'Super Admin' },
            permissions: [],
            unconditional_access: true,
        };

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(loginSuccess(superAdmin)));

        const { useAuth } = await freshStore();
        const auth = useAuth();
        await auth.login('super.admin@example.test', 'Passw0rd123');

        expect(auth.hasPermission('admin.manage_roles')).toBe(true);
    });

    it('answers false with no session at all', async () => {
        const { useAuth } = await freshStore();

        expect(useAuth().hasPermission('customer.view.own')).toBe(false);
    });

    /**
     * §8 and §3.1 ask two different questions, and the Super Admin is the only
     * account where the answers differ.
     *
     * §3.1 gives them scope *All*, which is why `hasPermission` short-circuits
     * and why `AuthorizeAction` does the same on the server — they may act.
     * But §8 gives them "22 administrative screens (section 13)" and **no
     * Customers screen**, and §3.3's seven columns have no Super Admin at all.
     * `holdsPermission` answers the §8 question — *is this screen part of this
     * role's set* — from the grant rows alone.
     *
     * Measured rather than assumed: the seeded `super_admin` role holds exactly
     * nine grants, all `admin.*`, and no business permission. So drawing the
     * menu from the grants does not empty it — it produces §13's screens, which
     * is what §8 asks for.
     */
    it('holdsPermission ignores §3.1 unconditional access where hasPermission honours it', async () => {
        const superAdmin: AuthenticatedUser = {
            ...PROFILE,
            role: { id: '01a0-sa', slug: 'super_admin', name: 'Super Admin' },
            permissions: ['admin.manage_roles.all'],
            unconditional_access: true,
        };

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(loginSuccess(superAdmin)));

        const { useAuth } = await freshStore();
        const auth = useAuth();
        await auth.login('super.admin@example.test', 'Passw0rd123');

        // Both halves in one test: the authorisation answer is unchanged, and
        // only the menu answer differs. Asserting the second alone would pass
        // against a function that always returned false.
        expect(auth.hasPermission('customer.view')).toBe(true);
        expect(auth.holdsPermission('customer.view')).toBe(false);

        // And a grant they really hold still draws its screen.
        expect(auth.holdsPermission('admin.manage_roles')).toBe(true);
    });

    it('holdsPermission keeps the exact and prefix matching hasPermission uses', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(loginSuccess()));

        const { useAuth } = await freshStore();
        const auth = useAuth();
        await auth.login('indoor.sales@example.test', 'Passw0rd123');

        // §3.2 makes the scope part of the identity: a bare pair matches any
        // scope, a full triple matches exactly, and an unheld one matches
        // nothing. Same rule as `hasPermission` — only the override differs.
        expect(auth.holdsPermission('customer.view')).toBe(true);
        expect(auth.holdsPermission('customer.view.own')).toBe(true);
        expect(auth.holdsPermission('admin.manage_roles')).toBe(false);
    });

    it('holdsPermission answers false with no session at all', async () => {
        const { useAuth } = await freshStore();

        expect(useAuth().holdsPermission('customer.view.own')).toBe(false);
    });
});

describe('logout and session loss', () => {
    it('clears local state even when the server refuses the logout', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(loginSuccess())
            .mockResolvedValueOnce(refusal({ status: 401, code: 'authentication_required' }));
        vi.stubGlobal('fetch', fetchMock);

        const { useAuth, TOKEN_STORAGE_KEY } = await freshStore();
        const auth = useAuth();
        await auth.login('indoor.sales@example.test', 'Passw0rd123');

        await auth.logout();

        // A token the server has already revoked still fails to log out.
        // Keeping it because the request 401'd would strand the person.
        expect(auth.isAuthenticated.value).toBe(false);
        expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    });

    it('runs the session-lost handler on a 401 to an authenticated call', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(loginSuccess())
            .mockResolvedValueOnce(refusal({ status: 401, code: 'authentication_required', detail: 'session_invalid' }));
        vi.stubGlobal('fetch', fetchMock);

        const { useAuth, installAuthTransport, TOKEN_STORAGE_KEY } = await freshStore();
        const onSessionLost = vi.fn();
        installAuthTransport(onSessionLost);

        const auth = useAuth();
        await auth.login('indoor.sales@example.test', 'Passw0rd123');

        const { apiGet } = await import('@/api');
        await expect(apiGet('/roles')).rejects.toThrow();

        // D-29's idle expiry and SEC-05's revocation both arrive as this.
        expect(onSessionLost).toHaveBeenCalledOnce();
        expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    });

    it('does not run the session-lost handler on a refused login', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            refusal({ status: 401, code: 'authentication_required', detail: 'invalid_credentials' }),
        ));

        const { useAuth, installAuthTransport } = await freshStore();
        const onSessionLost = vi.fn();
        installAuthTransport(onSessionLost);

        await useAuth().login('someone@example.test', 'wrong');

        // A wrong password is a 401 too. Treating it as an expired session
        // would clear state the person never had and bounce them to the page
        // they are already looking at.
        expect(onSessionLost).not.toHaveBeenCalled();
    });

    it('drops a stored token the server no longer recognises', async () => {
        const { TOKEN_STORAGE_KEY } = await import('@/stores/auth');
        vi.resetModules();
        window.localStorage.setItem(TOKEN_STORAGE_KEY, 'b'.repeat(64));

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            refusal({ status: 401, code: 'authentication_required', detail: 'session_invalid' }),
        ));

        const { useAuth } = await import('@/stores/auth');
        const auth = useAuth();

        // The token was rehydrated from storage on module load.
        expect(auth.state.token).toBe('b'.repeat(64));
        await expect(auth.fetchCurrentUser()).resolves.toBe(false);

        expect(auth.isAuthenticated.value).toBe(false);
        expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    });
});
