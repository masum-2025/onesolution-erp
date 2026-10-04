<script setup>
import { computed, ref, watch } from 'vue';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { portalAttendanceApi } from '../api';
import { dayTone, durationParts, shiftMonth } from '../lib';

/** A portal member's own days (the employee records the client linked to them), month by month. */
const month = ref(new Date().toISOString().slice(0, 7));
const list = useResource(() => portalAttendanceApi.days(month.value));
const days = computed(() => list.data.value?.data ?? []);
watch(month, () => list.reload());

const time = (iso) => (iso ? formatDate(iso, { timeStyle: 'short' }) : '—');
</script>

<template>
    <div>
        <PageHeader :title="t('attendance.portal.title')" :description="t('attendance.portal.text')">
            <template #actions>
                <div class="flex items-center gap-1">
                    <AppButton size="icon-sm" variant="ghost" :icon="ChevronLeft" :aria-label="t('attendance.common.previous_month')" @click="month = shiftMonth(month, -1)" />
                    <span class="min-w-32 text-center text-[14px] font-semibold">{{ formatDate(`${month}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' }) }}</span>
                    <AppButton size="icon-sm" variant="ghost" :icon="ChevronRight" :aria-label="t('attendance.common.next_month')" @click="month = shiftMonth(month, 1)" />
                </div>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!days.length" :icon="CalendarDays" :title="t('attendance.portal.empty')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="day in days" :key="`${day.employee_id}-${day.work_date}`" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5 text-[13px]">
                    <span class="w-28 shrink-0">{{ formatDate(`${day.work_date}T00:00:00Z`, { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }) }}</span>
                    <AppBadge :tone="dayTone(day.status)" dot>{{ t(`attendance.status.${day.status}`) }}</AppBadge>
                    <span class="flex-1 truncate text-muted">{{ day.employee_name }}</span>
                    <span class="tabular text-muted">{{ time(day.first_in_at) }} – {{ time(day.last_out_at) }}</span>
                    <span v-if="day.worked_minutes" class="tabular text-[12px] text-muted">{{ t('attendance.duration', durationParts(day.worked_minutes)) }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
