<script setup>
import { RouterLink } from 'vue-router';
import AppBadge from '@/components/AppBadge.vue';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { scoreText, stageTone } from '../lib';

/** Reviews as a list: who (or which cycle), the step and its last day, the result once there is one; a row opens the review. */
defineProps({
    items: { type: Array, required: true },
    // show the cycle's name (one's own reviews) or the employee's (a team, a cycle)
    by: { type: String, default: 'employee' },
});

const day = (value) => (value ? formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) : '');
</script>

<template>
    <ul class="divide-y divide-line">
        <li v-for="item in items" :key="item.id">
            <RouterLink :to="`/appraisals/reviews/${item.id}`" class="grid gap-1 px-5 py-3 hover:bg-subtle sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-4">
                <span class="min-w-0">
                    <span class="block truncate text-[14px] font-medium">
                        <template v-if="by === 'cycle'">{{ item.cycle_name }}</template>
                        <template v-else>{{ item.employee.name }} <span class="font-mono text-[12px] font-normal text-muted" dir="ltr">{{ item.employee.code }}</span></template>
                    </span>
                    <span class="block text-[12.5px] text-muted">
                        <template v-if="by !== 'cycle' && item.manager_name">{{ t('appraisals.rows.manager', { name: item.manager_name }) }} · </template>
                        <template v-if="item.due && item.stage !== 'done' && item.stage !== 'acknowledge'">{{ t('appraisals.rows.due', { date: day(item.due) }) }}</template>
                        <template v-if="item.stage === 'goals' && item.goals_sent"> · {{ t('appraisals.rows.goals_sent') }}</template>
                    </span>
                </span>
                <span class="flex items-center gap-3">
                    <span v-if="item.score !== null" class="tabular text-[14px] font-semibold">{{ scoreText(item.score) }}</span>
                    <AppBadge :tone="stageTone(item.stage)">{{ t(`appraisals.stages.${item.stage}`) }}</AppBadge>
                </span>
            </RouterLink>
        </li>
    </ul>
</template>
