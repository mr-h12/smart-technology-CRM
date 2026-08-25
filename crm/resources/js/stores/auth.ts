/**
 * The session, as the SPA holds it — `D-74`, `D-29`, `SEC-05`, `SEC-09`.
 *
 * ── This is not authorization, and the distinction is the whole file ───────
 *
 * §3.12 rule 1: "Enforcement happens at the API — hiding a button is not the
 * same as blocking an action", and `SEC-09` says the same in the other
 * direction. Everything below decides what a person *sees*. A caller who edits
 * `permissions` in a debugger gets a different-looking menu and not one extra
 * byte of data, because every endpoint asks the database again (Point 2.3).
 * Nothing here may ever become the reason a request is allowed.
 *
 * ── Why a module singleton and not Pinia ───────────────────────────────────
 *
 * Pinia is the conventional answer and would be a new runtime dependency for
 * one store with five fields. `reactive` from Vue is already installed, already
 * typed, and gives the same reactivity; `CLAUDE.md` asks for the smallest
 * coherent implementation. Revisit when a second store needs devtools or SSR
 * hydration, neither of which exists here.
 *
 * ── Where the token lives ──────────────────────────────────────────────────
 *
 * `localStorage`, and `D-74` already recorded the cost of that: "a token the
 * SPA must hold is reachable by script in a way an HttpOnly cookie is not, so
 * an XSS defect would leak it." `sessionStorage` does not change that — script
 * reads both — it only makes a second tab a second login, which fights `D-29`'s
 * eight-hour working day. What bounds the damage is what that decision already
 * names: per-device revocation (`SEC-05`), the idle expiry, and a database that
 * stores only the digest.
 */
import { computed, reactive, readonly } from 'vue';
import { ApiError, apiGet, apiPost, setBearerTokenProvider, setUnauthorizedHandler } from '@/api';

/** `GET /auth/me`'s `data`, which is `Profile::toArray()`. */
export interface AuthenticatedUser {
    id: string;
    name: string;
    email: string;
    is_active: boolean;
    role: { id: string; slug: string; name: string } | null;
    /** `SEC-07` triples. A menu, not a gate. */
    permissions: string[];
    /** §3.1's Super Admin, stated rather than inferred from a full list. */
    unconditional_access: boolean;
}

interface LoginResponse {
    token: string;
    token_type: string;
    idle_timeout_seconds: number;
    user: AuthenticatedUser | null;
}

/**
 * The key the token is kept under.
 *
 * Versioned, so a future change of shape does not silently read an old value
 * back as a valid session.
 */
export const TOKEN_STORAGE_KEY = 'crm.auth.token.v1';

/**
 * The Super Admin's own token, parked while a Login As is running (`SEC-10`).
 *
 * Point 3.4 never touches the original session — leaving is a client-side
 * switch back to the token the browser still holds, which is what
 * `resume_with_original_token` in the leave response means. Parking it in
 * storage rather than only in memory is what makes a page reload survivable:
 * without it, refreshing during an impersonation would strand the Super Admin
 * inside somebody else's account until `D-29`'s eight idle hours expired it.
 */
export const IMPERSONATOR_TOKEN_STORAGE_KEY = 'crm.auth.impersonator_token.v1';

/** Who is being impersonated, so a reload can still draw the banner. */
export const IMPERSONATION_STORAGE_KEY = 'crm.auth.impersonating.v1';

/** The subject of a Login As, as the start response describes them. */
export interface ImpersonationSubject {
    id: string;
    name: string;
    role: string | null;
}

interface AuthState {
    token: string | null;
    user: AuthenticatedUser | null;
    /** True while a `login()` or `fetchCurrentUser()` is in flight. */
    pending: boolean;
    /** The lang-file key for the last refusal, or null. §5.1's detail code decides which. */
    errorKey: string | null;
    /** `D-29`, as the server reported it. Told to the client, never assumed by it. */
    idleTimeoutSeconds: number | null;
    /** `SEC-10`. Null when this is an ordinary session. */
    impersonating: ImpersonationSubject | null;
    /** The parked Super Admin token. Null when this is an ordinary session. */
    impersonatorToken: string | null;
}

const state = reactive<AuthState>({
    token: readToken(),
    user: null,
    pending: false,
    errorKey: null,
    idleTimeoutSeconds: null,
    impersonating: readImpersonation(),
    impersonatorToken: readStored(IMPERSONATOR_TOKEN_STORAGE_KEY),
});

/**
 * `OpenAPI §5.1`'s detail codes mapped to lang-file keys.
 *
 * The server already sends a localised `message`; this exists so the screen can
 * use the project's own Arabic and English wording — §10.1 fixes the suspended
 * sentence word for word — and so an unrecognised code still renders something
 * rather than a blank.
 */
const REFUSAL_KEYS: Readonly<Record<string, string>> = {
    invalid_credentials: 'auth.error.invalidCredentials',
    account_suspended: 'auth.error.accountSuspended',
    account_locked: 'auth.error.accountLocked',
    session_invalid: 'auth.error.sessionInvalid',
    rate_limit_exceeded: 'auth.error.rateLimited',
    validation_failed: 'auth.error.validationFailed',
};

function readStored(key: string): string | null {
    try {
        return window.localStorage.getItem(key);
    } catch {
        // Safari in private mode, and any browser with storage disabled. A
        // session that cannot be persisted is still a session for this tab;
        // throwing here would make the application fail to boot.
        return null;
    }
}

function writeStored(key: string, value: string | null): void {
    try {
        if (value === null) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, value);
        }
    } catch {
        // Same reason as readStored. The in-memory value still works until the
        // tab closes.
    }
}

function readToken(): string | null {
    return readStored(TOKEN_STORAGE_KEY);
}

function writeToken(token: string | null): void {
    writeStored(TOKEN_STORAGE_KEY, token);
}

function readImpersonation(): ImpersonationSubject | null {
    const raw = readStored(IMPERSONATION_STORAGE_KEY);

    if (raw === null) {
        return null;
    }

    try {
        const parsed: unknown = JSON.parse(raw);

        if (typeof parsed !== 'object' || parsed === null) {
            return null;
        }

        const subject = parsed as Partial<ImpersonationSubject>;

        // A half-written entry answers null rather than a banner naming
        // `undefined`. The same reasoning CachePasswordChallengeStore applies
        // to a partial cache entry.
        return typeof subject.id === 'string' && typeof subject.name === 'string'
            ? { id: subject.id, name: subject.name, role: subject.role ?? null }
            : null;
    } catch {
        return null;
    }
}

/** Drops the Login As, leaving the ordinary session untouched. */
function clearImpersonation(): void {
    state.impersonating = null;
    state.impersonatorToken = null;
    writeStored(IMPERSONATION_STORAGE_KEY, null);
    writeStored(IMPERSONATOR_TOKEN_STORAGE_KEY, null);
}

/** Everything about the session, dropped at once. */
function clear(): void {
    state.token = null;
    state.user = null;
    state.idleTimeoutSeconds = null;
    writeToken(null);
    clearImpersonation();
}

export function useAuth() {
    return {
        // readonly so a component cannot assign to the session it is reading.
        // The actions below are the only way in.
        state: readonly(state),

        isAuthenticated: computed(() => state.token !== null),
        user: computed(() => state.user),
        role: computed(() => state.user?.role?.slug ?? null),
        permissions: computed<readonly string[]>(() => state.user?.permissions ?? []),
        pending: computed(() => state.pending),
        errorKey: computed(() => state.errorKey),

        /**
         * §3.1's unconditional access, which is what `SEC-10` restricts Login
         * As to. Asked through the flag the server states rather than by
         * comparing the slug here, so a ninth role §3.12 rule 5 adds cannot
         * accidentally match a string literal.
         */
        isSuperAdmin: computed(() => state.user?.unconditional_access === true),

        isImpersonating: computed(() => state.impersonating !== null && state.impersonatorToken !== null),
        impersonating: computed(() => state.impersonating),

        hasPermission,
        login,
        logout,
        fetchCurrentUser,
        clearError,
        startImpersonation,
        leaveImpersonation,
    };
}

/**
 * Whether the menu should show something (`SEC-09`), by `resource.action.scope`
 * or by the `resource.action` prefix.
 *
 * The prefix form exists because §3.2 makes the scope part of the identity, so
 * a role holds `customer.view.own` and a nav item cares only that the customer
 * screen is reachable at all. Asking for a bare pair therefore matches any
 * scope; asking for a full triple matches exactly.
 *
 * §3.1's unconditional access is answered first, exactly as the backend's
 * `Actor::hasUnconditionalAccess()` does — otherwise the Super Admin's menu
 * would be drawn from grant rows that authorise nothing for them.
 */
function hasPermission(ability: string): boolean {
    if (state.user === null) {
        return false;
    }

    if (state.user.unconditional_access) {
        return true;
    }

    const held = state.user.permissions;

    if (held.includes(ability)) {
        return true;
    }

    // A `resource.action` pair — two segments — matches any scope.
    if (ability.split('.').length !== 2) {
        return false;
    }

    return held.some((triple) => triple.startsWith(`${ability}.`));
}

function clearError(): void {
    state.errorKey = null;
}

/**
 * §9 Flow 0. On success the token is persisted and the profile is taken from
 * the login response rather than fetched again — `LoginController` already
 * serialises it, and a second round trip is a second chance to be inconsistent.
 */
async function login(email: string, password: string): Promise<boolean> {
    state.pending = true;
    state.errorKey = null;

    try {
        const { data } = await apiPost<LoginResponse>('/auth/login', { email, password });

        state.token = data.token;
        state.idleTimeoutSeconds = data.idle_timeout_seconds;
        writeToken(data.token);

        if (data.user === null) {
            // The server issued a session it could not describe. Refusing to
            // proceed is the honest reading: a signed-in user with no role has
            // no menu and no landing screen.
            clear();
            state.errorKey = 'auth.error.sessionInvalid';

            return false;
        }

        state.user = data.user;

        return true;
    } catch (error) {
        state.errorKey = refusalKeyFor(error);

        return false;
    } finally {
        state.pending = false;
    }
}

/**
 * `SEC-05`'s force-logout, applied by the person themselves.
 *
 * The local state is cleared **whatever the server answers**. A token the
 * server has already revoked still fails to log out, and leaving it in
 * `localStorage` because the request 401'd would strand the user in a session
 * that cannot do anything.
 */
async function logout(): Promise<void> {
    try {
        if (state.token !== null) {
            await apiPost('/auth/logout');
        }
    } catch {
        // Deliberately swallowed — see above.
    } finally {
        clear();
    }
}

/**
 * Rehydrates the session held in storage on a page load.
 *
 * Returns false when there is no usable session, which is what the router's
 * guard waits on before deciding whether a route may be entered.
 */
async function fetchCurrentUser(): Promise<boolean> {
    if (state.token === null) {
        return false;
    }

    if (state.user !== null) {
        return true;
    }

    state.pending = true;

    try {
        const { data } = await apiGet<AuthenticatedUser>('/auth/me');
        state.user = data;

        return true;
    } catch {
        // Any failure here means the stored token no longer names a session —
        // expired by D-29, revoked by SEC-05, or the account deactivated. The
        // 401 handler below has already cleared it; this covers the rest.
        clear();

        return false;
    } finally {
        state.pending = false;
    }
}

/**
 * `SEC-10` — begin a Login As.
 *
 * The Super Admin's own token is parked, not surrendered: Point 3.4 leaves
 * their session untouched, so leaving is a switch back to a credential the
 * browser still holds rather than a second sign-in. Everything is written
 * **before** the profile is re-fetched, because that fetch already runs as the
 * impersonated user.
 *
 * `SEC-10` is enforced by the server twice — the route's
 * `permission:admin.login_as` and `StartImpersonation`'s own
 * `hasUnconditionalAccess()` check, because §3.12 rule 5 makes the matrix
 * configuration. Nothing here is that check; the button is hidden from
 * everybody else because showing it would be a promise the API refuses.
 */
async function startImpersonation(userId: string): Promise<boolean> {
    const original = state.token;

    if (original === null) {
        return false;
    }

    const { impersonate } = await import('@/services/identity');

    state.pending = true;

    try {
        const started = await impersonate(userId);

        state.impersonatorToken = original;
        writeStored(IMPERSONATOR_TOKEN_STORAGE_KEY, original);

        state.impersonating = started.impersonating;
        writeStored(IMPERSONATION_STORAGE_KEY, JSON.stringify(started.impersonating));

        state.token = started.token;
        writeToken(started.token);

        // The profile, permissions and role now belong to the person being
        // impersonated — which is the point of the feature, and what makes the
        // menu and the guards show their screens rather than the Super Admin's.
        state.user = null;
        await fetchCurrentUser();

        return true;
    } catch {
        return false;
    } finally {
        state.pending = false;
    }
}

/**
 * The other end of it.
 *
 * The request goes out on the **impersonation** token — that is the session
 * `/auth/impersonate/leave` revokes — and only then is the parked token put
 * back. Restoring first would send the leave call as the Super Admin, whose
 * session is not an impersonation, and the server would answer `422
 * not_impersonating`.
 *
 * Local state is restored **whatever the server answers**, for the reason
 * {@see logout} gives: a leave that fails must not trap somebody inside another
 * person's account.
 */
async function leaveImpersonation(): Promise<void> {
    const original = state.impersonatorToken;

    if (original === null) {
        return;
    }

    state.pending = true;

    try {
        const { leaveImpersonation: leave } = await import('@/services/identity');
        await leave();
    } catch {
        // Deliberately swallowed — see above.
    } finally {
        state.token = original;
        writeToken(original);
        clearImpersonation();

        state.user = null;
        await fetchCurrentUser();

        state.pending = false;
    }
}

function refusalKeyFor(error: unknown): string {
    if (!(error instanceof ApiError)) {
        // A network failure, not a refusal. Saying "wrong password" here would
        // send the user to reset a credential that was never checked.
        return 'auth.error.unreachable';
    }

    for (const code of [...error.detailCodes, error.code]) {
        const key = REFUSAL_KEYS[code];

        if (key !== undefined) {
            return key;
        }
    }

    return 'auth.error.unknown';
}

// The credential is wired at module load, not by a caller.
//
// It used to be installed by `installAuthTransport()`, which only `app.ts`
// calls — so any other entry point that built a router and signed in sent no
// `Authorization` header at all and every authenticated request silently
// 401'd. Found by a component test in Point 5.2, where the impersonation
// refetch went out bare. A two-step wiring whose first step is required for
// correctness is a step somebody will forget; the store owns the token, so the
// store registers it.
setBearerTokenProvider(() => state.token);

/**
 * Tells the transport what to do when the server says the session is gone.
 *
 * Called once from `app.ts`, because only the entry point knows there is a
 * router to redirect. Kept separate from the token wiring above for exactly
 * that reason: this one genuinely needs a caller, and that one never did.
 */
export function installAuthTransport(onSessionLost: () => void): void {
    setUnauthorizedHandler(() => {
        clear();
        onSessionLost();
    });
}
