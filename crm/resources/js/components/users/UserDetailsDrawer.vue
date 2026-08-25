<script setup lang="ts">
/**
 * §13 screen 2's detail half — "last login · devices · IP · browser", and the
 * force logout beside them.
 *
 * ── A drawer, not a route ──────────────────────────────────────────────────
 *
 * The administrator arrives here from a row of the employee list and goes back
 * to it: §6.5 asks for tables nobody has to leave to use, and a full page would
 * lose the filters and the page number the list is sitting on. It is a
 * `role="dialog"` with a labelled heading rather than a panel, because it
 * covers the list and Escape has to mean something.
 *
 * ── It reads two endpoints and joins nothing ───────────────────────────────
 *
 * `GET /users/{id}` for the profile and `GET /users/{id}/sessions` for the
 * devices. The **last activity** line is the newest `last_activity_at` among
 * the rows the server returned — computed here because it is a maximum over
 * data already fetched, not a field anybody invented.
 *
 * ⚠️ **That is "last activity", and §13 says "last login".** They are not the
 * same: `users` carries no `last_login_at` column, and the honest source for a
 * login is `audit_log`'s `LOGIN_SUCCEEDED`, which Identity may not read —
 * `deptrac.modules.yaml` allows Identity → AuditContract, and that contract is
 * a recorder with no reader. A person with no live session therefore shows no
 * time at all rather than a made-up one. Recorded as a gap, not papered over.
 *
 * ── Two permissions, two controls ──────────────────────────────────────────
 *
 * Reading answers to §3.11's `admin.create_user` (`D-78`); terminating answers
 * to `admin.deactivate_user`, the row that already ends every session an
 * account holds. `canTerminate` hides the button when the caller lacks it —
 * `SEC-09`'s visual complement, never the check: the API refuses regardless.
 */
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/components/users/ConfirmDialog.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import { ApiError } from '@/api';
import type { AdministeredUser, DeviceSession } from '@/services/identity';
import { fetchUser, listUserSessions, terminateUserSession } from '@/services/identity';

const props = defineProps<{
    open: boolean;
    /** Null while the drawer is closed; the list clears it on close. */
    userId: string | null;
    /** Whether §3.11's deactivate row is held. Presentation only. */
    canTerminate: boolean;
}>();

const emit = defineEmits<{ close: [] }>();

const { t, locale } = useI18n();

const user = ref<AdministeredUser | null>(null);
const sessions = ref<DeviceSession[]>([]);
const loading = ref(false);
const failed = ref(false);
const terminating = ref<DeviceSession | null>(null);
const busy = ref(false);
/** A lang-file key, or null. §6.6: explained in place, not only in a toast. */
const errorKey = ref<string | null>(null);
const noticeKey = ref<string | null>(null);

/** The newest activity among the rows the server returned. Null when there are none. */
const lastActivity = computed<string | null>(() => {
    let newest: string | null = null;

    for (const session of sessions.value) {
        if (newest === null || session.last_activity_at > newest) {
            newest = session.last_activity_at;
        }
    }

    return newest;
});

async function load(): Promise<void> {
    const id = props.userId;

    if (id === null) {
        return;
    }

    loading.value = true;
    failed.value = false;
    errorKey.value = null;
    noticeKey.value = null;

    try {
        // In parallel: the two reads are independent, and a drawer that waited
        // for the profile before asking for devices is two round trips deep
        // before it draws anything.
        const [profile, devices] = await Promise.all([fetchUser(id), listUserSessions(id)]);

        user.value = profile;
        sessions.value = devices;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

async function confirmTermination(): Promise<void> {
    const target = terminating.value;
    const id = props.userId;

    if (target === null || id === null) {
        return;
    }

    busy.value = true;
    errorKey.value = null;

    try {
        await terminateUserSession(id, target.id);
        terminating.value = null;
        // Re-read rather than splice: the list is the server's answer, and a
        // locally edited copy stops being a device list the moment two
        // administrators act at once.
        await load();
        noticeKey.value = 'users.details.terminated';
    } catch (error) {
        terminating.value = null;
        errorKey.value = messageFor(error);
    } finally {
        busy.value = false;
    }
}

/** `OpenAPI §5.1` — the stable code, not the HTTP status alone. */
function messageFor(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return 'users.details.error.unreachable';
    }

    if (error.is('session_is_current')) {
        return 'users.details.error.own';
    }

    if (error.is('session_not_found')) {
        return 'users.details.error.gone';
    }

    if (error.is('user_not_found')) {
        return 'users.details.error.userGone';
    }

    if (error.status === 403) {
        return 'users.details.error.denied';
    }

    return 'users.details.error.rejected';
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.open && !busy.value) {
        emit('close');
    }
}

watch(() => props.open, async (open) => {
    if (open) {
        user.value = null;
        sessions.value = [];
        document.addEventListener('keydown', onKeydown);
        await load();
    } else {
        document.removeEventListener('keydown', onKeydown);
    }
}, { immediate: true });

/**
 * `DB-08`: the server sends UTC and the browser converts. The locale is
 * vue-i18n's, so an Arabic session reads an Arabic date.
 */
function formatMoment(iso: string): string {
    const at = new Date(iso);

    if (Number.isNaN(at.getTime())) {
        return iso;
    }

    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(at);
}

/** The raw `User-Agent`, shortened. Not parsed — see `SessionPayload`. */
function shortAgent(userAgent: string | null): string {
    if (userAgent === null || userAgent.trim() === '') {
        return t('users.details.unknownDevice');
    }

    return userAgent.length > 64 ? `${userAgent.slice(0, 64)}…` : userAgent;
}
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-40 flex justify-end" data-testid="user-details-drawer">
        <div class="drawer-scrim absolute inset-0" @click="busy ? null : emit('close')" />

        <aside
            class="drawer-panel relative flex h-full w-full max-w-lg flex-col gap-4 overflow-y-auto p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="user-details-title"
        >
            <header class="flex items-start justify-between gap-3">
                <h2 id="user-details-title" class="text-section-title">{{ t('users.details.title') }}</h2>

                <button
                    type="button"
                    class="drawer-close min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="details-close"
                    @click="emit('close')"
                >
                    {{ t('action.close') }}
                </button>
            </header>

            <LoadingState v-if="loading" label-key="users.details.loading" />
            <ErrorState v-else-if="failed" @retry="load" />

            <template v-else-if="user !== null">
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2" data-testid="details-profile">
                    <dt class="text-table text-[var(--color-text-muted)]">{{ t('users.column.name') }}</dt>
                    <dd>{{ user.name }}</dd>

                    <dt class="text-table text-[var(--color-text-muted)]">{{ t('users.column.email') }}</dt>
                    <dd class="tabular-nums">{{ user.email }}</dd>

                    <dt class="text-table text-[var(--color-text-muted)]">{{ t('users.column.role') }}</dt>
                    <dd>{{ user.role.label }}</dd>

                    <dt class="text-table text-[var(--color-text-muted)]">{{ t('users.column.status') }}</dt>
                    <dd>
                        <!-- §9.5: the badge carries its own word, so the state
                             is legible without relying on colour. -->
                        <span
                            class="status-badge rounded-full px-2 py-0.5 text-table"
                            :class="user.is_active ? 'status-badge--active' : 'status-badge--suspended'"
                            data-testid="details-status"
                        >
                            {{ user.is_active ? t('users.status.active') : t('users.status.suspended') }}
                        </span>
                    </dd>

                    <dt class="text-table text-[var(--color-text-muted)]">{{ t('users.details.lastActivity') }}</dt>
                    <dd class="tabular-nums" data-testid="details-last-activity">
                        {{ lastActivity === null ? t('users.details.neverActive') : formatMoment(lastActivity) }}
                    </dd>
                </dl>

                <section class="flex flex-col gap-3">
                    <div class="flex flex-col gap-1">
                        <h3 class="text-card-title">{{ t('users.details.devices') }}</h3>
                        <p class="text-table text-[var(--color-text-muted)] text-pretty">
                            {{ t('users.details.devicesExplainer') }}
                        </p>
                    </div>

                    <p
                        v-if="errorKey !== null"
                        class="alert rounded-lg p-3 text-table"
                        role="alert"
                        data-testid="details-error"
                    >
                        {{ t(errorKey) }}
                    </p>

                    <p
                        v-if="noticeKey !== null"
                        class="notice rounded-lg p-3 text-table"
                        role="status"
                        aria-live="polite"
                        data-testid="details-notice"
                    >
                        {{ t(noticeKey) }}
                    </p>

                    <!-- §8's empty state, spelled out: a person who is signed in
                         nowhere and a list that failed to load must not look
                         the same. -->
                    <p
                        v-if="sessions.length === 0"
                        class="text-[var(--color-text-muted)]"
                        data-testid="details-no-devices"
                    >
                        {{ t('users.details.noDevices') }}
                    </p>

                    <ul v-else class="flex flex-col gap-2" data-testid="details-devices">
                        <li
                            v-for="session in sessions"
                            :key="session.id"
                            class="device flex flex-wrap items-center gap-3 rounded-xl p-3"
                            data-testid="details-device"
                        >
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="min-w-0 truncate" :title="session.user_agent ?? ''">
                                    {{ shortAgent(session.user_agent) }}
                                </span>
                                <span class="text-table tabular-nums text-[var(--color-text-muted)]">
                                    {{ t('users.details.deviceMeta', {
                                        ip: session.ip_address ?? t('users.details.unknownIp'),
                                        active: formatMoment(session.last_activity_at),
                                    }) }}
                                </span>
                            </div>

                            <!-- Hidden on the administrator's own current row:
                                 the API answers `422 session_is_current` and
                                 points at sign-out, so the button would be an
                                 offer of a refusal. -->
                            <button
                                v-if="canTerminate && !session.is_current"
                                type="button"
                                class="ghost-danger min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="busy"
                                :data-testid="`details-revoke-${session.id}`"
                                @click="terminating = session"
                            >
                                {{ t('users.details.revoke') }}
                            </button>
                        </li>
                    </ul>
                </section>
            </template>
        </aside>

        <ConfirmDialog
            :open="terminating !== null"
            title-key="users.details.confirm.title"
            message-key="users.details.confirm.message"
            confirm-key="users.details.confirm.action"
            :subject="user?.name ?? ''"
            :busy="busy"
            danger
            @confirm="confirmTermination"
            @cancel="terminating = null"
        />
    </div>
</template>

<style scoped>
.drawer-scrim {
    background-color: color-mix(in srgb, var(--color-text) 45%, transparent);
}

.drawer-panel {
    background-color: var(--color-surface);
    /* The inline axis: the drawer enters from the end side, which is the right
       in English and the left in Arabic. */
    border-inline-start: 1px solid var(--color-border);
    box-shadow: var(--shadow-3, var(--shadow-2));
}

.drawer-close {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.device {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border);
}

.status-badge--active {
    background-color: color-mix(in srgb, var(--color-success) 14%, transparent);
    color: var(--color-success);
}

.status-badge--suspended {
    background-color: color-mix(in srgb, var(--color-warning) 16%, transparent);
    color: var(--color-warning);
}

.alert {
    background-color: color-mix(in srgb, var(--color-danger) 12%, transparent);
    color: var(--color-danger);
}

.notice {
    background-color: color-mix(in srgb, var(--color-success) 12%, transparent);
    color: var(--color-success);
}

.ghost-danger {
    background-color: var(--color-surface);
    border: 1px solid var(--color-danger);
    color: var(--color-danger);
}
</style>
