<script setup>
import { computed } from 'vue';
import { ReceiptText } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { portalExpensesApi } from '../api';
import { amountText, monthText, statusTone } from '../lib';

/** A portal member's own claims (the employee records the client linked to them): where each sent claim stands. Read only. */
const view = useResource(() => portalExpensesApi.show());
const people = computed(() => view.data.value?.data ?? []);
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('expenses.portal.title')" :description="t('expenses.portal.text')" />

        <SkeletonRows v-if="view.loading.value && !view.data.value" :rows="4" />
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <section v-else-if="!people.length" class="card">
            <EmptyState :icon="ReceiptText" :title="t('expenses.portal.empty')" compact />
        </section>
        <template v-else>
            <section v-for="person in people" :key="person.employee.id" class="card" :aria-label="person.employee.name">
                <h2 class="border-b border-line px-5 py-3 text-[15px] font-semibold">{{ person.employee.name }}</h2>
                <EmptyState v-if="!person.claims.length" :icon="ReceiptText" :title="t('expenses.portal.none')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in person.claims" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                        <span class="min-w-0 text-[13.5px]">
                            <span class="block font-medium">{{ item.title }}</span>
                            <span class="block text-[12.5px] text-muted">
                                <span class="font-mono" dir="ltr">{{ item.number }}</span> · {{ formatDate(item.submitted_at, { dateStyle: 'medium' }) }}
                                <template v-if="item.status === 'approved' && item.pay_method === 'payroll'"> · {{ t('expenses.detail.with_salary', { month: monthText(item.pay_period) }) }}</template>
                            </span>
                        </span>
                        <span class="flex items-center gap-3">
                            <span class="tabular font-semibold">{{ amountText(item.total_minor, item.currency) }}</span>
                            <AppBadge :tone="statusTone(item.status)">{{ t(`expenses.status.${item.status}`) }}</AppBadge>
                        </span>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
