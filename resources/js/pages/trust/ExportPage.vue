<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';
import { Download, FileArchive, Loader2, RefreshCw } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * The organization's own data, all of it, at any time (also while the
 * service provider is suspended). Built in the background; downloaded
 * through a link that works for a few minutes.
 */
const org = currentOrganization();
const exports = useResource(() => api(`/api/organizations/${org.id}/exports`).then((response) => response.data));
const list = computed(() => exports.data.value ?? []);
const working = computed(() => list.value.some((item) => ['queued', 'running'].includes(item.status)));

// While one is being prepared, look again every few seconds.
let poll;
watch(working, (value) => {
    clearInterval(poll);
    if (value) poll = setInterval(() => exports.reload(), 4000);
}, { immediate: true });
onBeforeUnmount(() => clearInterval(poll));

const STATUS_TONES = { queued: 'neutral', running: 'brand', ready: 'ok', failed: 'bad', expired: 'outline' };

async function request() {
    try {
        const response = await api(`/api/organizations/${org.id}/exports`, { method: 'POST' });
        toast.success(response.message);
        exports.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

async function download(item) {
    try {
        const { data } = await api(`/api/organizations/${org.id}/exports/${item.id}/link`);
        window.location.assign(data.url);
    } catch (error) {
        toast.error(error.message);
    }
}

function size(bytes) {
    if (!bytes) return '';
    return bytes > 1048576 ? `${formatNumber(Math.round(bytes / 104857.6) / 10)} MB` : `${formatNumber(Math.max(1, Math.round(bytes / 1024)))} KB`;
}

const rows = (item) => Object.values(item.datasets ?? {}).reduce((sum, count) => sum + count, 0);
</script>

<template>
    <div>
        <PageHeader :title="t('trust.export.title')" :description="t('trust.export.text')">
            <template #actions>
                <AppButton v-if="can('data.export')" variant="primary" :icon="FileArchive" :disabled="working" @click="request">{{ t('trust.export.request') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="exports.loading.value && !exports.data.value" :rows="3" />
            <ErrorState v-else-if="exports.error.value" compact :error="exports.error.value" @retry="exports.reload()" />
            <EmptyState v-else-if="!list.length" :icon="FileArchive" :title="t('trust.export.empty_title')" :text="t('trust.export.empty_text')" compact>
                <AppButton v-if="can('data.export')" variant="primary" :icon="FileArchive" @click="request">{{ t('trust.export.request') }}</AppButton>
            </EmptyState>

            <ul v-else class="divide-y divide-line">
                <li v-for="item in list" :key="item.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                            {{ formatDateTime(item.requested_at) }}
                            <AppBadge :tone="STATUS_TONES[item.status]" :icon="item.status === 'running' || item.status === 'queued' ? Loader2 : null">{{ t(`trust.export.status.${item.status}`) }}</AppBadge>
                        </p>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            <template v-if="item.status === 'ready'">
                                {{ t('trust.export.contents', { rows: formatNumber(rows(item)), sets: formatNumber(Object.keys(item.datasets ?? {}).length) }) }} · {{ size(item.size_bytes) }} ·
                                {{ t('trust.export.until', { date: formatDateTime(item.expires_at) }) }}
                            </template>
                            <template v-else-if="item.status === 'failed'">{{ t('trust.export.failed') }}</template>
                            <template v-else-if="item.status === 'expired'">{{ t('trust.export.expired') }}</template>
                            <template v-else>{{ t('trust.export.preparing') }}</template>
                        </p>
                    </div>
                    <AppButton v-if="item.status === 'ready'" :icon="Download" @click="download(item)">{{ t('trust.export.download') }}</AppButton>
                    <AppButton v-else-if="item.status === 'failed' && can('data.export')" :icon="RefreshCw" @click="request">{{ t('trust.export.retry') }}</AppButton>
                </li>
            </ul>
        </section>

        <p class="mt-4 text-[12.5px] leading-relaxed text-muted">{{ t('trust.export.formats') }}</p>
    </div>
</template>
