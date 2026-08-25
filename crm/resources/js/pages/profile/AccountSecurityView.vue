<script setup lang="ts">
/**
 * The account-security screen — `SEC-04` and `SEC-05`, for the person signed
 * in.
 *
 * ── Two sections, because they are two requirements ────────────────────────
 *
 * `SEC-04` is "mandatory email verification for password changes" and §9 Flow
 * 0 spells the order out: "password change → verification code by email → new
 * password → log in again". `SEC-05` is "8-hour session timeout + active
 * device list + force logout". They share a screen because they share a
 * question — *is this account still only mine?* — and nothing else.
 *
 * ── No permission, and that is the correct reading ─────────────────────────
 *
 * §3.11 has no row for either. The account is the caller's, read from the
 * bearer token on every endpoint behind this screen, and there is no `user_id`
 * anywhere in it. Somebody *else's* password and devices are §13 screen 2, an
 * administrative screen with its own permission, and this is not that screen.
 * The route therefore declares `requiresAuth` and no `requiredPermission`.
 *
 * ── The password change signs this device out, on purpose ──────────────────
 *
 * `ChangePassword` revokes **every** session including the calling one, so the
 * token in this tab is dead the moment the request succeeds. The screen says
 * so before it happens and redirects to the login page after — anything else
 * leaves a person clicking around an application whose every request will
 * 401.
 *
 * `SEC-09`/§3.12 rule 1: everything here is presentation. The checklist is a
 * hint (`passwordPolicy.ts` is pinned to the server's rule by
 * `PasswordPolicyMirrorTest`), and every refusal below is the server's.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { ApiError } from '@/api';
import EmailChallengeModal from '@/components/profile/EmailChallengeModal.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import { MINIMUM_LENGTH, checkPassword, isPasswordAcceptable } from '@/domain/passwordPolicy';
import type { DeviceSession } from '@/services/identity';
import {
    changePassword,
    listSessions,
    requestPasswordChallenge,
    revokeOtherSessions,
    revokeSession,
} from '@/services/identity';
import { useAuth } from '@/stores/auth';

const { t, locale } = useI18n();
const router = useRouter();
const auth = useAuth();

// ── the password section ───────────────────────────────────────────────────

const currentPassword = ref('');
const newPassword = ref('');
const confirmPassword = ref('');

const challengeOpen = ref(false);
const challengeExpiresAt = ref<number | null>(null);
const requestingChallenge = ref(false);
const resendingChallenge = ref(false);
const verifying = ref(false);
/** A lang-file key, or null. */
const passwordError = ref<string | null>(null);
const challengeError = ref<string | null>(null);

const checklist = computed(() => checkPassword(newPassword.value));

const passwordsMatch = computed(
    () => confirmPassword.value.length > 0 && newPassword.value === confirmPassword.value,
);

const canRequestChallenge = computed(
    () => currentPassword.value.length > 0
        && isPasswordAcceptable(newPassword.value)
        && passwordsMatch.value
        && !requestingChallenge.value,
);

/**
 * `SEC-04` step one. The passwords are **not** sent here — this endpoint takes
 * no body at all — so nothing is committed until the code comes back.
 */
async function startPasswordChange(): Promise<void> {
    if (!canRequestChallenge.value) {
        return;
    }

    requestingChallenge.value = true;
    passwordError.value = null;
    challengeError.value = null;

    try {
        const { expires_in_minutes: minutes } = await requestPasswordChallenge();

        // The server's own TTL, turned into a deadline once. Recomputing it on
        // every tick would let a slow render extend the countdown.
        challengeExpiresAt.value = Date.now() + minutes * 60_000;
        challengeOpen.value = true;
    } catch (error) {
        passwordError.value = challengeMessageFor(error);
    } finally {
        requestingChallenge.value = false;
    }
}

async function resendChallenge(): Promise<void> {
    resendingChallenge.value = true;
    challengeError.value = null;

    try {
        const { expires_in_minutes: minutes } = await requestPasswordChallenge();
        challengeExpiresAt.value = Date.now() + minutes * 60_000;
    } catch (error) {
        challengeError.value = challengeMessageFor(error);
    } finally {
        resendingChallenge.value = false;
    }
}

/**
 * `SEC-04` step two and §9 Flow 0's ending.
 *
 * The local session is dropped with {@see useAuth().forgetSession}, not with
 * `logout()`: the server has already revoked it, and posting a dead token to
 * `/auth/logout` reaches the same state through a `401`.
 */
async function submitChallenge(code: string): Promise<void> {
    verifying.value = true;
    challengeError.value = null;

    try {
        await changePassword({
            currentPassword: currentPassword.value,
            newPassword: newPassword.value,
            verificationCode: code,
        });

        challengeOpen.value = false;
        auth.forgetSession();

        await router.replace({ name: 'login', query: { changed: 'password' } });
    } catch (error) {
        challengeError.value = passwordMessageFor(error);
    } finally {
        verifying.value = false;
    }
}

function cancelChallenge(): void {
    challengeOpen.value = false;
    challengeError.value = null;
}

// ── the device section (`SEC-05`) ──────────────────────────────────────────

const sessions = ref<DeviceSession[]>([]);
const loading = ref(true);
const failed = ref(false);
const revoking = ref<string | null>(null);
const revokingOthers = ref(false);
const sessionError = ref<string | null>(null);
const sessionNotice = ref<string | null>(null);

const otherDeviceCount = computed(() => sessions.value.filter((session) => !session.is_current).length);

async function loadSessions(): Promise<void> {
    loading.value = true;
    failed.value = false;

    try {
        sessions.value = await listSessions();
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

async function revokeOne(session: DeviceSession): Promise<void> {
    // The API refuses this too (`422 session_is_current`); the control is not
    // rendered, and this is the second answer rather than the only one.
    if (session.is_current || revoking.value !== null) {
        return;
    }

    revoking.value = session.id;
    sessionError.value = null;
    sessionNotice.value = null;

    try {
        await revokeSession(session.id);
        // Re-read rather than splice: the list is the server's answer, and a
        // locally edited copy is a device list that stops being one the first
        // time two tabs revoke at once.
        await loadSessions();
        sessionNotice.value = 'account.devices.revoked';
    } catch (error) {
        sessionError.value = sessionMessageFor(error);
    } finally {
        revoking.value = null;
    }
}

async function revokeOthers(): Promise<void> {
    revokingOthers.value = true;
    sessionError.value = null;
    sessionNotice.value = null;

    try {
        await revokeOtherSessions();
        await loadSessions();
        sessionNotice.value = 'account.devices.revokedOthers';
    } catch (error) {
        sessionError.value = sessionMessageFor(error);
    } finally {
        revokingOthers.value = false;
    }
}

// ── §5.1's stable codes, mapped to this project's own wording ──────────────

function passwordMessageFor(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return 'account.error.unreachable';
    }

    if (error.is('current_password_incorrect')) {
        return 'account.error.currentPassword';
    }

    if (error.is('expired_verification_code')) {
        return 'account.error.codeExpired';
    }

    if (error.is('invalid_verification_code')) {
        return 'account.error.codeInvalid';
    }

    if (error.is('password_policy_not_met')) {
        return 'account.error.policy';
    }

    if (error.is('password_unchanged')) {
        return 'account.error.unchanged';
    }

    return 'account.error.rejected';
}

function challengeMessageFor(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return 'account.error.unreachable';
    }

    // `SEC-11`, three per fifteen minutes per account. The limit is the
    // server's and is not restated here — only the fact that it was reached.
    if (error.status === 429) {
        return 'account.error.resendLimited';
    }

    return 'account.error.challengeFailed';
}

function sessionMessageFor(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return 'account.error.unreachable';
    }

    if (error.is('session_is_current')) {
        return 'account.error.deviceIsCurrent';
    }

    if (error.is('session_not_found')) {
        return 'account.error.deviceGone';
    }

    return 'account.error.rejected';
}

// ── display ────────────────────────────────────────────────────────────────

/**
 * `DB-08`: the server sends UTC, and the browser converts for display. The
 * locale comes from vue-i18n so an Arabic session reads an Arabic date.
 */
function formatMoment(iso: string): string {
    const at = new Date(iso);

    if (Number.isNaN(at.getTime())) {
        return iso;
    }

    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(at);
}

/**
 * The raw `User-Agent`, shortened for the row.
 *
 * Not parsed into "Chrome on Windows" — that is a dependency and a lookup
 * table that goes stale, and §13 screen 2 asks for the browser without saying
 * who does the reading. The whole string stays in the `title` attribute.
 */
function shortAgent(userAgent: string | null): string {
    if (userAgent === null || userAgent.trim() === '') {
        return t('account.devices.unknownDevice');
    }

    return userAgent.length > 72 ? `${userAgent.slice(0, 72)}…` : userAgent;
}

onMounted(loadSessions);
</script>

<template>
    <section class="flex flex-col gap-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-page-title">{{ t('account.title') }}</h1>
            <p class="text-[var(--color-text-muted)] text-pretty">{{ t('account.subtitle') }}</p>
        </header>

        <!-- ── SEC-04 · §9 Flow 0 ─────────────────────────────────────────── -->
        <section class="panel flex flex-col gap-4 rounded-2xl p-5" data-testid="password-section">
            <div class="flex flex-col gap-1">
                <h2 class="text-section-title">{{ t('account.password.title') }}</h2>
                <p class="text-table text-[var(--color-text-muted)] text-pretty">
                    {{ t('account.password.explainer') }}
                </p>
            </div>

            <form class="flex flex-col gap-4" @submit.prevent="startPasswordChange">
                <label class="flex flex-col gap-1">
                    <span class="text-table text-[var(--color-text-muted)]">
                        {{ t('account.password.current') }}
                    </span>
                    <input
                        v-model="currentPassword"
                        class="field min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        type="password"
                        autocomplete="current-password"
                        data-testid="current-password"
                    >
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-table text-[var(--color-text-muted)]">
                        {{ t('account.password.next') }}
                    </span>
                    <input
                        v-model="newPassword"
                        class="field min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        type="password"
                        autocomplete="new-password"
                        data-testid="new-password"
                    >
                </label>

                <!-- `D-28`, as three separate answers. §6.4 and §9.5: each item
                     carries a word and a mark, so the state is legible without
                     relying on the colour. -->
                <ul class="flex flex-col gap-1 text-table" data-testid="password-checklist">
                    <li :class="checklist.length ? 'rule--met' : 'rule--unmet'">
                        <span aria-hidden="true">{{ checklist.length ? '✓' : '•' }}</span>
                        {{ t('account.password.ruleLength', { length: MINIMUM_LENGTH }) }}
                    </li>
                    <li :class="checklist.letter ? 'rule--met' : 'rule--unmet'">
                        <span aria-hidden="true">{{ checklist.letter ? '✓' : '•' }}</span>
                        {{ t('account.password.ruleLetter') }}
                    </li>
                    <li :class="checklist.digit ? 'rule--met' : 'rule--unmet'">
                        <span aria-hidden="true">{{ checklist.digit ? '✓' : '•' }}</span>
                        {{ t('account.password.ruleDigit') }}
                    </li>
                </ul>

                <label class="flex flex-col gap-1">
                    <span class="text-table text-[var(--color-text-muted)]">
                        {{ t('account.password.confirm') }}
                    </span>
                    <input
                        v-model="confirmPassword"
                        class="field min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        type="password"
                        autocomplete="new-password"
                        data-testid="confirm-password"
                    >
                    <span
                        v-if="confirmPassword.length > 0 && !passwordsMatch"
                        class="text-table rule--unmet"
                        data-testid="confirm-mismatch"
                    >
                        {{ t('account.password.mismatch') }}
                    </span>
                </label>

                <p
                    v-if="passwordError !== null"
                    class="alert rounded-lg p-3 text-table"
                    role="alert"
                    data-testid="password-error"
                >
                    {{ t(passwordError) }}
                </p>

                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="submit"
                        class="primary min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="!canRequestChallenge"
                        data-testid="change-password"
                    >
                        {{ requestingChallenge ? t('account.password.sending') : t('account.password.submit') }}
                    </button>

                    <!-- Said before it happens, not after. §9 Flow 0 ends with
                         "log in again", and this device is one of the sessions
                         the change takes down. -->
                    <span class="text-table text-[var(--color-text-muted)] text-pretty">
                        {{ t('account.password.signsOutEverywhere') }}
                    </span>
                </div>
            </form>
        </section>

        <!-- ── SEC-05 ─────────────────────────────────────────────────────── -->
        <section class="panel flex flex-col gap-4 rounded-2xl p-5" data-testid="devices-section">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <h2 class="text-section-title">{{ t('account.devices.title') }}</h2>
                    <p class="text-table text-[var(--color-text-muted)] text-pretty">
                        {{ t('account.devices.explainer') }}
                    </p>
                </div>

                <button
                    type="button"
                    class="danger min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="revokingOthers || otherDeviceCount === 0"
                    data-testid="revoke-others"
                    @click="revokeOthers"
                >
                    {{ revokingOthers ? t('action.saving') : t('account.devices.revokeOthers') }}
                </button>
            </div>

            <LoadingState v-if="loading" label-key="account.devices.loading" />
            <ErrorState v-else-if="failed" @retry="loadSessions" />

            <template v-else>
                <p
                    v-if="sessionError !== null"
                    class="alert rounded-lg p-3 text-table"
                    role="alert"
                    data-testid="devices-error"
                >
                    {{ t(sessionError) }}
                </p>

                <p
                    v-if="sessionNotice !== null"
                    class="notice rounded-lg p-3 text-table"
                    role="status"
                    aria-live="polite"
                    data-testid="devices-notice"
                >
                    {{ t(sessionNotice) }}
                </p>

                <ul class="flex flex-col gap-2" data-testid="devices-list">
                    <li
                        v-for="session in sessions"
                        :key="session.id"
                        class="device flex flex-wrap items-center gap-3 rounded-xl p-3"
                        data-testid="device-row"
                        :data-current="session.is_current"
                    >
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="min-w-0 truncate" :title="session.user_agent ?? ''">
                                    {{ shortAgent(session.user_agent) }}
                                </span>
                                <!-- §6.4: a badge with its own word, not a tint. -->
                                <span
                                    v-if="session.is_current"
                                    class="badge rounded-full px-2 py-0.5 text-table"
                                    data-testid="current-badge"
                                >
                                    {{ t('account.devices.current') }}
                                </span>
                            </span>

                            <span class="text-table tabular-nums text-[var(--color-text-muted)]">
                                {{ t('account.devices.meta', {
                                    ip: session.ip_address ?? t('account.devices.unknownIp'),
                                    active: formatMoment(session.last_activity_at),
                                    since: formatMoment(session.signed_in_at),
                                }) }}
                            </span>
                        </div>

                        <!-- No control on the current row: the API refuses it
                             (`422 session_is_current`) and points at sign-out,
                             so offering it would be offering a refusal. -->
                        <button
                            v-if="!session.is_current"
                            type="button"
                            class="ghost-danger min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="revoking !== null"
                            :data-testid="`revoke-${session.id}`"
                            @click="revokeOne(session)"
                        >
                            {{ revoking === session.id ? t('action.saving') : t('account.devices.revoke') }}
                        </button>
                    </li>
                </ul>
            </template>
        </section>

        <EmailChallengeModal
            :open="challengeOpen"
            :email="auth.user.value?.email ?? ''"
            :expires-at="challengeExpiresAt"
            :busy="verifying"
            :resending="resendingChallenge"
            :error-key="challengeError"
            @submit="submitChallenge"
            @resend="resendChallenge"
            @cancel="cancelChallenge"
        />
    </section>
</template>

<style scoped>
.panel {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-1);
}

.field {
    background-color: var(--color-surface-raised);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.device {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border);
}

.device[data-current='true'] {
    /* The inline axis, so the marker sits at the start in both directions. */
    border-inline-start: 3px solid var(--color-primary);
}

.badge {
    background-color: var(--color-surface-raised);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text-muted);
}

.rule--met {
    color: var(--color-success);
}

.rule--unmet {
    color: var(--color-text-muted);
}

.alert {
    background-color: color-mix(in srgb, var(--color-danger) 12%, transparent);
    color: var(--color-danger);
}

.notice {
    background-color: color-mix(in srgb, var(--color-success) 12%, transparent);
    color: var(--color-success);
}

.primary {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

.danger {
    background-color: var(--color-danger);
    color: var(--color-primary-text);
}

.ghost-danger {
    background-color: var(--color-surface);
    border: 1px solid var(--color-danger);
    color: var(--color-danger);
}
</style>
