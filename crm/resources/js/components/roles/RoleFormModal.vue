<script setup lang="ts">
/**
 * §13 screen 3's "create new roles" — the form in front of `POST /roles`.
 *
 * ── It collects; it does not decide ────────────────────────────────────────
 *
 * No request is made here and no rule is evaluated. The parent owns the call,
 * and the server owns every refusal: the slug that is taken, the label that
 * collides, §3.12 rule 3's forbidden grant. What this does check is *shape* —
 * a slug that cannot match `CreateRoleRequest::SLUG_PATTERN` is a round trip
 * that can only end in a 422, and §6.6 asks a form to say so before submission
 * rather than after.
 *
 * ⚠️ The pattern below is duplicated from the server on purpose, and
 * `RoleSlugMirrorTest` reads both files and asserts they are the same string.
 * The precedent is `PasswordPolicyMirrorTest` (Point 5.4): where a documented
 * rule genuinely has to exist client-side, the duplicate is pinned by a test
 * rather than trusted.
 *
 * ── Both labels, and why only one is required ──────────────────────────────
 *
 * `CLAUDE.md` requires every screen to work in Arabic and English, and a role's
 * name is **data** — §3.12 rule 5 makes a ninth role a runtime configuration
 * change, so there is no lang file its label could ever live in. The English
 * label is required because something has to render; the Arabic one is optional
 * because `RoleView::label()` falls back, exactly as it does for §3.1's eight.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { SLUG_PATTERN, isSlugShaped } from '@/domain/roleSlug';

const props = defineProps<{ open: boolean; busy: boolean; errorKey: string | null }>();

const emit = defineEmits<{
    submit: [{ slug: string; name: string; name_ar: string | null; description: string | null }];
    cancel: [];
}>();

const { t } = useI18n();

const slug = ref('');
const name = ref('');
const nameAr = ref('');
const description = ref('');
const touched = ref(false);
const slugField = ref<HTMLInputElement | null>(null);

const slugValid = computed(() => isSlugShaped(slug.value.trim()));
const nameValid = computed(() => name.value.trim().length >= 2);
const submittable = computed(() => slugValid.value && nameValid.value && !props.busy);

function reset(): void {
    slug.value = '';
    name.value = '';
    nameAr.value = '';
    description.value = '';
    touched.value = false;
}

function submit(): void {
    touched.value = true;

    if (!submittable.value) {
        return;
    }

    emit('submit', {
        slug: slug.value.trim(),
        name: name.value.trim(),
        // An empty box is *absent*, not an empty label: a stored `""` would
        // make the Arabic fallback treat the name as present and render a role
        // with no visible name at all.
        name_ar: nameAr.value.trim() === '' ? null : nameAr.value.trim(),
        description: description.value.trim() === '' ? null : description.value.trim(),
    });
}

// §6.1: "Escape closes dialogs/menus without discarding silently." Closing here
// discards a form nothing was done with yet, which is the documented behaviour
// for a create dialog — nothing has been sent.
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.open && !props.busy) {
        emit('cancel');
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

watch(
    () => props.open,
    async (open) => {
        if (open) {
            reset();
            await nextTick();
            slugField.value?.focus();
        }
    },
);
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        data-testid="role-form-modal"
    >
        <div class="dialog-scrim absolute inset-0" @click="busy ? null : emit('cancel')" />

        <form
            class="dialog-panel relative flex max-h-full w-full max-w-lg flex-col gap-4 overflow-y-auto rounded-2xl p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="role-form-title"
            @submit.prevent="submit"
        >
            <h2 id="role-form-title" class="text-card-title">{{ t('roles.create.title') }}</h2>

            <p class="text-[var(--color-text-muted)] text-pretty">{{ t('roles.create.explainer') }}</p>

            <label class="flex flex-col gap-1">
                <span>{{ t('roles.create.slug') }}</span>
                <input
                    ref="slugField"
                    v-model="slug"
                    type="text"
                    autocomplete="off"
                    class="field rounded-lg px-3 py-2"
                    :pattern="SLUG_PATTERN.source"
                    :aria-invalid="touched && !slugValid"
                    data-testid="role-form-slug"
                />
                <span class="hint">{{ t('roles.create.slugHint') }}</span>
                <span
                    v-if="touched && !slugValid"
                    class="hint hint--danger"
                    role="alert"
                    data-testid="role-form-slug-error"
                >
                    {{ t('roles.create.slugInvalid') }}
                </span>
            </label>

            <label class="flex flex-col gap-1">
                <span>{{ t('roles.create.name') }}</span>
                <input
                    v-model="name"
                    type="text"
                    class="field rounded-lg px-3 py-2"
                    :aria-invalid="touched && !nameValid"
                    data-testid="role-form-name"
                />
                <span
                    v-if="touched && !nameValid"
                    class="hint hint--danger"
                    role="alert"
                    data-testid="role-form-name-error"
                >
                    {{ t('roles.create.nameInvalid') }}
                </span>
            </label>

            <label class="flex flex-col gap-1">
                <span>{{ t('roles.create.nameAr') }}</span>
                <input v-model="nameAr" type="text" lang="ar" dir="rtl" class="field rounded-lg px-3 py-2" data-testid="role-form-name-ar" />
                <span class="hint">{{ t('roles.create.nameArHint') }}</span>
            </label>

            <label class="flex flex-col gap-1">
                <span>{{ t('roles.create.description') }}</span>
                <input v-model="description" type="text" class="field rounded-lg px-3 py-2" data-testid="role-form-description" />
            </label>

            <p
                v-if="errorKey !== null"
                class="notice notice--danger rounded-lg p-3 text-pretty"
                role="alert"
                data-testid="role-form-error"
            >
                {{ t(errorKey) }}
            </p>

            <p class="text-[var(--color-text-muted)] text-pretty">{{ t('roles.create.thenGrant') }}</p>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="dialog-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="busy"
                    data-testid="role-form-cancel"
                    @click="emit('cancel')"
                >
                    {{ t('action.cancel') }}
                </button>

                <button
                    type="submit"
                    class="dialog-confirm min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy"
                    data-testid="role-form-submit"
                >
                    {{ busy ? t('action.saving') : t('roles.create.submit') }}
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

.field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.field:focus-visible {
    outline: 2px solid var(--color-focus-ring);
    outline-offset: 2px;
}

.hint {
    color: var(--color-text-muted);
    font-size: 0.875rem;
}

.hint--danger {
    color: var(--color-danger);
}

.notice {
    background-color: var(--color-surface-muted);
    color: var(--color-text);
}

.notice--danger {
    border: 1px solid var(--color-danger);
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
