<script setup>
import { computed, ref } from 'vue';
import { BookCheck, RotateCw } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';

/**
 * Whether fee events reached the books: counts, and the ones waiting or
 * refused with the books' reason (a closed period, a missing account).
 * People who set fees up try them again after fixing the cause.
 */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();
const list = useResource(() => api.postings());
const rows = computed(() => (list.data.value?.data ?? []).filter((row) => row.status !== 'posted'));
const counts = computed(() => list.data.value?.meta?.counts ?? { posted: 0, pending: 0, failed: 0 });
const tone = { posted: 'ok', pending: 'warn', failed: 'bad' };

const retrying = ref(false);
async function retry() {
    retrying.value = true;
    try {
        const { data } = await api.retryPostings();
        toast.success(t('education_fees.books.retried', { count: data.posted }));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        retrying.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('education_fees.books.title')" :description="t('education_fees.books.text')">
            <template v-if="setup.can('configure') && counts.failed + counts.pending > 0" #actions>
                <AppButton variant="primary" :icon="RotateCw" :loading="retrying" @click="retry">{{ t('education_fees.books.retry') }}</AppButton>
            </template>
        </PageHeader>

        <dl class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div v-for="key in ['posted', 'pending', 'failed']" :key="key" class="card p-4">
                <dt class="text-[12px] text-muted">{{ t(`education_fees.books.${key}`) }}</dt>
                <dd class="tabular text-[22px] font-semibold" :class="key === 'failed' && counts.failed ? 'text-bad' : 'text-fg'">{{ counts[key] }}</dd>
            </div>
        </dl>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="BookCheck" :title="t('education_fees.books.empty')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="row.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3">
                    <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                        <span class="block text-[14px] font-medium text-fg">{{ t(`education_fees.books.kinds.${row.kind}`) }}</span>
                        <span class="block text-[12.5px] text-muted">{{ formatDateTime(row.created_at) }} · {{ t('education_fees.books.attempts', { count: row.attempts }) }}</span>
                        <span v-if="row.error" class="mt-1 block text-[12.5px] text-bad">{{ row.error }}</span>
                    </span>
                    <AppBadge :tone="tone[row.status]" dot>{{ t(`education_fees.books.${row.status}`) }}</AppBadge>
                </li>
            </ul>
        </section>
    </div>
</template>
