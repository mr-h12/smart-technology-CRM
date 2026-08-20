<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { apiGet } from '@/api';

interface Ping {
    service: string;
    time: string;
}

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
         Coding Standards §11 requires every screen to have them. -->
    <section>
        <p v-if="state === 'loading'">…</p>
        <p v-else-if="state === 'error'">{{ error }}</p>
        <dl v-else-if="ping">
            <dt>service</dt><dd>{{ ping.service }}</dd>
            <dt>time (UTC)</dt><dd>{{ ping.time }}</dd>
            <dt>X-Request-Id</dt><dd>{{ requestId }}</dd>
        </dl>
    </section>
</template>
