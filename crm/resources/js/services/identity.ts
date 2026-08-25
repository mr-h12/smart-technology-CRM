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
import { apiGet, apiPatch, apiPost, type ApiResult, type Pagination } from '@/api';

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
