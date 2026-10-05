<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ReceiptText, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { posApi } from '../api';

/** Sales and returns, newest first: by day, by receipt number, or those marked for review. */
const pos = posApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const day = ref(route.query.date ?? '');
const review = ref(route.query.review === '1');
const number = ref('');
const list = useResource(() => pos.sales({ ...(day.value ? { date: day.value } : {}), ...(review.value ? { review: 1 } : {}), ...(number.value.trim() ? { number: number.value.trim() } : {}) }));
watch([day, review], () => {
    router.replace({ query: { ...(day.value ? { date: day.value } : {}), ...(review.value ? { review: '1' } : {}) } });
    list.reload();
});
const registers = useResource(() => pos.registers());
const registerName = (id) => (registers.data.value?.data ?? []).find((row) => row.id === id)?.name ?? '';
const sales = computed(() => list.data.value?.data ?? []);
</script>

<template>
    <div>
        <PageHeader :title="t('pos.sales.title')" :description="t('pos.sales.text')" />
        <section class="card mb-4 flex flex-wrap items-end gap-4 p-4">
            <label class="grid gap-1 text-[12.5px] text-muted">{{ t('pos.sales.day') }}<input v-model="day" type="date" class="field-input" /></label>
            <div class="relative min-w-0 max-w-xs flex-1">
                <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                <input v-model="number" class="field-input ps-9 font-mono" dir="ltr" :placeholder="t('pos.sales.number')" :aria-label="t('pos.sales.number')" @keydown.enter="list.reload()" />
            </div>
            <AppSwitch v-model="review" :label="t('pos.sales.only_review')" show-label />
        </section>
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!sales.length" :icon="ReceiptText" :title="t('pos.sales.empty')" :text="t('pos.sales.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="sale in sales" :key="sale.id">
                    <RouterLink :to="{ name: 'pos-sale', params: { id: sale.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block font-mono text-[13.5px] font-medium" dir="ltr">{{ sale.number }}</span>
                            <span class="block text-[12.5px] text-muted">{{ formatDateTime(sale.sold_at) }} · {{ registerName(sale.register_id) }}<template v-if="sale.customer_name"> · {{ sale.customer_name }}</template></span>
                        </span>
                        <AppBadge v-if="sale.kind === 'return'" tone="neutral">{{ t('pos.sales.return') }}</AppBadge>
                        <AppBadge v-if="sale.offline" tone="neutral">{{ t('pos.receipt.offline') }}</AppBadge>
                        <AppBadge v-if="sale.review_reason" tone="warn" dot>{{ t(`pos.review.${sale.review_reason}`) }}</AppBadge>
                        <span class="tabular w-32 text-end text-[14px] font-semibold" :class="sale.kind === 'return' ? 'text-bad' : ''">{{ sale.kind === 'return' ? '−' : '' }}{{ formatMoney({ amount: sale.total_minor, currency: sale.currency }) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </div>
</template>
