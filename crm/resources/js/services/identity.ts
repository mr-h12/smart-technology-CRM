/**
 * §3.11's administration endpoints, typed once.
 *
 * `api.ts` is the transport — envelopes, headers, `ApiError`. This is the
 * catalogue of what Module 1 actually exposes, so a screen names an operation
 * rather than assembling a path, and the response shapes live beside the calls
 * that produce them instead of inside each component.
 *
 * Nothing here decides anything. Every function is one request (`D-67`).
 */
import { apiDelete, apiGet, apiPatch, apiPost, type ApiResult, type Pagination } from '@/api';

/** `UserPayload::of()`. No `password`, and no `is_hidden` — §3.12 rule 6. */
export interface AdministeredUser {
    id: string;
    name: string;
    email: string;
    role_id: string;
    role: { slug: string; name: string };
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

/** `RolePayload::of()`, trimmed to what a dropdown needs. */
export interface RoleOption {
    id: string;
    slug: string;
    name: string;
    is_system: boolean;
}

/** One `permissions` row — §3.2's `resource.action.scope`, as the server sends it. */
export interface PermissionOption {
    id: string;
    resource: string;
    action: string;
    scope: string;
    /** §3.2's notation, built by the server so the client never concatenates it. */
    triple: string;
    /**
     * §3.12 rule 3 — false when no role may hold this `resource.action`.
     *
     * Server-derived from `PermissionMatrix::forbiddenKeys()`. The client must
     * not recompute it: a second copy of the forbidden list is a second answer
     * to the same question, and the copy that is wrong is always the one in the
     * screen.
     */
    is_grantable: boolean;
}

/** `RolePayload::of()` in full — what the matrix screen reads. */
export interface AdministeredRole extends RoleOption {
    description: string | null;
    /**
     * False for the role §3.1 grants unconditionally.
     *
     * **Not** `is_system`: all eight seeded roles carry `is_system = true`, and
     * treating that as "immutable" would freeze the entire matrix and delete
     * §3.12 rule 5. `RolePayload` derives this from
     * `RoleView::hasUnconditionalAccess()`, which is the Super Admin alone.
     */
    is_editable: boolean;
    /** The triples this role currently grants. */
    permissions: PermissionOption[];
}

/** `PATCH /roles/{id}/permissions` — the role as it now stands, and what moved. */
export interface RolePermissionsUpdated {
    role: AdministeredRole;
    diff: { granted: string[]; revoked: string[]; changed: boolean };
}

/**
 * One row of `SEC-05`'s active device list — `SessionPayload::of()`.
 *
 * No `session_id`: `D-74` stores the SHA-256 digest of the bearer token in
 * that column and Coding Standards §9 forbids exposing it, so the server never
 * sends it and this interface has nowhere to put it.
 *
 * No impersonation field either. §3.1 hides the Super Admin, so a Login As
 * session is not returned to the account it runs as — see `DeviceSession` on
 * the server for why that is a rule and not a filter.
 */
export interface DeviceSession {
    id: string;
    ip_address: string | null;
    /** The raw `User-Agent`. The server does not parse it and neither does this. */
    user_agent: string | null;
    last_activity_at: string;
    signed_in_at: string;
    /** The device making the call. Told by the server, never inferred here. */
    is_current: boolean;
}

export type { Pagination } from '@/api';

export interface Page<T> {
    items: T[];
    pagination: Pagination;
}

export interface UserListQuery {
    page?: number;
    isActive?: boolean | null;
    roleSlug?: string | null;
}

/**
 * `POST /auth/impersonate/{user}` — `SEC-10`.
 *
 * `impersonator_id` rather than the Super Admin's token: the token they already
 * hold is never re-sent, which is why leaving is a client-side switch back to
 * it rather than a second login (Point 3.4).
 */
export interface ImpersonationStarted {
    token: string;
    impersonating: { id: string; name: string; role: string | null };
    impersonator_id: string;
}

// No `token_type`. `LoginController` sends one and `ImpersonateController` does
// not — measured against the running server rather than inferred from the
// login shape, which is what this interface first copied.

export async function listUsers(query: UserListQuery = {}): Promise<Page<AdministeredUser>> {
    const parameters = new URLSearchParams();

    if (query.page !== undefined) {
        parameters.set('page', String(query.page));
    }

    // §6.2: only the fields the resource declares. `is_active` and `role` are
    // `UserListCriteria::ALLOWED_FILTERS`; anything else is a 400, so an
    // unset filter is omitted rather than sent empty.
    if (query.isActive !== undefined && query.isActive !== null) {
        parameters.set('filter[is_active]', query.isActive ? 'true' : 'false');
    }

    if (query.roleSlug !== undefined && query.roleSlug !== null && query.roleSlug !== '') {
        parameters.set('filter[role]', query.roleSlug);
    }

    const suffix = parameters.size === 0 ? '' : `?${parameters.toString()}`;

    return collection<AdministeredUser>(await apiGet(`/users${suffix}`));
}

export async function createUser(payload: {
    name: string;
    email: string;
    password: string;
    role_id: string;
}): Promise<AdministeredUser> {
    return (await apiPost<AdministeredUser>('/users', payload)).data;
}

export async function updateUser(
    id: string,
    payload: { name?: string; email?: string; role_id?: string },
): Promise<AdministeredUser> {
    return (await apiPatch<AdministeredUser>(`/users/${id}`, payload)).data;
}

/**
 * `D-34` — the switch, never a delete. §7.2's action suffix, because this
 * revokes every session and writes its own mandatory audit entry (§3.12 rule 4)
 * rather than being a field on a form.
 */
export async function setUserActivation(id: string, active: boolean): Promise<AdministeredUser> {
    const action = active ? 'reactivate' : 'deactivate';

    return (await apiPatch<AdministeredUser>(`/users/${id}/${action}`, {})).data;
}

/**
 * `GET /api/v1/roles` — §3.11's `admin.manage_roles`, **Super Admin only**.
 *
 * ⚠️ A Manager holds `admin.create_user` and not this row, so this call answers
 * `403` for them. That is §3.11 read correctly and it leaves a real gap: a
 * Manager may create users and has no endpoint that returns a `role_id` to put
 * on one. Recorded as an owner question in `CHECKLIST.md` since Point 4.1; the
 * screen degrades rather than pretending.
 */
export async function listAssignableRoles(): Promise<RoleOption[]> {
    return collection<RoleOption>(await apiGet(`/roles?per_page=100`)).items;
}

/**
 * Every role, with the grants each one holds — §13 screen 3's input.
 *
 * Paged through rather than fetched with one large `per_page`, for the reason
 * {@see listAllPermissions} spells out.
 */
export async function listRoles(): Promise<AdministeredRole[]> {
    return allPages<AdministeredRole>('/roles');
}

/**
 * Every permission row, across as many pages as it takes.
 *
 * ⚠️ **This loop is not defensive coding; it is required today.**
 * `ReferenceListCriteria::MAX_PER_PAGE` is **100** and a `per_page` above it is
 * a `400`, not a clamp — and the seeded matrix holds **143** rows (measured
 * against the running database, 2026-08-25). A single request can therefore
 * never return the whole matrix, and the screen that assumed it could would
 * silently draw 100 permissions and quietly drop 43 — with a Save that then
 * revoked every grant sitting in the missing 43, because `PATCH` takes the full
 * desired set.
 */
export async function listAllPermissions(): Promise<PermissionOption[]> {
    return allPages<PermissionOption>('/permissions');
}

/**
 * `PATCH /api/v1/roles/{id}/permissions` — §3.12 rule 5's configuration change.
 *
 * The body is the **complete** desired set, not a delta. That is the endpoint's
 * contract (`SyncRolePermissionsRequest`), and it is why the caller must hold
 * every page: an omitted id is a revocation.
 */
export async function updateRolePermissions(
    roleId: string,
    permissionIds: readonly string[],
): Promise<RolePermissionsUpdated> {
    const result = await apiPatch<AdministeredRole & { diff: RolePermissionsUpdated['diff'] }>(
        `/roles/${roleId}/permissions`,
        { permission_ids: [...permissionIds] },
    );

    const { diff, ...role } = result.data;

    return { role, diff };
}

/**
 * `GET /api/v1/auth/sessions` — `SEC-05`'s device list, the caller's own.
 *
 * Paged through for the same reason {@see listAllPermissions} is: the screen
 * shows every device and `OpenAPI §4.2` caps a page at 100. Nobody signs in on
 * 101 devices, and the loop costs one request in every realistic case — but a
 * list that silently stopped at a page boundary would be a device the owner
 * cannot see and therefore cannot revoke, which is the one thing `SEC-05` is
 * for.
 */
export async function listSessions(): Promise<DeviceSession[]> {
    return allPages<DeviceSession>('/auth/sessions');
}

/**
 * `DELETE /api/v1/auth/sessions/{id}` — one remote device.
 *
 * The caller's own session is refused with `422 session_is_current`: ending
 * this session is `POST /auth/logout`, which writes the right audit event and
 * lets the SPA drop the token it is holding.
 */
export async function revokeSession(sessionId: string): Promise<void> {
    await apiDelete(`/auth/sessions/${sessionId}`);
}

/**
 * `DELETE /api/v1/auth/sessions` — every device except this one.
 *
 * @return how many were revoked
 */
export async function revokeOtherSessions(): Promise<number> {
    const result = await apiDelete<{ revoked: number; current_session_kept: boolean }>('/auth/sessions');

    return result.data.revoked;
}

/**
 * `POST /api/v1/auth/change-password/challenge` — `SEC-04` step one.
 *
 * Answers `202`: the work the caller cares about is a mail on its way, and the
 * response is not evidence it arrived. `expires_in_minutes` comes from
 * `identity.password_challenge.ttl_minutes`, so the countdown is the server's
 * number rather than one this file guessed.
 */
export async function requestPasswordChallenge(): Promise<{ expires_in_minutes: number }> {
    const result = await apiPost<{ challenge_sent: boolean; expires_in_minutes: number }>(
        '/auth/change-password/challenge',
    );

    return { expires_in_minutes: result.data.expires_in_minutes };
}

/**
 * `POST /api/v1/auth/change-password` — §9 Flow 0's third and fourth steps.
 *
 * `sessions_revoked` counts **every** session including the calling one: the
 * flow ends "log in again", and the token that made this request is dead when
 * it returns. The caller must sign the user out locally rather than continue.
 */
export async function changePassword(payload: {
    currentPassword: string;
    newPassword: string;
    verificationCode: string;
}): Promise<{ sessions_revoked: number }> {
    const result = await apiPost<{
        password_changed: boolean;
        sessions_revoked: number;
        reauthentication_required: boolean;
    }>('/auth/change-password', {
        current_password: payload.currentPassword,
        new_password: payload.newPassword,
        // Laravel's `confirmed` rule expects this exact field name, and the
        // Form Request requires it. The two boxes are compared in the screen
        // before anything is sent; this is the server asking the same question.
        new_password_confirmation: payload.newPassword,
        verification_code: payload.verificationCode,
    });

    return { sessions_revoked: result.data.sessions_revoked };
}

export async function impersonate(userId: string): Promise<ImpersonationStarted> {
    return (await apiPost<ImpersonationStarted>(`/auth/impersonate/${userId}`)).data;
}

export async function leaveImpersonation(): Promise<void> {
    await apiPost('/auth/impersonate/leave');
}

/**
 * `OpenAPI §4.2`'s collection envelope, unwrapped.
 *
 * The pagination block is required by the contract on every list endpoint, so
 * its absence is a server that changed shape rather than a page to render
 * defensively — but a screen that threw here would show an error state for a
 * response that carried the rows fine, so the block is defaulted and the rows
 * are shown.
 */
/**
 * Follow `OpenAPI §4.2`'s `has_next_page` to the end of a listing.
 *
 * Bounded at 50 pages — 5000 rows at the maximum page size. A server that keeps
 * answering `has_next_page: true` is a bug, and an unbounded `while` turns that
 * bug into a browser tab that never stops fetching.
 */
async function allPages<T>(path: string): Promise<T[]> {
    const items: T[] = [];
    let page = 1;

    for (let guard = 0; guard < 50; guard += 1) {
        const separator = path.includes('?') ? '&' : '?';
        const result = collection<T>(await apiGet(`${path}${separator}page=${page}&per_page=100`));

        items.push(...result.items);

        if (!result.pagination.has_next_page) {
            break;
        }

        page += 1;
    }

    return items;
}

function collection<T>(result: ApiResult<unknown>): Page<T> {
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
