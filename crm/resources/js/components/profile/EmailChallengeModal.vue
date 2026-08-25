<script setup lang="ts">
/**
 * `SEC-04` step two — §9 Flow 0's "verification code by email".
 *
 * ── Why the code is a separate step and not a fourth field ─────────────────
 *
 * §9 Flow 0 reads "password change → **verification code by email** → new
 * password → log in again", and `ChangePassword` enforces that order: the
 * current password is checked *first*, so a caller guessing passwords cannot
 * burn the account's challenge attempts. A single form with all four fields
 * would ask for a code before the person has one, and the only way to get one
 * would be to submit the form and fail. So the form collects the passwords,
 * the challenge is requested, and this dialog collects what arrives.
 *
 * ── The countdown is the server's number ───────────────────────────────────
 *
 * `expiresAt` is computed by the parent from the `202`'s `expires_in_minutes`,
 * which is `identity.password_challenge.ttl_minutes`. Nothing here knows it is
 * fifteen — `AP-08` and §3.12 rule 5 make limits configuration, and a hard-coded
 * fifteen would keep counting down after an administrator changed it.
 *
 * ── It collects; it does not decide ────────────────────────────────────────
 *
 * No request is made here. The parent owns the call and the server owns every
 * refusal — a wrong code, an expired challenge and an exhausted one all answer
 * with the same `422`, deliberately (`PasswordRefusal`), and this dialog shows
 * whichever sentence the parent maps it to.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { VERIFICATION_CODE_LENGTH, isVerificationCodeShaped } from '@/domain/passwordPolicy';

const props = defineProps<{
    open: boolean;
    /** Where the code went, so the person knows which inbox to open. */
    email: string;
    /** Epoch milliseconds, from the challenge response. Null before one is issued. */
    expiresAt: number | null;
    /** The verify request is in flight. */
    busy: boolean;
    /** A second challenge is being requested. */
    resending: boolean;
    /** A lang-file key, or null. §6.6: explained in place, not only in a toast. */
    errorKey: string | null;
}>();

const emit = defineEmits<{ submit: [code: string]; resend: []; cancel: [] }>();

const { t } = useI18n();

const code = ref('');
const codeInput = ref<HTMLInputElement | null>(null);
const now = ref(Date.now());

let ticker: ReturnType<typeof setInterval> | null = null;

function stopTicking(): void {
    if (ticker !== null) {
        clearInterval(ticker);
        ticker = null;
    }
}

/**
 * One second is the coarsest tick that still shows a moving countdown, and the
 * interval is cleared whenever the dialog closes — a timer that outlives its
 * component keeps a closed dialog's clock running for the life of the tab.
 */
function startTicking(): void {
    stopTicking();
    now.value = Date.now();
    ticker = setInterval(() => {
        now.value = Date.now();
    }, 1000);
}

const remainingSeconds = computed<number | null>(() => {
    if (props.expiresAt === null) {
        return null;
    }

    return Math.max(0, Math.floor((props.expiresAt - now.value) / 1000));
});

const hasExpired = computed(() => remainingSeconds.value !== null && remainingSeconds.value === 0);

/**
 * `mm:ss`, padded, so the width does not jump every ten seconds.
 *
 * §8.1 asks for tabular numerals on anything that counts; the element carries
 * `tabular-nums`, which is what keeps the two digits from dancing.
 */
const countdown = computed<string>(() => {
    const seconds = remainingSeconds.value;

    if (seconds === null) {
        return '';
    }

    const minutes = Math.floor(seconds / 60);

    return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
});

const canSubmit = computed(() => !props.busy && !hasExpired.value && isVerificationCodeShaped(code.value));

// §6.1: "Escape closes dialogs/menus without discarding silently." Closing this
// abandons the challenge, not the typed passwords — the form behind it keeps
// what was entered, and the emailed code stays valid until it expires.
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.open && !props.busy) {
        emit('cancel');
    }
}

function onSubmit(): void {
    if (canSubmit.value) {
        emit('submit', code.value);
    }
}

/** Digits only, and never more than the code is long. */
function onInput(event: Event): void {
    const target = event.target;

    if (target instanceof HTMLInputElement) {
        code.value = target.value.replace(/\D/gu, '').slice(0, VERIFICATION_CODE_LENGTH);
        target.value = code.value;
    }
}

watch(() => props.open, async (open) => {
    if (open) {
        code.value = '';
        startTicking();
        document.addEventListener('keydown', onKeydown);
        await new Promise((resolve) => setTimeout(resolve, 0));
        codeInput.value?.focus();
    } else {
        stopTicking();
        document.removeEventListener('keydown', onKeydown);
    }
}, { immediate: true });

// A fresh challenge restarts the clock; the parent moves `expiresAt` forward.
watch(() => props.expiresAt, () => {
    if (props.open) {
        startTicking();
    }
});

onBeforeUnmount(() => {
    stopTicking();
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        data-testid="email-challenge-modal"
    >
        <div class="dialog-scrim absolute inset-0" @click="busy ? null : emit('cancel')" />

        <form
            class="dialog-panel relative flex max-h-full w-full max-w-md flex-col gap-4 overflow-y-auto rounded-2xl p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="email-challenge-title"
            aria-describedby="email-challenge-intro"
            @submit.prevent="onSubmit"
        >
            <h2 id="email-challenge-title" class="text-card-title">
                {{ t('account.challenge.title') }}
            </h2>

            <p id="email-challenge-intro" class="text-[var(--color-text-muted)] text-pretty">
                {{ t('account.challenge.intro', { email }) }}
            </p>

            <label class="flex flex-col gap-1">
                <span class="text-table text-[var(--color-text-muted)]">
                    {{ t('account.challenge.label', { length: VERIFICATION_CODE_LENGTH }) }}
                </span>

                <input
                    ref="codeInput"
                    class="challenge-input min-h-11 rounded-lg px-3 text-center tabular-nums focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    :maxlength="VERIFICATION_CODE_LENGTH"
                    :value="code"
                    :disabled="busy"
                    data-testid="challenge-code"
                    @input="onInput"
                >
            </label>

            <!-- §6.4/§9.5: the state is a word and a number, not a colour. -->
            <p
                v-if="remainingSeconds !== null"
                class="text-table tabular-nums"
                :class="hasExpired ? 'challenge-expired' : 'text-[var(--color-text-muted)]'"
                role="status"
                aria-live="polite"
                data-testid="challenge-countdown"
            >
                {{ hasExpired ? t('account.challenge.expired') : t('account.challenge.remaining', { time: countdown }) }}
            </p>

            <p
                v-if="errorKey !== null"
                class="challenge-error rounded-lg p-3 text-table"
                role="alert"
                data-testid="challenge-error"
            >
                {{ t(errorKey) }}
            </p>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <!-- `SEC-11` limits this to three per fifteen minutes, per
                     account. The refusal arrives as a 429 and the parent turns
                     it into `account.error.resendLimited`, which is why this
                     control stays enabled rather than trying to count locally:
                     a client-side counter and a server-side limiter that
                     disagree is a button that lies in both directions. -->
                <button
                    type="button"
                    class="dialog-cancel me-auto min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy || resending"
                    data-testid="challenge-resend"
                    @click="emit('resend')"
                >
                    {{ resending ? t('account.challenge.resending') : t('account.challenge.resend') }}
                </button>

                <button
                    type="button"
                    class="dialog-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="busy"
                    data-testid="challenge-cancel"
                    @click="emit('cancel')"
                >
                    {{ t('action.cancel') }}
                </button>

                <button
                    type="submit"
                    class="dialog-confirm min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!canSubmit"
                    data-testid="challenge-submit"
                >
                    {{ busy ? t('action.saving') : t('account.challenge.confirm') }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.dialog-scrim {
    background-color: color-mix(in srgb, var(--color-text) 45%, transparent);
}

.dialog-panel {
    background-color: var(--color-surface-raised);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-3, var(--shadow-2));
}

.challenge-input {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
    /* A code is read back digit by digit; the spacing is what makes six of them
       countable at a glance rather than a number. */
    letter-spacing: 0.4em;
    font-variant-numeric: tabular-nums;
}

.challenge-expired {
    color: var(--color-danger);
}

.challenge-error {
    background-color: color-mix(in srgb, var(--color-danger) 12%, transparent);
    color: var(--color-danger);
}

.dialog-cancel {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.dialog-confirm {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
