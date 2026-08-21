<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { apiGet } from '@/api';
import { useI18n } from 'vue-i18n';

interface Ping {
    service: string;
    time: string;
}

const { t } = useI18n();

const state = ref<'loading' | 'ok' | 'error'>('loading');
const ping = ref<Ping | null>(null);
const requestId = ref<string | null>(null);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const result = await apiGet<Ping>('/ping');
        ping.value = result.data;
        requestId.value = result.requestId;
        state.value = 'ok';
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
        state.value = 'error';
    }
});
</script>

<template>
    <!-- Loading, error and success are all rendered rather than assumed:
         Coding Standards §11 requires every screen to have them.

         Every visible string comes from a lang file. The only literals left are
         the request id and the timestamp, which are data. -->
    <section>
        <h1>{{ t('ping.title') }}</h1>

        <p v-if="state === 'loading'">{{ t('state.loading') }}</p>

        <template v-else-if="state === 'error'">
            <p>{{ t('state.error') }}</p>
            <!-- The raw message is diagnostic, not a user-facing string, so it
                 is shown beside the translated one rather than instead of it. -->
            <p><small>{{ error }}</small></p>
        </template>

        <dl v-else-if="ping">
            <dt>{{ t('ping.service') }}</dt><dd>{{ ping.service }}</dd>
            <dt>{{ t('ping.time') }}</dt><dd>{{ ping.time }}</dd>
            <dt>{{ t('ping.requestId') }}</dt><dd>{{ requestId }}</dd>
        </dl>
    </section>
</template>
