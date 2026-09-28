<script setup lang="ts">
/**
 * F-10 · 1.8 (`D-86`) — suppliers chosen by searching the server, many at once.
 *
 * `SearchCombobox`'s search, keyboard and states (F-18 · 1.1), many-valued: a
 * choice toggles in the listbox and shows as a removable chip. Deactivated
 * suppliers are offered — ruling A2 lets one be linked — and say so, with the
 * contact and phone, so two suppliers with one name can be told apart.
 *
 * The model is `{id, name}` pairs, so the chips need no second request: the
 * caller already holds the names (the catalog item's read carries them).
 *
 * The list sits in the flow, not floated: the form it lives in is a dialog
 * that scrolls, and an absolute list was clipped by it (`rtl-ui-verifier`,
 * 2026-09-22). In the flow it pushes the dialog longer instead.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SearchCombobox from '@/components/SearchCombobox.vue';
import { listSuppliers, type Supplier } from '@/services/suppliers';
import { SUPPLIER_KEYS, supplierDetail } from '@/components/suppliers/supplierOptions';

defineOptions({ inheritAttrs: false });

type Picked = Pick<Supplier, 'id' | 'name'>;

defineProps<{ testId: string; disabled?: boolean }>();

const model = defineModel<Picked[]>({ required: true });

const { t } = useI18n();

const chosen = computed(() => new Set(model.value.map((picked) => picked.id)));

function toggle(supplier: Picked | null): void {
    if (supplier === null) return;

    model.value = chosen.value.has(supplier.id)
        ? model.value.filter((picked) => picked.id !== supplier.id)
        : [...model.value, { id: supplier.id, name: supplier.name }];
}
</script>

<template>
    <SearchCombobox
        v-bind="$attrs"
        :test-id="testId"
        :search="listSuppliers"
        :keys="SUPPLIER_KEYS"
        :selected="(supplier: Supplier) => chosen.has(supplier.id)"
        :disabled="disabled"
        :placeholder="t('suppliers.picker.placeholder')"
        multiple
        @pick="toggle"
    >
        <template #before>
            <ul v-if="model.length > 0" class="flex flex-wrap gap-2">
                <li
                    v-for="picked in model"
                    :key="picked.id"
                    class="flex min-h-11 items-center gap-1 rounded-full border border-[var(--color-border)] bg-[var(--color-surface-muted)] ps-3"
                    :data-testid="`${testId}-chip`"
                >
                    <span>{{ picked.name }}</span>
                    <button
                        type="button"
                        class="min-h-11 min-w-11 rounded-full focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :aria-label="t('suppliers.picker.remove', { name: picked.name })"
                        :disabled="disabled"
                        :data-testid="`${testId}-chip-remove`"
                        @click="toggle(picked)"
                    >
                        <span aria-hidden="true">×</span>
                    </button>
                </li>
            </ul>
        </template>

        <template #option="{ item }">
            <span>
                <span class="block">{{ item.name }}</span>
                <span
                    v-if="supplierDetail(item, t) !== ''"
                    class="block text-sm text-[var(--color-text-muted)]"
                    :data-testid="`${testId}-option-detail`"
                >{{ supplierDetail(item, t) }}</span>
            </span>
        </template>
    </SearchCombobox>
</template>
