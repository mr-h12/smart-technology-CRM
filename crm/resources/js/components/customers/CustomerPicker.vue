<script setup lang="ts">
/**
 * F-08 (`D-84`) — a customer dropdown that searches the server.
 *
 * A `<select>` filled from one `listCustomers({ perPage: 100 })` could never
 * offer the 101st customer (`MAX_PER_PAGE`), and the page is sorted by name,
 * so every Arabic name fell past it. Design System §6.3: "Search only when
 * the option volume needs it". So nothing is asked until the picker opens;
 * opening asks for the first 20 by name, and typing asks the server with `q`
 * after 300 ms of quiet (`OpenAPI §6.2` — `q` "always passes through
 * `SearchService`", which folds the Arabic letter variants).
 *
 * The list is the caller's own `customer.view` scope, so this shows no field
 * the customers list does not already send, and archived customers are never
 * offered (the server's default).
 *
 * The keyboard follows the WAI-ARIA APG combobox with a listbox popup: focus
 * stays on the input and `aria-activedescendant` names the active option.
 *
 * `allLabel` is the filter's «كل العملاء»: when given, it is the first option
 * and a clear button appears beside a chosen customer. A caller without it
 * gets neither, and says its own `placeholder` — a form's required field.
 * Attributes such as `id`, `disabled` and `aria-invalid` fall through to the
 * input, so a `<label for>` and a form's saving and 422 states reach it.
 */
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import { listCustomers, type Customer } from '@/services/customers';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    testId: string;
    allLabel?: string;
    placeholder?: string;
}>();

const model = defineModel<string>({ required: true });

const { t } = useI18n();

/** 20 is small enough to scan and large enough that two letters usually find the customer. */
const PAGE = 20;
const PAUSE_MS = 300;

const listboxId = useId();
const optionId = (index: number): string => `${listboxId}-option-${index}`;

const open = ref(false);
const text = ref('');
const searched = ref('');
const items = ref<Customer[]>([]);
const total = ref(0);
const status = ref<'idle' | 'loading' | 'ready' | 'forbidden' | 'failed'>('idle');
const active = ref(-1);

/**
 * The name of the row the user picked. An id set from outside with no picked
 * row shows as the id — D-83's fallback.
 * ponytail: no screen starts with a customer chosen today; add a
 * `readCustomer` lookup when one does.
 */
const pickedName = ref('');

type Choice = { id: string; name: string; detail: string; all: boolean };

/** Whichever of region, contact person and phone the row has (D-84), in that order. */
function detail(customer: Customer): string {
    return [customer.region, customer.contact_person, customer.phone]
        .filter((part): part is string => typeof part === 'string' && part !== '')
        .join(t('customers.picker.detailSeparator'));
}

const choices = computed<Choice[]>(() => [
    ...(props.allLabel === undefined ? [] : [{ id: '', name: props.allLabel, detail: '', all: true }]),
    ...(status.value === 'ready' ? items.value.map((c) => ({ id: c.id, name: c.name, detail: detail(c), all: false })) : []),
]);

let sequence = 0;
let pause: ReturnType<typeof setTimeout> | undefined;

async function search(): Promise<void> {
    const mine = ++sequence;
    status.value = 'loading';

    try {
        const result = await listCustomers({ perPage: PAGE, q: searched.value === '' ? null : searched.value });
        if (mine !== sequence) return;
        items.value = result.items;
        total.value = result.pagination.total;
        status.value = 'ready';
    } catch (error) {
        if (mine !== sequence) return;
        items.value = [];
        // A refusal is a permission, not an outage: §3.3 gates customers apart
        // from quotations and deals, so the screen around this keeps working.
        status.value = error instanceof ApiError && error.status === 403 ? 'forbidden' : 'failed';
    }

    active.value = -1;
}

function show(): void {
    open.value = true;

    if (status.value === 'idle') {
        void search();
    }
}

function typed(): void {
    open.value = true;
    active.value = -1;
    clearTimeout(pause);
    pause = setTimeout(() => {
        searched.value = text.value.trim();
        void search();
    }, PAUSE_MS);
}

function choose(choice: Choice): void {
    pickedName.value = choice.all ? '' : choice.name;
    text.value = pickedName.value;
    open.value = false;
    active.value = -1;
    model.value = choice.id;
}

const root = ref<HTMLElement | null>(null);

/** Closes only when focus leaves the whole picker, so Tab can reach Clear and Retry. */
function left(event: FocusEvent): void {
    if (!(event.relatedTarget instanceof Node && root.value?.contains(event.relatedTarget))) {
        close();
    }
}

function close(): void {
    open.value = false;
    active.value = -1;
    text.value = model.value === '' ? '' : pickedName.value || model.value;
}

function keydown(event: KeyboardEvent): void {
    const count = choices.value.length;

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        show();
        active.value = count === 0 ? -1 : Math.min(active.value + 1, count - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = count === 0 ? -1 : Math.max(active.value - 1, 0);
    } else if (event.key === 'Enter' && open.value && choices.value[active.value] !== undefined) {
        event.preventDefault();
        choose(choices.value[active.value]!);
    } else if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        close();
    }
}

watch(model, (value) => {
    if (value === '') {
        pickedName.value = '';
        text.value = '';
    }
});
</script>

<template>
    <div ref="root" class="relative" @focusout="left">
        <div class="flex items-center gap-1">
            <input
                v-bind="$attrs"
                v-model="text"
                type="text"
                role="combobox"
                autocomplete="off"
                aria-autocomplete="list"
                :aria-expanded="open ? 'true' : 'false'"
                :aria-controls="listboxId"
                :aria-activedescendant="active >= 0 ? optionId(active) : undefined"
                :placeholder="allLabel ?? placeholder"
                class="form-field min-h-11 w-full rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                :data-testid="testId"
                @focus="show"
                @input="typed"
                @keydown="keydown"
            />
            <button
                v-if="allLabel !== undefined && model !== ''"
                type="button"
                class="min-h-11 rounded-lg px-3 text-[var(--color-text-muted)] focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                :data-testid="`${testId}-clear`"
                @click="choose({ id: '', name: '', detail: '', all: true })"
            >
                {{ t('customers.picker.clear') }}
            </button>
        </div>

        <div
            v-show="open"
            class="absolute inset-x-0 top-full z-20 mt-1 max-h-80 overflow-y-auto rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-raised)] shadow-lg"
        >
            <ul :id="listboxId" role="listbox" :aria-busy="status === 'loading' ? 'true' : 'false'">
                <li
                    v-for="(choice, index) in choices"
                    :id="optionId(index)"
                    :key="choice.all ? '' : choice.id"
                    role="option"
                    :aria-selected="model === choice.id ? 'true' : 'false'"
                    class="cursor-pointer px-3 py-2"
                    :class="{ 'bg-[var(--color-surface-muted)]': index === active }"
                    :data-testid="choice.all ? `${testId}-all` : `${testId}-option`"
                    @mousedown.prevent="choose(choice)"
                >
                    <template v-if="choice.all">{{ choice.name }}</template>
                    <template v-else>
                        <span class="block" :data-testid="`${testId}-option-name`">{{ choice.name }}</span>
                        <span
                            v-if="choice.detail !== ''"
                            class="block text-sm text-[var(--color-text-muted)]"
                            :data-testid="`${testId}-option-detail`"
                        >
                            {{ choice.detail }}
                        </span>
                    </template>
                </li>
            </ul>

            <p
                v-if="status === 'ready' && total > items.length"
                class="px-3 py-2 text-sm text-[var(--color-text-muted)]"
                :data-testid="`${testId}-more`"
            >
                {{ t('customers.picker.more') }}
            </p>

            <p
                v-if="status === 'forbidden' || status === 'failed' || (status === 'ready' && items.length === 0)"
                class="px-3 py-2 text-sm text-[var(--color-text-muted)]"
                role="status"
                :data-testid="`${testId}-state`"
            >
                <template v-if="status === 'forbidden'">{{ t('customers.picker.forbidden') }}</template>
                <template v-else-if="status === 'failed'">{{ t('customers.picker.failed') }}</template>
                <template v-else-if="searched === ''">{{ t('customers.picker.emptyScope') }}</template>
                <template v-else>{{ t('customers.picker.noMatch', { query: searched }) }}</template>
            </p>

            <button
                v-if="status === 'failed'"
                type="button"
                class="mx-3 mb-2 min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                :data-testid="`${testId}-retry`"
                @click="search"
            >
                {{ t('customers.picker.retry') }}
            </button>
        </div>
    </div>
</template>
