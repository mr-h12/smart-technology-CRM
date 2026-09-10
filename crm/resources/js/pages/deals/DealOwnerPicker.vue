<script setup lang="ts">
/**
 * Choosing a deal's owner — the one control, used by both the create dialog
 * (Point 6.3/6.7b) and the assign panel (Point 6.7/6.7a).
 *
 * ── Why this exists as a component at all ──────────────────────────────────
 *
 * It did not, and that was the defect. Point 6.7a replaced the identifier box
 * on the assign panel; Point 6.7b then had to make the *same* change again on
 * the create form, because the loader, the two refs, the `<select>`/`<input>`
 * pair and the "list unavailable" sentence had simply been written twice. A
 * `/code-review` standards pass named it: Duplicated Code, and a small case of
 * Shotgun Surgery — "the fix had to be applied twice, in two separate commits,
 * because the logic wasn't factored into one component". The third caller
 * would have paid the same price, so the two were collapsed into this.
 *
 * ── Best-effort, and the two failures are different facts ──────────────────
 *
 * `GET /users` carries `admin.create_user` (§3.11: Super Admin and Manager)
 * while `deal.assign_owner` (§3.4) reaches the Manager and the Team Leader — so
 * a caller may legitimately assign a deal and not list employees. The list is
 * therefore fetched best-effort, exactly as `DealsView` fetches customer names:
 * a picker when it reads, the identifier box when it does not.
 *
 * ⚠️ **`unavailable` is not the same as "empty"**, and it is a separate flag
 * because a probe proved the `catch` untestable without one: rethrowing instead
 * of falling back left the list empty exactly as the catch did, so an assertion
 * on emptiness proved neither. Only this flag tells a refusal from an empty
 * company.
 *
 * ── What this component does NOT decide ────────────────────────────────────
 *
 * **Whether it is drawn at all.** The create form draws it only for
 * `deal.assign_owner` — without that permission `SaveDeal::ownedWithinScope`
 * makes the creator the owner regardless, so the control would be a choice that
 * is not one. The panel draws it only inside its own `assign_owner` section.
 * That gate belongs to the caller, which knows why it is asking.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { listUsers, type AdministeredUser } from '@/services/identity';

const props = defineProps<{
    /** Bound value: an employee id, or "" for "not chosen". */
    modelValue: string;
    disabled?: boolean;
    /** `data-testid` of the control itself, so each caller keeps its own name. */
    testId: string;
    /** `id`/`for` pairing, so each caller keeps its own label association. */
    fieldId: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [string] }>();

const { t } = useI18n();

const employees = ref<AdministeredUser[]>([]);
const unavailable = ref(false);

const hasList = computed(() => employees.value.length > 0);

const chosen = computed({
    get: () => props.modelValue,
    set: (value: string) => emit('update:modelValue', value),
});

onMounted(async () => {
    try {
        // Only somebody who can own a deal: §10.1's deactivated employees are
        // not candidates, and the server already hides the Super Admin (§3.12).
        employees.value = (await listUsers({ isActive: true })).items;
        unavailable.value = false;
    } catch {
        employees.value = [];
        unavailable.value = true;
    }
});
</script>

<template>
    <select
        v-if="hasList"
        :id="fieldId"
        v-model="chosen"
        :disabled="disabled"
        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
        :data-testid="testId"
    >
        <option value="">{{ t('deals.assign.ownerNone') }}</option>
        <option v-for="employee in employees" :key="employee.id" :value="employee.id">
            {{ employee.name }} — {{ employee.role.label }}
        </option>
    </select>

    <!-- ⚠️ The `v-else` must stay adjacent to the `v-if` above: an element
         between them severs the chain and this never renders, which is exactly
         what happened when the sentence below was first written inline. -->
    <input
        v-else
        :id="fieldId"
        v-model="chosen"
        type="text"
        :disabled="disabled"
        :placeholder="t('deals.form.ownerPlaceholder')"
        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
        :data-testid="testId"
    />

    <span v-if="unavailable" class="text-[var(--color-text-muted)]" :data-testid="`${testId}-unavailable`">
        {{ t('deals.assign.listUnavailable') }}
    </span>
</template>

<style scoped>
.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}
</style>
