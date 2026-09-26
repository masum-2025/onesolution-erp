<script setup>
import { watch } from 'vue';
import { useRoute } from 'vue-router';
import AuthTopBar from '@/layouts/AuthTopBar.vue';
import BrandLockup from '@/components/BrandLockup.vue';
import ErrorState from '@/components/ErrorState.vue';
import LegalText from '@/components/LegalText.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { i18n, t } from '@/lib/i18n';

/**
 * The terms or privacy notice in force at this address, for anyone to read
 * (e.g. before signing up).
 */
const route = useRoute();
const doc = useResource(() => api(`/session/legal/${route.params.kind}`).then((response) => response.data));

watch(() => [route.params.kind, i18n.locale], () => doc.reload());
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-canvas">
        <AuthTopBar />
        <main class="mx-auto w-full max-w-2xl flex-1 px-4 py-10">
            <BrandLockup class="mb-8" />
            <SkeletonRows v-if="doc.loading.value && !doc.data.value" :rows="6" />
            <ErrorState v-else-if="doc.error.value" :error="doc.error.value" @retry="doc.reload()" />
            <article v-else-if="doc.data.value" class="card p-6 sm:p-8">
                <h1 class="text-[22px] font-semibold text-fg">{{ doc.data.value.title }}</h1>
                <p class="mt-1 text-[12.5px] text-muted">{{ t('identity.legal.version', { version: doc.data.value.version, date: formatDate(doc.data.value.published_at) }) }}</p>
                <LegalText class="mt-6" :text="doc.data.value.body" />
            </article>
        </main>
    </div>
</template>
