<script setup>
import AppBadge from '@/components/AppBadge.vue';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { amountText, monthText, statusTone } from '../lib';

/** Claims of other people as a list (who, what, how much, where it stands); a row opens the claim. */
defineProps({
    items: { type: Array, required: true },
});
const emit = defineEmits(['open']);

function where(item) {
    if (item.status === 'submitted' && item.steps_needed > 1) return t('expenses.detail.at_step', { step: item.steps_done + 1, steps: item.steps_needed });
    if (item.status === 'approved' && item.pay_method === 'payroll') return t('expenses.detail.with_salary', { month: monthText(item.pay_period) });
    return '';
}
</script>

<template>
    <ul class="divide-y divide-line">
        <li v-for="item in items" :key="item.id">
            <button type="button" class="grid w-full gap-1 px-5 py-3 text-start hover:bg-subtle sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-4" @click="emit('open', item)">
                <span class="min-w-0">
                    <span class="block text-[14px] font-medium">
                        {{ item.employee.name }} <span class="font-mono text-[12px] font-normal text-muted" dir="ltr">{{ item.employee.code }}</span>
                    </span>
                    <span class="block truncate text-[13px]">{{ item.title }}</span>
                    <span class="block text-[12.5px] text-muted">
                        <span class="font-mono" dir="ltr">{{ item.number }}</span>
                        <template v-if="item.submitted_at"> · {{ formatDate(item.submitted_at, { dateStyle: 'medium' }) }}</template>
                        · {{ t('expenses.common.lines', { count: item.line_count }) }}
                        <template v-if="where(item)"> · {{ where(item) }}</template>
                    </span>
                </span>
                <span class="flex items-center gap-3">
                    <span class="tabular text-[14px] font-semibold">{{ amountText(item.total_minor, item.currency) }}</span>
                    <AppBadge :tone="statusTone(item.status)">{{ t(`expenses.status.${item.status}`) }}</AppBadge>
                </span>
            </button>
        </li>
    </ul>
</template>
