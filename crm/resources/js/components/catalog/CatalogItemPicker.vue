<script setup lang="ts">
/**
 * F-18 · 1.2 (`D-93`) — a supplier offer line's product, searched on the server.
 *
 * The `<select>` it replaces was filled from one `listCatalogItems({ perPage: 100 })`
 * and could never offer the 101st item (E1-5). The search, the keyboard and the
 * states are `SearchCombobox`'s (F-18 · 1.1); this adds §10.4's active items
 * only, `D-93`'s `supplier_first` — the items `D-86` links to the offer's
 * supplier come first — and §7.3's label: a product's `name`, a service's
 * `service_type`, the id as the last resort.
 *
 * `D-22` stays the old select's empty option: «Type a name instead» is first and
 * empties the choice, and the form shows its name box. The label is a model of
 * its own because the form's rows are keyed by index, so the name lives on the
 * line, not in this instance. Attributes fall through to the input.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SearchCombobox from '@/components/SearchCombobox.vue';
import { listCatalogItems, type CatalogItem, type Page } from '@/services/catalog';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    testId: string;
    /** The offer's supplier, `''` before one is chosen. */
    supplierId: string;
}>();

const model = defineModel<string>({ required: true });
const label = defineModel<string>('label', { default: '' });

const { t } = useI18n();

const KEYS = {
    more: 'catalog.picker.more',
    forbidden: 'catalog.picker.forbidden',
    failed: 'catalog.picker.failed',
    empty: 'catalog.picker.empty',
    noMatch: 'catalog.picker.noMatch',
    retry: 'state.retry',
};

type Option = CatalogItem & { name: string };

async function search(query: { perPage: number; q: string | null }): Promise<Page<Option>> {
    const page = await listCatalogItems({ ...query, isActive: true, supplierFirst: props.supplierId });

    return { ...page, items: page.items.map((item) => ({ ...item, name: item.name ?? item.service_type ?? item.id })) };
}

/** An id with no label shows as the id — D-83's fallback. */
const display = computed(() => (model.value === '' ? '' : label.value || model.value));

function picked(item: Option | null): void {
    label.value = item?.name ?? '';
    model.value = item?.id ?? '';
}
</script>

<template>
    <SearchCombobox
        v-bind="$attrs"
        :test-id="testId"
        :search="search"
        :keys="KEYS"
        :selected="(item: Option) => item.id === model"
        :leading="{ label: t('catalog.picker.byName'), selected: model === '' }"
        :display="display"
        :placeholder="t('catalog.picker.placeholder')"
        floating
        @pick="picked"
    >
        <template #option="{ item }">{{ item.name }}</template>
    </SearchCombobox>
</template>
