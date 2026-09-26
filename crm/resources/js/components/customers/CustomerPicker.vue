<script setup lang="ts">
/**
 * F-08 (`D-84`) — a customer dropdown that searches the server.
 *
 * A `<select>` filled from one `listCustomers({ perPage: 100 })` could never
 * offer the 101st customer (`MAX_PER_PAGE`), and the page is sorted by name,
 * so every Arabic name fell past it. Design System §6.3: "Search only when
 * the option volume needs it". The search, the keyboard and the states are
 * `SearchCombobox`'s (F-18 · 1.1); this adds the customer's detail line, the
 * single value, and the filter's «كل العملاء».
 *
 * The list is the caller's own `customer.view` scope, so this shows no field
 * the customers list does not already send, and archived customers are never
 * offered (the server's default).
 *
 * `allLabel` is the filter's «كل العملاء»: when given, it is the first option
 * and a clear button appears beside a chosen customer. A caller without it
 * gets neither, and says its own `placeholder` — a form's required field.
 * Attributes such as `id`, `disabled` and `aria-invalid` fall through to the
 * input, so a `<label for>` and a form's saving and 422 states reach it.
 */
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import SearchCombobox from '@/components/SearchCombobox.vue';
import { listCustomers, type Customer } from '@/services/customers';

defineOptions({ inheritAttrs: false });

defineProps<{
    testId: string;
    allLabel?: string;
    placeholder?: string;
}>();

const model = defineModel<string>({ required: true });

const { t } = useI18n();

const KEYS = {
    more: 'customers.picker.more',
    forbidden: 'customers.picker.forbidden',
    failed: 'customers.picker.failed',
    empty: 'customers.picker.emptyScope',
    noMatch: 'customers.picker.noMatch',
    retry: 'customers.picker.retry',
};

/**
 * The name of the row the user picked. An id set from outside with no picked
 * row shows as the id — D-83's fallback.
 * ponytail: no screen starts with a customer chosen today; add a
 * `readCustomer` lookup when one does.
 */
const pickedName = ref('');

const display = computed(() => (model.value === '' ? '' : pickedName.value || model.value));

/** Whichever of region, contact person and phone the row has (D-84), in that order. */
function detail(customer: Customer): string {
    return [customer.region, customer.contact_person, customer.phone]
        .filter((part): part is string => typeof part === 'string' && part !== '')
        .join(t('customers.picker.detailSeparator'));
}

function picked(customer: Customer | null): void {
    pickedName.value = customer?.name ?? '';
    model.value = customer?.id ?? '';
}

watch(model, (value) => {
    if (value === '') {
        pickedName.value = '';
    }
});
</script>

<template>
    <SearchCombobox
        v-bind="$attrs"
        :test-id="testId"
        :search="listCustomers"
        :keys="KEYS"
        :selected="(customer: Customer) => customer.id === model"
        :leading="allLabel === undefined ? undefined : { label: allLabel, selected: model === '' }"
        :display="display"
        :placeholder="allLabel ?? placeholder"
        floating
        @pick="picked"
    >
        <template #inputEnd="{ pick }">
            <button
                v-if="allLabel !== undefined && model !== ''"
                type="button"
                class="min-h-11 rounded-lg px-3 text-[var(--color-text-muted)] focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                :data-testid="`${testId}-clear`"
                @click="pick(null)"
            >
                {{ t('customers.picker.clear') }}
            </button>
        </template>

        <template #option="{ item }">
            <span class="block" :data-testid="`${testId}-option-name`">{{ item.name }}</span>
            <span
                v-if="detail(item) !== ''"
                class="block text-sm text-[var(--color-text-muted)]"
                :data-testid="`${testId}-option-detail`"
            >
                {{ detail(item) }}
            </span>
        </template>
    </SearchCombobox>
</template>
