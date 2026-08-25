<script setup lang="ts">
/**
 * §13 screen 3 — *Roles & Permissions (RBAC)*, and the screen §3.12 rule 5
 * describes: "changing this matrix is a configuration change, not a
 * deployment."
 *
 * ── One role at a time, not eight columns ──────────────────────────────────
 *
 * The brief allowed either. Eight roles × 143 permission rows is 1144
 * checkboxes on one page, and the *cells* are not booleans — §3.2 makes a
 * permission a `resource.action.scope` triple, so a role holding
 * `customer.view.team` and a role holding `customer.view.all` are two different
 * grants of the same row. A role selector plus a scope-per-column grid says
 * that; a role-per-column grid would have to collapse the scope into the cell
 * and stop being readable at the first row where two roles differ only by
 * scope. §6.5 also asks for tables nobody has to load all of at once.
 *
 * ── The grid draws what exists, and never a triple it invented ─────────────
 *
 * A cell is a checkbox only where `GET /permissions` returned a row for that
 * `resource.action` **and** that scope. Everywhere else it prints §3.2's own
 * `—`. The client cannot mint a permission id, so a scope with no row is a
 * scope that cannot be granted — showing an enabled box there would be offering
 * a `422 permission_not_found`.
 *
 * ── Two locks, and they are different rules ────────────────────────────────
 *
 * `is_editable === false` is §3.1's Super Admin: unconditional access is
 * answered before any grant row is read, so editing its rows would report
 * success and change nothing. `is_grantable === false` is §3.12 rule 3, a
 * property of the `resource.action` and therefore of the whole row. Both are
 * **server-derived** — `RoleView::hasUnconditionalAccess()` and
 * `PermissionMatrix::forbiddenKeys()` — and neither is re-decided here.
 *
 * ── Saving sends the whole set, so the whole set has to be loaded ──────────
 *
 * `PATCH /roles/{id}/permissions` takes the desired grants, not a delta. That
 * is why {@see listAllPermissions} pages: 143 rows against a `per_page` maximum
 * of 100 means a screen that fetched one page would revoke everything in the
 * other 43 on the first save.
 *
 * `SEC-09`/§3.12 rule 1: everything below is presentation. The route guard
 * names `admin.manage_roles` because the four endpoints do, and the API refuses
 * regardless of what this screen draws.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import LoadingState from '@/components/states/LoadingState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import PermissionDiffModal from '@/components/roles/PermissionDiffModal.vue';
import RoleFormModal from '@/components/roles/RoleFormModal.vue';
import ConfirmDialog from '@/components/users/ConfirmDialog.vue';
import { SCOPES, diffGrants, groupPermissions, isRowLocked, scopeCells } from '@/domain/permissionMatrix';
import type { AdministeredRole, PermissionOption } from '@/services/identity';
import { archiveRole, createRole, listAllPermissions, listRoles, updateRolePermissions } from '@/services/identity';

const { t, te } = useI18n();

const roles = ref<AdministeredRole[]>([]);
const permissions = ref<PermissionOption[]>([]);
const selectedRoleId = ref('');
const staged = ref<Set<string>>(new Set());

const loading = ref(true);
const failed = ref(false);
const saving = ref(false);
const confirming = ref(false);
/** A lang-file key, or null. §6.6: an error is explained in place, not only in a toast. */
const saveError = ref<string | null>(null);
const saveResult = ref<{ granted: number; revoked: number } | null>(null);

/**
 * §13 screen 3's create, and the archive that retires what it made.
 *
 * Both live on this screen rather than on a page of their own: §3.12 rule 5
 * makes creating a role and granting it permissions one act of configuration,
 * and a new role with no grants is the one state an administrator must not be
 * left in silently. The modal says so (`roles.create.thenGrant`) and the screen
 * selects the new role the moment it exists.
 */
const creating = ref(false);
const createBusy = ref(false);
const createError = ref<string | null>(null);

/** The role the confirmation is about, or null. */
const archivingId = ref<string | null>(null);
const archiveBusy = ref(false);

const archiving = computed<AdministeredRole | null>(
    () => roles.value.find((role) => role.id === archivingId.value) ?? null,
);

/**
 * `is_system` here, and `is_editable` on the grid — two different rules.
 *
 * The grid locks §3.1's unconditional-access role, because editing its grants
 * would report success and change nothing. This locks all eight, because
 * `RolePermissionSeeder` rewrites their labels and restores them if trashed, so
 * an archive is a change that undoes itself. The server refuses both
 * independently (§3.12 rule 1); this only decides which control is drawn.
 */
const archivable = computed(() => selectedRole.value !== null && !selectedRole.value.is_system);

const groups = computed(() => groupPermissions(permissions.value));

const byId = computed(() => new Map(permissions.value.map((permission) => [permission.id, permission])));

const selectedRole = computed<AdministeredRole | null>(
    () => roles.value.find((role) => role.id === selectedRoleId.value) ?? null,
);

/** What the server says this role holds right now — the baseline every diff is against. */
const original = computed(() => new Set((selectedRole.value?.permissions ?? []).map((p) => p.id)));

const editable = computed(() => selectedRole.value?.is_editable === true);

const diff = computed(() => diffGrants(original.value, staged.value, byId.value));

const changeCount = computed(() => diff.value.granted.length + diff.value.revoked.length);

function resourceLabel(resource: string): string {
    // §3.3–§3.11's section names, when the lang files know the resource. A
    // resource a later module adds falls back to its own identifier rather than
    // rendering a raw translation key — the label is data at that point, not
    // prose this project failed to translate.
    const key = `roles.resource.${resource}`;

    return te(key) ? t(key) : resource;
}

function isChecked(permission: PermissionOption): boolean {
    return staged.value.has(permission.id);
}

/** A staged cell differs from what the server holds — §6.4's "pending". */
function isPending(permission: PermissionOption): boolean {
    return staged.value.has(permission.id) !== original.value.has(permission.id);
}

function toggle(permission: PermissionOption): void {
    if (!editable.value || !permission.is_grantable || saving.value) {
        return;
    }

    // A new Set rather than a mutation: `ref` tracks the reference, and an
    // in-place `add` on the same object leaves every computed above stale.
    const next = new Set(staged.value);

    if (next.has(permission.id)) {
        next.delete(permission.id);
    } else {
        next.add(permission.id);
    }

    staged.value = next;
}

function selectRole(roleId: string): void {
    selectedRoleId.value = roleId;
    saveError.value = null;
    saveResult.value = null;
    discard();
}

/** Back to what the server holds. Nothing is sent; nothing was. */
function discard(): void {
    staged.value = new Set(original.value);
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;

    try {
        const [loadedRoles, loadedPermissions] = await Promise.all([listRoles(), listAllPermissions()]);

        roles.value = loadedRoles;
        permissions.value = loadedPermissions;

        if (selectedRoleId.value === '' || !loadedRoles.some((role) => role.id === selectedRoleId.value)) {
            // The first **editable** role, not simply the first: opening on the
            // Super Admin would greet an administrator with a grid that cannot
            // be touched and no indication that any other role can.
            selectedRoleId.value = (loadedRoles.find((role) => role.is_editable) ?? loadedRoles[0])?.id ?? '';
        }

        discard();
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

/**
 * §3.12 rule 5's round trip, and the proof it needs no deployment: the response
 * carries the role as it now stands, so the grid is rebuilt from the server's
 * answer rather than from the boxes that were clicked.
 */
async function save(): Promise<void> {
    const role = selectedRole.value;

    if (role === null || !editable.value) {
        return;
    }

    saving.value = true;
    saveError.value = null;

    try {
        const result = await updateRolePermissions(role.id, [...staged.value]);

        roles.value = roles.value.map((existing) => (existing.id === result.role.id ? result.role : existing));
        staged.value = new Set(result.role.permissions.map((permission) => permission.id));
        saveResult.value = { granted: result.diff.granted.length, revoked: result.diff.revoked.length };
        confirming.value = false;
    } catch (error) {
        saveError.value = messageFor(error);
        confirming.value = false;
    } finally {
        saving.value = false;
    }
}

/**
 * §13 screen 3's "create new roles".
 *
 * The whole list is re-read rather than the response appended: the new role has
 * to land in the server's own ordering (`ReferenceListCriteria::ROLE_DEFAULT_SORT`
 * is `name`), and a row pushed onto the end would sit somewhere the next reload
 * moves it away from.
 */
async function submitRole(input: {
    slug: string;
    name: string;
    name_ar: string | null;
    description: string | null;
}): Promise<void> {
    createBusy.value = true;
    createError.value = null;

    try {
        const created = await createRole(input);

        await load();

        // Selected straight away, because a role with no grants is exactly the
        // state the modal just warned about and the grid is where it is fixed.
        selectRole(created.id);
        creating.value = false;
    } catch (error) {
        createError.value = createMessageFor(error);
    } finally {
        createBusy.value = false;
    }
}

/** The archive, `DB-01`: no row is removed and the grants go with it. */
async function confirmArchive(): Promise<void> {
    const role = archiving.value;

    if (role === null) {
        return;
    }

    archiveBusy.value = true;
    saveError.value = null;

    try {
        await archiveRole(role.id);

        archivingId.value = null;
        selectedRoleId.value = '';
        await load();
    } catch (error) {
        saveError.value = messageFor(error);
        archivingId.value = null;
    } finally {
        archiveBusy.value = false;
    }
}

/** `OpenAPI §5.1` — the create form's own refusals, by field. */
function createMessageFor(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return 'roles.error.unreachable';
    }

    if (error.is('slug_already_taken')) {
        return 'roles.error.slugTaken';
    }

    if (error.is('name_already_taken')) {
        return 'roles.error.nameTaken';
    }

    if (error.is('name_ar_already_taken')) {
        return 'roles.error.nameArTaken';
    }

    if (error.status === 422) {
        return 'roles.error.invalid';
    }

    if (error.status === 403) {
        return 'roles.error.denied';
    }

    return 'roles.error.rejected';
}

/** `OpenAPI §5.1` — the stable code, not the HTTP status alone. */
function messageFor(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return 'roles.error.unreachable';
    }

    if (error.is('role_is_immutable')) {
        return 'roles.error.immutable';
    }

    if (error.is('grant_forbidden')) {
        return 'roles.error.forbidden';
    }

    if (error.is('permission_not_found')) {
        return 'roles.error.staleMatrix';
    }

    if (error.is('system_role_cannot_be_deleted')) {
        return 'roles.error.systemRole';
    }

    if (error.is('role_has_assigned_users')) {
        return 'roles.error.roleInUse';
    }

    if (error.status === 404) {
        return 'roles.error.gone';
    }

    if (error.status === 403) {
        return 'roles.error.denied';
    }

    return 'roles.error.rejected';
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex flex-col gap-1">
                <h1 class="text-page-title">{{ t('roles.title') }}</h1>
                <p class="text-[var(--color-text-muted)] text-pretty">{{ t('roles.subtitle') }}</p>
            </div>

            <!-- §13 screen 3: "create new roles". Drawn unconditionally because
                 the route guard already names `admin.manage_roles` and §3.11
                 gives that row to the Super Admin alone — there is no caller who
                 reaches this screen and may not use it. §3.12 rule 1 still holds
                 underneath: `POST /roles` refuses on its own. -->
            <button
                v-if="!loading && !failed"
                type="button"
                class="primary-action min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                data-testid="roles-create"
                @click="creating = true; createError = null"
            >
                {{ t('roles.create.action') }}
            </button>
        </header>

        <LoadingState v-if="loading" label-key="roles.loading" />
        <ErrorState v-else-if="failed" @retry="load" />

        <template v-else>
            <div
                class="flex flex-wrap gap-2"
                role="tablist"
                :aria-label="t('roles.selector.label')"
                data-testid="roles-selector"
            >
                <button
                    v-for="role in roles"
                    :key="role.id"
                    type="button"
                    role="tab"
                    :aria-selected="role.id === selectedRoleId"
                    class="role-tab min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :class="role.id === selectedRoleId ? 'role-tab--active' : ''"
                    data-testid="roles-tab"
                    @click="selectRole(role.id)"
                >
                    {{ role.label }}
                    <span
                        v-if="!role.is_editable"
                        class="immutable-chip rounded-full px-2 py-0.5"
                        data-testid="roles-immutable-chip"
                    >
                        {{ t('roles.badge.immutable') }}
                    </span>
                </button>
            </div>

            <!-- §3.1, not `is_system`: all eight seeded roles are system roles,
                 and freezing them all would delete §3.12 rule 5 outright. -->
            <p
                v-if="selectedRole !== null && !editable"
                class="notice notice--warning rounded-lg p-3 text-pretty"
                role="note"
                data-testid="roles-immutable-notice"
            >
                {{ t('roles.immutableNotice', { role: selectedRole.label }) }}
            </p>

            <p
                v-if="saveError !== null"
                class="notice notice--danger rounded-lg p-3 text-pretty"
                role="alert"
                data-testid="roles-save-error"
            >
                {{ t(saveError) }}
            </p>

            <!-- §6.6 allows a toast for concise completion feedback; it is
                 written here as a live region so a screen reader is told too,
                 and so the sentence stays on the page rather than expiring. -->
            <p
                v-if="saveResult !== null"
                class="notice notice--success rounded-lg p-3 text-pretty tabular-nums"
                role="status"
                aria-live="polite"
                data-testid="roles-save-result"
            >
                {{
                    saveResult.granted + saveResult.revoked === 0
                        ? t('roles.savedNoChange')
                        : t('roles.saved', { granted: saveResult.granted, revoked: saveResult.revoked })
                }}
            </p>

            <section
                v-for="group in groups"
                :key="group.resource"
                class="table-frame flex flex-col rounded-xl"
                data-testid="roles-group"
            >
                <h2 class="group-heading text-card-title p-3">{{ resourceLabel(group.resource) }}</h2>

                <div class="overflow-x-auto">
                    <table class="w-full text-table" data-testid="roles-matrix">
                        <thead>
                            <tr class="table-head">
                                <th scope="col" class="p-3 text-start">{{ t('roles.column.permission') }}</th>
                                <th
                                    v-for="scope in SCOPES"
                                    :key="scope"
                                    scope="col"
                                    class="p-3 text-center"
                                >
                                    {{ t(`roles.scope.${scope}`) }}
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="row in group.rows"
                                :key="row.key"
                                class="table-row"
                                data-testid="roles-row"
                                :data-permission="row.key"
                            >
                                <th scope="row" class="p-3 text-start font-normal">
                                    <span class="permission-key">{{ row.key }}</span>

                                    <!-- §3.12 rule 3. §6.1: a disabled control
                                         explains itself when the reason is not
                                         obvious. -->
                                    <span
                                        v-if="isRowLocked(row)"
                                        class="locked-chip rounded-full px-2 py-0.5"
                                        data-testid="roles-locked-chip"
                                        :title="t('roles.lockedReason')"
                                    >
                                        {{ t('roles.badge.locked') }}
                                    </span>

                                    <span
                                        v-if="row.unknownScopes.length > 0"
                                        class="locked-chip rounded-full px-2 py-0.5"
                                        data-testid="roles-unknown-scope"
                                    >
                                        {{ t('roles.badge.unknownScope', { scopes: row.unknownScopes.join(', ') }) }}
                                    </span>
                                </th>

                                <td v-for="cell in scopeCells(row)" :key="cell.scope" class="p-3 text-center">
                                    <template v-if="cell.permission !== null">
                                        <input
                                            type="checkbox"
                                            class="matrix-toggle"
                                            :class="isPending(cell.permission) ? 'matrix-toggle--pending' : ''"
                                            :checked="isChecked(cell.permission)"
                                            :disabled="!editable || !cell.permission.is_grantable || saving"
                                            :aria-label="t('roles.toggle.label', { permission: row.key, scope: t(`roles.scope.${cell.scope}`) })"
                                            :data-testid="`roles-toggle-${cell.permission.triple}`"
                                            @change="toggle(cell.permission)"
                                        />
                                    </template>
                                    <!-- §3.2's own code for "not permitted": no
                                         row exists, so there is nothing to grant. -->
                                    <span v-else class="text-[var(--color-text-muted)]">{{ t('roles.cell.notDefined') }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="action-bar flex flex-wrap items-center justify-between gap-3 rounded-xl p-3">
                <p class="tabular-nums" data-testid="roles-pending">
                    {{ changeCount === 0 ? t('roles.changes.none') : t('roles.changes.pending', { count: changeCount }) }}
                </p>

                <div class="flex flex-wrap gap-2">
                    <!-- Only for a role an administrator added. §3.1's eight are
                         restored by the seeder on its next run, so archiving one
                         is a change that undoes itself — the server refuses it
                         and the control is not drawn. -->
                    <button
                        v-if="archivable"
                        type="button"
                        class="danger-action min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="saving || archiveBusy"
                        data-testid="roles-archive"
                        @click="archivingId = selectedRoleId"
                    >
                        {{ t('roles.archive.action') }}
                    </button>

                    <button
                        type="button"
                        class="secondary-action min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="changeCount === 0 || saving"
                        data-testid="roles-discard"
                        @click="discard"
                    >
                        {{ t('roles.discard') }}
                    </button>

                    <button
                        type="button"
                        class="primary-action min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="!editable || changeCount === 0 || saving"
                        data-testid="roles-save"
                        @click="confirming = true"
                    >
                        {{ t('roles.save') }}
                    </button>
                </div>
            </div>
        </template>

        <PermissionDiffModal
            :open="confirming"
            :role-name="selectedRole?.label ?? ''"
            :granted="diff.granted"
            :revoked="diff.revoked"
            :busy="saving"
            @confirm="save"
            @cancel="confirming = false"
        />

        <RoleFormModal
            :open="creating"
            :busy="createBusy"
            :error-key="createError"
            @submit="submitRole"
            @cancel="creating = false"
        />

        <!-- §6.6: a consequential action states its consequence first. The
             message names the grants that go with the role, because they are
             the part an administrator cannot see from the tab they clicked. -->
        <ConfirmDialog
            :open="archiving !== null"
            title-key="roles.archive.confirm.title"
            message-key="roles.archive.confirm.message"
            confirm-key="roles.archive.confirm.action"
            :subject="archiving?.label ?? ''"
            :busy="archiveBusy"
            danger
            @confirm="confirmArchive"
            @cancel="archivingId = null"
        />
    </section>
</template>

<style scoped>
.role-tab {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.role-tab--active {
    background-color: var(--color-primary);
    border-color: var(--color-primary);
    color: var(--color-primary-text);
}

.immutable-chip,
.locked-chip {
    background-color: color-mix(in srgb, var(--color-warning) 18%, transparent);
    color: var(--color-warning);
    margin-inline-start: 0.5rem;
}

.permission-key {
    font-family: var(--font-mono, ui-monospace, monospace);
}

.notice {
    background-color: var(--color-surface-muted);
    color: var(--color-text);
}

.notice--warning {
    border: 1px solid var(--color-warning);
}

.notice--danger {
    border: 1px solid var(--color-danger);
    color: var(--color-danger);
}

.notice--success {
    border: 1px solid var(--color-success);
}

.table-frame {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.group-heading {
    border-block-end: 1px solid var(--color-border);
}

.table-head {
    background-color: var(--color-surface-muted);
    color: var(--color-text-muted);
}

.table-row {
    border-block-start: 1px solid var(--color-border);
}

.matrix-toggle {
    accent-color: var(--color-primary);
    block-size: 1.15rem;
    inline-size: 1.15rem;
}

.matrix-toggle:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}

/* §6.4: a pending change is legible without relying on colour alone — the
   footer states the count in words, and this only draws attention to where. */
.matrix-toggle--pending {
    outline: 2px solid var(--color-warning);
    outline-offset: 2px;
}

.action-bar {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.primary-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

.secondary-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

/* §6.4 and §9.5: the button carries its own word, so the colour is an accent on
   a distinction that is already legible without it. */
.danger-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-danger);
    color: var(--color-danger);
}
</style>
