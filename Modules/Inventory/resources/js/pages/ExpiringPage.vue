<script setup>
import { computed } from 'vue';
import { CalendarClock } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { inventoryApi } from '../api';
import { formatQuantity } from '../lib';

/** Batches still held that expire within the rule's days, or already have: first to expire first. */
const inventory = inventoryApi(currentOrganization().id);
const list = useResource(() => inventory.expiring());
const items = useResource(() => inventory.list('items'));
const itemOf = (id) => (items.data.value?.data ?? []).find((item) => item.id === id);
const batches = computed(() => list.data.value?.data ?? []);
const today = new Date().toISOString().slice(0, 10);
</script>

<template>
    <div>
        <PageHeader :title="t('inventory.expiring.title')" :description="t('inventory.expiring.text', { days: formatNumber(list.data.value?.meta?.days ?? 0) })" />
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!batches.length" :icon="CalendarClock" :title="t('inventory.expiring.empty')" :text="t('inventory.expiring.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="batch in batches" :key="batch.id">
                    <RouterLink :to="{ name: 'inventory-item', params: { id: batch.item_id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ itemOf(batch.item_id)?.name ?? '—' }} <span class="font-mono text-[12px] text-muted" dir="ltr">{{ batch.number }}</span></span>
                            <span class="block text-[12.5px] text-muted">{{ t('inventory.item.expires', { date: formatDate(`${batch.expires_on}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) }) }}</span>
                        </span>
                        <AppBadge :tone="batch.expires_on < today ? 'bad' : 'warn'" dot>{{ t(batch.expires_on < today ? 'inventory.expiring.expired' : 'inventory.expiring.soon') }}</AppBadge>
                        <span class="tabular w-20 text-end font-semibold">{{ formatQuantity(batch.quantity_milli) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </div>
</template>
