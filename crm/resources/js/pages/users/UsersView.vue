<script setup lang="ts">
/**
 * §8's *Employees* screen and §9 Flow 9 — Module 1's user administration.
 *
 * ── The list is filtered by the server, not here ───────────────────────────
 *
 * §3.12 rule 6: "The Super Admin is hidden — never listed in any user list, for
 * any role." `User::scopeListable()` applies that inside
 * `EloquentUserDirectory`, so a hidden account never reaches this component and
 * there is nothing here to filter. That is deliberate: a client-side filter on
 * a `is_hidden` field would require the field to be sent, and a field telling a
 * client which account to omit is a field telling it the account exists.
 *
 * ── No search box, and that is not an omission ─────────────────────────────
 *
 * `UserListCriteria` declares two filters — `is_active` and `role` — and
 * deliberately omits free text: `OpenAPI §6.2` routes `q` through
 * `SearchService`, which `CLAUDE.md` builds in **Module 3**. An input that sent
 * `?q=` would be silently ignored by the parser, which is a search box that
 * does nothing. Recorded as a gap rather than faked.
 *
 * ── Login As is drawn only for the Super Admin ─────────────────────────────
 *
 * `SEC-10` restricts it, and the server asks twice (the route's
 * `permission:admin.login_as` and `StartImpersonation`'s own
 * `hasUnconditionalAccess()`, because §3.12 rule 5 makes the matrix
 * configuration). Hiding the button is `SEC-09`'s "visual complement", never
 * the check.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useAuth } from '@/stores/auth';
import { landingRouteFor } from '@/router';
import LoadingState from '@/components/states/LoadingState.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import ConfirmDialog from '@/components/users/ConfirmDialog.vue';
import UserFormModal from '@/components/users/UserFormModal.vue';
import UserDetailsDrawer from '@/components/users/UserDetailsDrawer.vue';
import type { AdministeredUser, Pagination, RoleOption } from '@/services/identity';
import { listAssignableRoles, listUsers, setUserActivation } from '@/services/identity';

const { t } = useI18n();
const router = useRouter();
const auth = useAuth();

const users = ref<AdministeredUser[]>([]);
const roles = ref<RoleOption[]>([]);
const pagination = ref<Pagination | null>(null);
const loading = ref(true);
const failed = ref(false);
const busy = ref(false);

const page = ref(1);
const activeFilter = ref<'all' | 'active' | 'inactive'>('all');
const roleFilter = ref('');

const editing = ref<AdministeredUser | null>(null);
const formOpen = ref(false);
const confirming = ref<AdministeredUser | null>(null);

/**
 * §13 screen 2's detail drawer. The id rather than the row, so the drawer
 * re-reads from the server: the list's copy is a page that may be minutes old,
 * and the devices it shows have to be current or the force logout is aimed at
 * a session that has already gone.
 */
const inspecting = ref<string | null>(null);

/**
 * §3.11's deactivate row, which is what `DELETE /users/{id}/sessions/{id}`
 * names. `SEC-09`: hiding the control is the visual complement, never the
 * check — the API refuses regardless of what this computes.
 */
const canTerminateSessions = computed(() => auth.hasPermission('admin.deactivate_user'));

/**
 * True for a caller who may read `GET /api/v1/roles`.
 *
 * ✅ **The gap this used to name is closed (Point 4.2).** §3.11 gives the
 * Manager "create user … Out.Sup · Out.Sales · Sales · Procurement only", and
 * until Point 4.2 the role listing carried `admin.manage_roles` — a row §3.11
 * gives to the Super Admin alone — so the Manager could not obtain a `role_id`
 * and the form was permanently unavailable to them. The endpoint now carries
 * `admin.create_user` and narrows the page to
 * `RoleAssignmentPolicy::assignableBy()`, which is the same row this screen
 * already requires to load at all.
 *
 * It is still asked rather than assumed: §3.12 rule 5 lets an administrator
 * build a role that reaches this screen through some later grant, and a fetch
 * that 403s should leave the list empty and the notice visible rather than
 * failing the whole screen.
 */
const canLoadRoles = computed(() => auth.hasPermission('admin.create_user'));

const canCreate = computed(() => roles.value.length > 0);

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;

    try {
        const result = await listUsers({
            page: page.value,
            isActive: activeFilter.value === 'all' ? null : activeFilter.value === 'active',
            roleSlug: roleFilter.value === '' ? null : roleFilter.value,
        });

        users.value = result.items;
        pagination.value = result.pagination;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

async function loadRoles(): Promise<void> {
    if (!canLoadRoles.value) {
        return;
    }

    try {
        roles.value = await listAssignableRoles();
    } catch {
        // A 403 here is §3.11 answering correctly. The screen still lists
        // people; only the form is unavailable, and it says so.
        roles.value = [];
    }
}

async function applyFilters(): Promise<void> {
    page.value = 1;
    await load();
}

async function goToPage(target: number): Promise<void> {
    page.value = target;
    await load();
}

function openCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(user: AdministeredUser): void {
    editing.value = user;
    formOpen.value = true;
}

async function onSaved(): Promise<void> {
    formOpen.value = false;
    editing.value = null;
    await load();
}

/**
 * `D-34` and §10.1 — deactivate, never delete.
 *
 * Reloading rather than patching the row in place: deactivation also revokes
 * every session that account holds (`SEC-05`), and the list is the only place
 * that shows the result. A local mutation would show the new badge and hide
 * that anything else happened.
 */
async function confirmActivation(): Promise<void> {
    const target = confirming.value;

    if (target === null) {
        return;
    }

    busy.value = true;

    try {
        await setUserActivation(target.id, !target.is_active);
        confirming.value = null;
        await load();
    } catch {
        failed.value = true;
        confirming.value = null;
    } finally {
        busy.value = false;
    }
}

/** `SEC-10`. On success the whole session becomes the target's, so the SPA leaves for their screen. */
async function loginAs(user: AdministeredUser): Promise<void> {
    busy.value = true;

    try {
        if (await auth.startImpersonation(user.id)) {
            await router.replace({
                name: landingRouteFor(auth.role.value, (name) => router.hasRoute(name)),
            });
        }
    } finally {
        busy.value = false;
    }
}

onMounted(async () => {
    await Promise.all([load(), loadRoles()]);
});
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div class="flex flex-col gap-1">
                <h1 class="text-page-title">{{ t('users.title') }}</h1>
                <p class="text-[var(--color-text-muted)]">{{ t('users.subtitle') }}</p>
            </div>

            <button
                type="button"
                class="primary-action min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="!canCreate"
                data-testid="users-create"
                @click="openCreate"
            >
                {{ t('users.create') }}
            </button>
        </header>

        <!-- The gap, stated on the screen rather than as a button that fails. -->
        <p
            v-if="!canCreate"
            class="notice rounded-lg p-3 text-pretty"
            role="note"
            data-testid="users-no-roles-notice"
        >
            {{ t('users.rolesUnavailable') }}
        </p>

        <div class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1.5">
                <span>{{ t('users.filter.status') }}</span>
                <select
                    v-model="activeFilter"
                    class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="users-filter-status"
                    @change="applyFilters"
                >
                    <option value="all">{{ t('users.filter.statusAll') }}</option>
                    <option value="active">{{ t('users.status.active') }}</option>
                    <option value="inactive">{{ t('users.status.suspended') }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1.5">
                <span>{{ t('users.filter.role') }}</span>
                <select
                    v-model="roleFilter"
                    class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="users-filter-role"
                    @change="applyFilters"
                >
                    <option value="">{{ t('users.filter.roleAll') }}</option>
                    <option v-for="role in roles" :key="role.id" :value="role.slug">{{ role.label }}</option>
                </select>
            </label>
        </div>

        <LoadingState v-if="loading" label-key="users.loading" />
        <ErrorState v-else-if="failed" @retry="load" />
        <EmptyState v-else-if="users.length === 0" />

        <div v-else class="table-frame overflow-x-auto rounded-xl">
            <table class="w-full text-table" data-testid="users-table">
                <thead>
                    <tr class="table-head">
                        <th scope="col" class="p-3 text-start">{{ t('users.column.name') }}</th>
                        <th scope="col" class="p-3 text-start">{{ t('users.column.email') }}</th>
                        <th scope="col" class="p-3 text-start">{{ t('users.column.role') }}</th>
                        <th scope="col" class="p-3 text-start">{{ t('users.column.status') }}</th>
                        <th scope="col" class="p-3 text-end">{{ t('users.column.actions') }}</th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="user in users" :key="user.id" class="table-row" data-testid="users-row">
                        <td class="p-3">{{ user.name }}</td>
                        <!-- D-70: Inter leads --font-sans, so Western digits and
                             Latin addresses are drawn by it in both locales.
                             tabular-nums keeps columns of figures aligned. -->
                        <td class="p-3 tabular-nums">{{ user.email }}</td>
                        <td class="p-3">{{ user.role.label }}</td>
                        <td class="p-3">
                            <!-- §9.5: legible without relying on colour alone —
                                 the badge carries its own word. -->
                            <span
                                class="status-badge rounded-full px-2 py-0.5"
                                :class="user.is_active ? 'status-badge--active' : 'status-badge--suspended'"
                                data-testid="users-status"
                            >
                                {{ user.is_active ? t('users.status.active') : t('users.status.suspended') }}
                            </span>
                        </td>
                        <td class="p-3">
                            <div class="flex flex-wrap justify-end gap-2">
                                <button
                                    type="button"
                                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    :disabled="busy"
                                    data-testid="users-details"
                                    @click="inspecting = user.id"
                                >
                                    {{ t('users.viewDetails') }}
                                </button>

                                <button
                                    type="button"
                                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    :disabled="busy || !canCreate"
                                    data-testid="users-edit"
                                    @click="openEdit(user)"
                                >
                                    {{ t('action.edit') }}
                                </button>

                                <button
                                    type="button"
                                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    :disabled="busy"
                                    data-testid="users-toggle-activation"
                                    @click="confirming = user"
                                >
                                    {{ user.is_active ? t('users.deactivate') : t('users.reactivate') }}
                                </button>

                                <button
                                    v-if="auth.isSuperAdmin.value && !auth.isImpersonating.value"
                                    type="button"
                                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    :disabled="busy || !user.is_active"
                                    data-testid="users-login-as"
                                    @click="loginAs(user)"
                                >
                                    {{ t('users.loginAs') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="pagination !== null && pagination.total_pages > 1"
            class="flex items-center justify-between gap-3"
            :aria-label="t('users.pagination.label')"
            data-testid="users-pagination"
        >
            <button
                type="button"
                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="!pagination.has_previous_page || loading"
                data-testid="users-previous"
                @click="goToPage(pagination.page - 1)"
            >
                {{ t('users.pagination.previous') }}
            </button>

            <p class="tabular-nums text-[var(--color-text-muted)]">
                {{ t('users.pagination.position', { page: pagination.page, pages: pagination.total_pages, total: pagination.total }) }}
            </p>

            <button
                type="button"
                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="!pagination.has_next_page || loading"
                data-testid="users-next"
                @click="goToPage(pagination.page + 1)"
            >
                {{ t('users.pagination.next') }}
            </button>
        </nav>

        <UserFormModal
            :open="formOpen"
            :editing="editing"
            :roles="roles"
            :actor-role="auth.role.value"
            @saved="onSaved"
            @cancel="formOpen = false"
        />

        <UserDetailsDrawer
            :open="inspecting !== null"
            :user-id="inspecting"
            :can-terminate="canTerminateSessions"
            @close="inspecting = null"
        />

        <ConfirmDialog
            :open="confirming !== null"
            :title-key="confirming?.is_active === true ? 'users.confirm.deactivateTitle' : 'users.confirm.reactivateTitle'"
            :message-key="confirming?.is_active === true ? 'users.confirm.deactivateMessage' : 'users.confirm.reactivateMessage'"
            :confirm-key="confirming?.is_active === true ? 'users.deactivate' : 'users.reactivate'"
            :subject="confirming?.name ?? ''"
            :busy="busy"
            :danger="confirming?.is_active === true"
            @confirm="confirmActivation"
            @cancel="confirming = null"
        />
    </section>
</template>

<style scoped>
.primary-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

.row-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.notice {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-warning);
    color: var(--color-text);
}

.table-frame {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.table-head {
    background-color: var(--color-surface-muted);
    color: var(--color-text-muted);
}

.table-row {
    border-block-start: 1px solid var(--color-border);
}

.status-badge--active {
    background-color: color-mix(in srgb, var(--color-success) 14%, transparent);
    color: var(--color-success);
}

.status-badge--suspended {
    background-color: color-mix(in srgb, var(--color-status-neutral) 14%, transparent);
    color: var(--color-status-neutral);
}
</style>
