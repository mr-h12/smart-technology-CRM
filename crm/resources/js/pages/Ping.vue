<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { apiGet } from '@/api';
import { useI18n } from 'vue-i18n';
import LoadingState from '@/components/states/LoadingState.vue';
import ErrorState from '@/components/states/ErrorState.vue';

interface Ping {
    service: string;
    time: string;
}

const { t } = useI18n();

const state = ref<'loading' | 'ok' | 'error'>('loading');
const ping = ref<Ping | null>(null);
const requestId = ref<string | null>(null);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    state.value = 'loading';

    try {
        const result = await apiGet<Ping>('/ping');
        ping.value = result.data;
        requestId.value = result.requestId;
        state.value = 'ok';
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
        state.value = 'error';
    }
}

onMounted(load);
</script>

<template>
    <!-- Loading and error come from the §8 base components rather than being
         spelled out again here. That is the point of them: this page owes a
         request and a table, not an accessible spinner. It is also what puts
         them in front of vue-tsc and the bundler — four components nothing
         imports are four components nothing checks. -->
    <section>
        <h1 class="mb-4 text-page-title">{{ t('ping.title') }}</h1>

        <LoadingState v-if="state === 'loading'" />

        <ErrorState v-else-if="state === 'error'" @retry="load">
            <!-- Diagnostic, not copy: the raw message is data and sits beside the
                 translated explanation rather than replacing it. -->
            <template #detail>{{ error }}</template>
        </ErrorState>

        <dl v-else-if="ping">
            <dt>{{ t('ping.service') }}</dt><dd>{{ ping.service }}</dd>
            <dt>{{ t('ping.time') }}</dt><dd>{{ ping.time }}</dd>
            <dt>{{ t('ping.requestId') }}</dt><dd>{{ requestId }}</dd>
        </dl>
    </section>
</template>
