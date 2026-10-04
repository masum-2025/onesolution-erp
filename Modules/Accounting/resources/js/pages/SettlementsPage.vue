<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Banknote, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { documentTone } from '../lib';
import BooksGate from '../components/BooksGate.vue';

/**
 * Money received (or paid), newest first; "advances only" shows money not
 * yet set against documents.
 */
const books = accountingApi(currentOrganization().id);
const route = useRoute();
const type = computed(() => route.meta.type ?? 'receipt');
const plural = computed(() => (type.value === 'receipt' ? 'receipts' : 'payments'));
const writer = computed(() => can(type.value === 'receipt' ? 'accounting.sell' : 'accounting.buy'));

const unallocated = ref(false);
const page = ref(1);
const list = useResource(() => books.settlements({ type: type.value, unallocated: unallocated.value ? 1 : undefined, page: page.value, per_page: 25 }));
const rows = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? null);
watch([type, unallocated], () => {
    page.value = 1;
    list.reload();
});

function go(to) {
    page.value = to;
    list.reload();
}
</script>

<template>
    <div>
        <PageHeader :title="t(`accounting.settlements.${plural}`)" :description="t(`accounting.settlements.${plural}_text`)">
            <template #actions>
                <AppButton v-if="writer" variant="primary" :icon="Banknote" :to="{ name: 'accounting-settlement-new', query: { type } }">{{ t(`accounting.settlements.new_${type}`) }}</AppButton>
            </template>
        </PageHeader>

        <BooksGate>
            <div class="mb-4 flex justify-end">
                <AppSwitch v-model="unallocated" :label="t('accounting.settlements.unallocated')" show-label />
            </div>
            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="8" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState v-else-if="!rows.length" :icon="Banknote" :title="t('accounting.settlements.empty_title')" :text="t('accounting.settlements.empty_text')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="row in rows" :key="row.id">
                        <RouterLink :to="{ name: 'accounting-settlement', params: { id: row.id } }" class="flex items-center gap-3.5 px-4 py-3 transition hover:bg-subtle/60 sm:px-5">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium text-fg">{{ row.party_name }}</span>
                                <span class="mt-0.5 block truncate text-[12.5px] text-muted">
                                    <span v-if="row.number" class="font-mono" dir="ltr">{{ row.number }} · </span>{{ formatDate(row.settled_on) }}<template v-if="row.reference"> · {{ row.reference }}</template>
                                </span>
                            </span>
                            <span class="shrink-0 text-end">
                                <span class="tabular block text-[13.5px] font-medium">{{ formatMoney({ amount: row.amount_minor, currency: row.currency }) }}</span>
                                <span v-if="row.status === 'posted' && row.unallocated_minor > 0" class="tabular block text-[12px] text-muted">
                                    {{ t('accounting.settlements.unallocated') }}: {{ formatMoney({ amount: row.unallocated_minor, currency: row.currency }) }}
                                </span>
                            </span>
                            <AppBadge v-if="row.status !== 'posted'" :tone="documentTone(row.status)" dot class="shrink-0">{{ t(`accounting.statuses.${row.status}`) }}</AppBadge>
                        </RouterLink>
                    </li>
                </ul>
                <footer v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3">
                    <span class="tabular text-[12.5px] text-muted">{{ t('accounting.list.page', { page: formatNumber(meta.page), pages: formatNumber(meta.last_page) }) }}</span>
                    <div class="flex gap-2">
                        <AppButton size="sm" :icon="ChevronLeft" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('core.actions.previous') }}</AppButton>
                        <AppButton size="sm" :icon-end="ChevronRight" :disabled="meta.page >= meta.last_page" @click="go(meta.page + 1)">{{ t('core.actions.next') }}</AppButton>
                    </div>
                </footer>
            </section>
        </BooksGate>
    </div>
</template>
