<script setup>
import { computed, ref, watch } from 'vue';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { leaveApi } from '../api';
import { covers, monthDays, shiftMonth } from '../lib';

/**
 * Who is away in a month at the unit: one row per person with leave that
 * month, a cell per day. Approved leave is a filled mark "A", waiting leave
 * an outlined "W" (letters and a legend, never colour alone).
 */
const org = currentOrganization();
const leave = leaveApi(org.id);
const month = ref(new Date().toISOString().slice(0, 7));
const days = computed(() => monthDays(month.value));
const list = useResource(() => leave.requests({ from: days.value[0], to: days.value[days.value.length - 1] }));
watch(month, () => list.reload());

const active = computed(() => (list.data.value?.data ?? []).filter((item) => ['approved', 'pending'].includes(item.status)));
const people = computed(() => {
    const rows = new Map();
    for (const item of active.value) {
        if (!rows.has(item.employee_id)) rows.set(item.employee_id, { id: item.employee_id, name: item.employee_name, requests: [] });
        rows.get(item.employee_id).requests.push(item);
    }
    return [...rows.values()].sort((a, b) => a.name.localeCompare(b.name));
});

function cell(person, date) {
    const found = person.requests.find((item) => covers(item, date));
    return found ? { status: found.status, title: `${found.type_name} · ${t(`leave.status.${found.status}`)}` } : null;
}

const title = computed(() => formatDate(`${month.value}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' }));
</script>

<template>
    <div>
        <PageHeader :title="t('leave.calendar.title')" :description="t('leave.calendar.text')">
            <template #actions>
                <div class="flex items-center gap-1">
                    <AppButton size="icon-sm" variant="ghost" :icon="ChevronLeft" :aria-label="t('leave.calendar.previous')" @click="month = shiftMonth(month, -1)" />
                    <span class="min-w-36 text-center text-[14px] font-medium" aria-live="polite">{{ title }}</span>
                    <AppButton size="icon-sm" variant="ghost" :icon="ChevronRight" :aria-label="t('leave.calendar.next')" @click="month = shiftMonth(month, 1)" />
                </div>
            </template>
        </PageHeader>

        <section class="card overflow-hidden">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!people.length" :icon="CalendarDays" :title="t('leave.calendar.empty')" compact />
            <div v-else class="overflow-x-auto">
                <table class="w-full border-collapse text-[12px]">
                    <thead>
                        <tr>
                            <th scope="col" class="sticky start-0 z-10 min-w-40 bg-surface px-4 py-2 text-start font-medium text-muted">{{ t('leave.calendar.person') }}</th>
                            <th v-for="date in days" :key="date" scope="col" class="min-w-7 px-0.5 py-2 text-center font-medium tabular text-muted">
                                {{ formatNumber(Number(date.slice(8))) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="person in people" :key="person.id" class="border-t border-line">
                            <th scope="row" class="sticky start-0 z-10 max-w-48 truncate bg-surface px-4 py-2 text-start text-[13px] font-medium">{{ person.name }}</th>
                            <td v-for="date in days" :key="date" class="px-0.5 py-1.5 text-center">
                                <span
                                    v-if="cell(person, date)"
                                    class="mx-auto grid size-6 place-items-center rounded-md text-[11px] font-semibold"
                                    :class="cell(person, date).status === 'approved' ? 'bg-brand text-brand-fg' : 'border border-dashed border-warn text-warn'"
                                    :title="cell(person, date).title"
                                >
                                    {{ cell(person, date).status === 'approved' ? t('leave.calendar.approved_short') : t('leave.calendar.waiting_short') }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="flex flex-wrap gap-x-5 gap-y-1 border-t border-line px-5 py-3 text-[12.5px] text-muted">
                <span class="inline-flex items-center gap-1.5"><span class="grid size-5 place-items-center rounded bg-brand text-[10px] font-semibold text-brand-fg">{{ t('leave.calendar.approved_short') }}</span>{{ t('leave.status.approved') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="grid size-5 place-items-center rounded border border-dashed border-warn text-[10px] font-semibold text-warn">{{ t('leave.calendar.waiting_short') }}</span>{{ t('leave.status.pending') }}</span>
                <span>{{ t('leave.calendar.days_off_note') }}</span>
            </p>
        </section>
    </div>
</template>
