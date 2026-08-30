<script setup lang="ts">
/**
 * §9 Flow 9's employee form — create and edit, one component.
 *
 * ── What the client validates, and what it must not ────────────────────────
 *
 * `D-28` — "at least 8 characters, letters and numbers" — is checked here as a
 * courtesy so the person is not told after a round trip. It is **not** the
 * rule: `PasswordPolicy` is, and `CreateUser` refuses regardless (`D-67`: the
 * SPA "never owns a calculation, a permission decision, or a state
 * transition"). A `422` from the server is rendered field by field, so the two
 * disagreeing shows up as a server message rather than as a silently accepted
 * password.
 *
 * ── The role list is filtered, and that is presentation ────────────────────
 *
 * §3.11's create-user cell and §3.12 rule 7 decide which roles the actor may
 * assign; {@see assignableBy} mirrors that so the dropdown does not offer a
 * choice the API will refuse. `RoleAssignmentPolicy` is the authority, and
 * `RoleAssignmentMirrorTest` pins the two lists equal.
 *
 * ── Password is create-only ────────────────────────────────────────────────
 *
 * `UpdateUserRequest` has no `password` field, on purpose: §9 Flow 0 gives the
 * owner of an account their own endpoint, behind `SEC-04`'s emailed code. An
 * administrator silently resetting somebody's password is a different
 * documented action with a different audit event, and it does not exist yet.
 */
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import { assignableBy } from '@/domain/roleAssignment';
import type { AdministeredUser, RoleOption } from '@/services/identity';
import { createUser, updateUser } from '@/services/identity';

const props = defineProps<{
    open: boolean;
    /** Null for a create. */
    editing: AdministeredUser | null;
    roles: readonly RoleOption[];
    /** The signed-in person's role slug, which decides the dropdown. */
    actorRole: string | null;
}>();

const emit = defineEmits<{ saved: [AdministeredUser]; cancel: [] }>();

const { t } = useI18n();

const name = ref('');
const email = ref('');
const password = ref('');
const roleId = ref('');
const saving = ref(false);

/** Lang-file keys, by field. `_form` holds anything not tied to one. */
const errors = ref<Record<string, string>>({});

/**
 * The server's own sentences, by field — already localised for the request's
 * `Accept-Language`.
 *
 * Kept apart from {@link errors} rather than merged into it, so a client-side
 * message stays a *key* and keeps re-translating when the person switches
 * AR/EN. A server sentence cannot do that — it was localised once, when the
 * request was answered — and collapsing both into one map would quietly cost
 * the client-side half that behaviour.
 */
const serverErrors = ref<Record<string, string>>({});

/** The fields a server refusal can name, which are the inputs this form has. */
const FIELDS = ['name', 'email', 'role_id', 'password'] as const;

function hasError(field: string): boolean {
    return errors.value[field] !== undefined || serverErrors.value[field] !== undefined;
}

/** The server's sentence when it sent one for this field, otherwise ours. */
function errorText(field: string): string {
    const sentence = serverErrors.value[field];

    if (sentence !== undefined) {
        return sentence;
    }

    const key = errors.value[field];

    return key === undefined ? '' : t(key);
}

const isEdit = computed(() => props.editing !== null);

const options = computed<RoleOption[]>(() => {
    const allowed = assignableBy(props.actorRole, props.roles.map((role) => role.slug));

    return props.roles.filter((role) => allowed.includes(role.slug));
});

/** `D-28`, mirrored — eight or more, with at least one letter and one digit. */
function meetsPasswordPolicy(value: string): boolean {
    return value.length >= 8 && /[a-z]/i.test(value) && /[0-9]/.test(value);
}

watch(() => [props.open, props.editing] as const, ([open]) => {
    if (!open) {
        return;
    }

    errors.value = {};
    serverErrors.value = {};
    password.value = '';
    name.value = props.editing?.name ?? '';
    email.value = props.editing?.email ?? '';
    roleId.value = props.editing?.role_id ?? '';
}, { immediate: true });

function validate(): boolean {
    const found: Record<string, string> = {};

    if (name.value.trim() === '') {
        found.name = 'users.form.nameRequired';
    }

    if (email.value.trim() === '') {
        found.email = 'users.form.emailRequired';
    }

    if (roleId.value === '') {
        found.role_id = 'users.form.roleRequired';
    }

    if (!isEdit.value && !meetsPasswordPolicy(password.value)) {
        found.password = 'users.form.passwordPolicy';
    }

    errors.value = found;
    serverErrors.value = {};

    return Object.keys(found).length === 0;
}

/**
 * `OpenAPI §5` — `details[]` carries `field` and a stable `code`, so a server
 * refusal lands on the input that caused it rather than in a banner that says
 * "something was wrong".
 */
function applyServerErrors(error: unknown): void {
    if (!(error instanceof ApiError)) {
        errors.value = { _form: 'users.form.unreachable' };
        serverErrors.value = {};

        return;
    }

    const found: Record<string, string> = {};
    const sentences: Record<string, string> = {};

    // A **domain** refusal — `AdministrationRefusal` — carries a stable code
    // and names no field, so the field it belongs to is decided here.
    for (const detail of error.detailCodes) {
        if (detail === 'email_already_taken') {
            found.email = 'users.form.emailTaken';
        } else if (detail === 'role_not_assignable') {
            found.role_id = 'users.form.roleNotAssignable';
        } else if (detail === 'password_policy_not_met') {
            found.password = 'users.form.passwordPolicy';
        } else if (detail === 'role_not_found') {
            found.role_id = 'users.form.roleRequired';
        }
    }

    // A **validation** failure is the other half, and the half this form used
    // to drop on the floor. `ApiExceptionRenderer::validation()` writes every
    // Form Request error as `code: 'invalid'` with the field named and the
    // server's own sentence attached — and a Form Request is validated *before*
    // the controller runs, so a duplicate address never reaches the use case
    // and never produces `email_already_taken`. Matching on the four codes
    // above alone therefore left every validation error in a banner that said
    // "something was wrong" while the server had already said exactly what.
    //
    // The sentence is used rather than re-written here: a rule copied into a
    // screen is a second copy of that rule, and the copy that is wrong is
    // always the one in the screen.
    for (const field of FIELDS) {
        // A recognised domain code wins. Its sentence above was written for
        // that exact rule, while this one is whatever the validator produced —
        // and when the server sends both, the specific one is the better
        // sentence. So this fills the gap rather than taking the field over,
        // which is also what keeps `UsersView`'s §5.1 test true.
        if (found[field] !== undefined) {
            continue;
        }

        const message = error.messageFor(field);

        if (message !== null) {
            sentences[field] = message;
        }
    }

    if (Object.keys(found).length === 0 && Object.keys(sentences).length === 0) {
        found._form = error.status === 403 ? 'users.form.forbidden' : 'users.form.rejected';
    }

    errors.value = found;
    serverErrors.value = sentences;
}

async function save(): Promise<void> {
    if (!validate()) {
        return;
    }

    saving.value = true;

    try {
        const saved = props.editing === null
            ? await createUser({
                name: name.value.trim(),
                email: email.value.trim(),
                password: password.value,
                role_id: roleId.value,
            })
            : await updateUser(props.editing.id, {
                name: name.value.trim(),
                email: email.value.trim(),
                role_id: roleId.value,
            });

        password.value = '';
        emit('saved', saved);
    } catch (error) {
        applyServerErrors(error);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="user-form-modal">
        <div class="modal-scrim absolute inset-0" @click="emit('cancel')" />

        <form
            class="modal-panel relative flex w-full max-w-md flex-col gap-4 rounded-2xl p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="user-form-title"
            novalidate
            @submit.prevent="save"
        >
            <h2 id="user-form-title" class="text-card-title">
                {{ isEdit ? t('users.form.editTitle') : t('users.form.createTitle') }}
            </h2>

            <p
                v-if="errors._form !== undefined"
                class="form-alert rounded-lg p-3"
                role="alert"
                data-testid="user-form-error"
            >
                {{ t(errors._form) }}
            </p>

            <label class="flex flex-col gap-1.5">
                <span>{{ t('users.form.name') }}</span>
                <input
                    v-model="name"
                    type="text"
                    autocomplete="off"
                    :disabled="saving"
                    :aria-invalid="hasError('name')"
                    class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="user-form-name"
                />
                <span v-if="hasError('name')" class="text-[var(--color-danger)]">{{ errorText('name') }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span>{{ t('users.form.email') }}</span>
                <input
                    v-model="email"
                    type="email"
                    inputmode="email"
                    autocomplete="off"
                    :disabled="saving"
                    :aria-invalid="hasError('email')"
                    class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="user-form-email"
                />
                <span v-if="hasError('email')" class="text-[var(--color-danger)]">{{ errorText('email') }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span>{{ t('users.form.role') }}</span>
                <select
                    v-model="roleId"
                    :disabled="saving || options.length === 0"
                    :aria-invalid="hasError('role_id')"
                    class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="user-form-role"
                >
                    <option value="" disabled>{{ t('users.form.rolePlaceholder') }}</option>
                    <option v-for="role in options" :key="role.id" :value="role.id">{{ role.label }}</option>
                </select>
                <span v-if="options.length === 0" class="text-[var(--color-text-muted)] text-pretty">
                    {{ t('users.form.noAssignableRoles') }}
                </span>
                <span v-if="hasError('role_id')" class="text-[var(--color-danger)]">{{ errorText('role_id') }}</span>
            </label>

            <label v-if="!isEdit" class="flex flex-col gap-1.5">
                <span>{{ t('users.form.password') }}</span>
                <input
                    v-model="password"
                    type="password"
                    autocomplete="new-password"
                    :disabled="saving"
                    :aria-invalid="hasError('password')"
                    class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="user-form-password"
                />
                <span class="text-[var(--color-text-muted)]">{{ t('users.form.passwordHint') }}</span>
                <span v-if="hasError('password')" class="text-[var(--color-danger)]">{{ errorText('password') }}</span>
            </label>

            <p v-else class="text-[var(--color-text-muted)] text-pretty">{{ t('users.form.passwordNotHere') }}</p>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="modal-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="saving"
                    data-testid="user-form-cancel"
                    @click="emit('cancel')"
                >
                    {{ t('action.cancel') }}
                </button>

                <button
                    type="submit"
                    class="modal-save min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="saving"
                    data-testid="user-form-save"
                >
                    {{ saving ? t('action.saving') : t('action.save') }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.modal-scrim {
    background-color: color-mix(in srgb, var(--color-text) 45%, transparent);
}

.modal-panel {
    background-color: var(--color-surface-raised);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-3, var(--shadow-2));
}

.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-danger);
    color: var(--color-danger);
}

.modal-cancel {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.modal-save {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
