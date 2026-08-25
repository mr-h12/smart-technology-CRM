<script setup lang="ts">
/**
 * §9 Flow 0's login screen — the only page in the system reachable signed out.
 *
 * ── What Flow 0 and §10.1 require of it ────────────────────────────────────
 *
 * "No sign-up. Accounts are created by the Manager or Super Admin only", so
 * there is no register link, no password-reset link and no "remember me": a
 * reset is §9 Flow 0's emailed code on an authenticated `change-password`
 * (Point 3.3), not a public form. Wrong credentials, a locked account and a
 * deactivated one are three different messages, because §10.1 fixes the
 * suspended wording — "Account suspended, please contact administration" — and
 * `SEC-03`'s lock is a state the person cannot fix by retyping.
 *
 * ── The refusal text comes from the store's key, not from the response ─────
 *
 * The API already localises `error.message`. Rendering the project's own
 * strings instead keeps §10.1's sentence exact in both languages and keeps this
 * screen readable when the failure was a dropped connection, where there is no
 * server message at all.
 *
 * ── Direction and typography ───────────────────────────────────────────────
 *
 * No physical inline properties anywhere (`LogicalPropertiesTest`); the form is
 * one DOM order that follows `dir`, which follows the locale. `D-70`: the
 * `--font-sans` stack puts Inter first, so Western digits in the email field
 * are drawn by Inter in both locales without this screen saying anything about
 * fonts.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '@/stores/auth';
import { landingRouteFor, safeRedirect } from '@/router';

const { t } = useI18n();
const router = useRouter();
const route = useRoute();
const auth = useAuth();

const email = ref('');
const password = ref('');

/** Set only after a submit, so the fields are not red before anyone typed. */
const submitted = ref(false);

const emailMissing = computed(() => submitted.value && email.value.trim() === '');
const passwordMissing = computed(() => submitted.value && password.value === '');

/**
 * `D-28` is checked by the server and is not re-implemented here.
 *
 * The SPA "never owns a calculation, a permission decision, or a state
 * transition" (`D-67`), and a password policy is a rule with an authoritative
 * home in `PasswordPolicy`. What this form checks is that the two boxes are not
 * empty, which is about the form and not about the credential.
 */
const canSubmit = computed(() => !auth.pending.value && email.value.trim() !== '' && password.value !== '');

async function submit(): Promise<void> {
    submitted.value = true;

    if (!canSubmit.value) {
        return;
    }

    const signedIn = await auth.login(email.value.trim(), password.value);

    if (!signedIn) {
        // Never left in memory after a refusal, and never prefilled back into
        // the field on a retry.
        password.value = '';

        return;
    }

    const requested = safeRedirect(route.query.redirect);

    await router.replace(
        requested === null
            ? { name: landingRouteFor(auth.role.value, (name) => router.hasRoute(name)) }
            : requested,
    );
}
</script>

<template>
    <div class="flex min-h-dvh items-center justify-center bg-[var(--color-canvas)] p-4 text-[var(--color-text)]">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex flex-col items-center gap-2 text-center">
                <p class="text-page-title">{{ t('app.name') }}</p>
                <p class="text-[var(--color-text-muted)]">{{ t('auth.login.subtitle') }}</p>
            </div>

            <form
                class="login-card flex flex-col gap-4 rounded-2xl p-6"
                novalidate
                data-testid="login-form"
                @submit.prevent="submit"
            >
                <h1 class="text-section-title">{{ t('auth.login.title') }}</h1>

                <!-- §8's error state, announced rather than only coloured (§9.5).
                     assertive because it is the result of something the person
                     just did, and a polite region would be read after it. -->
                <p
                    v-if="auth.errorKey.value !== null"
                    class="login-alert rounded-lg p-3"
                    role="alert"
                    aria-live="assertive"
                    data-testid="login-error"
                >
                    {{ t(auth.errorKey.value) }}
                </p>

                <label class="flex flex-col gap-1.5">
                    <span>{{ t('auth.login.email') }}</span>
                    <input
                        v-model="email"
                        type="email"
                        name="email"
                        autocomplete="username"
                        inputmode="email"
                        required
                        :disabled="auth.pending.value"
                        :aria-invalid="emailMissing"
                        :aria-describedby="emailMissing ? 'login-email-error' : undefined"
                        class="login-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="login-email"
                        @input="auth.clearError()"
                    />
                    <span v-if="emailMissing" id="login-email-error" class="text-[var(--color-danger)]">
                        {{ t('auth.login.emailRequired') }}
                    </span>
                </label>

                <label class="flex flex-col gap-1.5">
                    <span>{{ t('auth.login.password') }}</span>
                    <input
                        v-model="password"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                        :disabled="auth.pending.value"
                        :aria-invalid="passwordMissing"
                        :aria-describedby="passwordMissing ? 'login-password-error' : undefined"
                        class="login-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="login-password"
                        @input="auth.clearError()"
                    />
                    <span v-if="passwordMissing" id="login-password-error" class="text-[var(--color-danger)]">
                        {{ t('auth.login.passwordRequired') }}
                    </span>
                </label>

                <button
                    type="submit"
                    :disabled="!canSubmit"
                    class="login-submit flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    data-testid="login-submit"
                >
                    <!-- §6.1: "Loading controls retain their width and show a
                         text alternative." The label stays; the spinner is added
                         beside it rather than replacing it, so the button does
                         not resize and a screen reader still reads an action. -->
                    <svg
                        v-if="auth.pending.value"
                        class="size-4 shrink-0 animate-spin motion-reduce:animate-none"
                        viewBox="0 0 20 20"
                        aria-hidden="true"
                        fill="none"
                    >
                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-opacity="0.3" stroke-width="2.5" />
                        <path d="M18 10a8 8 0 0 0-8-8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                    <span>{{ auth.pending.value ? t('auth.login.submitting') : t('auth.login.submit') }}</span>
                </button>

                <!-- SEC-01. Stated on the screen rather than left as an absence,
                     so somebody looking for a sign-up link stops looking. -->
                <p class="text-[var(--color-text-muted)] text-pretty">{{ t('auth.login.noSignUp') }}</p>
            </form>
        </div>
    </div>
</template>

<style scoped>
.login-card {
    background-color: var(--color-surface-raised);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-2);
}

.login-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.login-field:disabled {
    background-color: var(--color-surface-muted);
}

.login-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-danger);
    color: var(--color-danger);
}

.login-submit {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

.login-submit:hover:not(:disabled) {
    background-color: var(--color-primary-hover);
}

.login-submit:active:not(:disabled) {
    background-color: var(--color-primary-active);
}
</style>
