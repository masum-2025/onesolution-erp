<script setup>
import { computed, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { can, currentOrganization, session } from '@/lib/session';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { dayGrid, localDateTime, monthDates, shiftMonth, STATUSES } from '../lib';
import CorrectionDialog from '../components/CorrectionDialog.vue';
import DayDrawer from '../components/DayDrawer.vue';

/**
 * A month at a unit: one row per employee, one cell per day with a short
 * mark and colour (the legend spells each out). A cell opens that day.
 */
const org = currentOrganization();
const attendance = attendanceApi(org.id);
const today = localDateTime(session.me?.context?.settings?.timezone).slice(0, 10);

const month = ref(today.slice(0, 7));
const page = ref(1);
const dates = computed(() => monthDates(month.value, today));
const list = useResource(() => (dates.value.length
    ? attendance.days({ from: dates.value[0], to: dates.value[dates.value.length - 1], page: page.value })
    : Promise.resolve({ data: [], meta: { employees: 0, per_page: 50 } })));
const grid = computed(() => dayGrid(list.data.value?.data));
const people = computed(() => {
    const seen = new Map();
    for (const day of list.data.value?.data ?? []) if (!seen.has(day.employee_id)) seen.set(day.employee_id, { id: day.employee_id, name: day.employee_name, code: day.employee_code });
    return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name));
});
const pages = computed(() => Math.max(1, Math.ceil((list.data.value?.meta.employees ?? 0) / (list.data.value?.meta.per_page ?? 50))));
const attended = (id) => Object.values(grid.value[id] ?? {}).filter((day) => ['present', 'late', 'half_day', 'incomplete'].includes(day.status)).length;

watch([month, page], () => list.reload());

// Colour per status, always with its letter (never colour alone).
const CELL = {
    present: 'bg-ok-soft text-ok',
    late: 'bg-warn-soft text-warn',
    half_day: 'bg-warn-soft text-warn',
    incomplete: 'bg-warn-soft text-warn',
    absent: 'bg-bad-soft text-bad',
    weekend: 'bg-subtle text-muted',
    holiday: 'bg-subtle text-muted',
    pending: 'text-muted',
    no_shift: 'text-muted',
};

const open = ref(null);
const correcting = ref(null);
function openDay(person, date) {
    const day = grid.value[person.id]?.[date];
    if (day) open.value = { ...day, employee_name: person.name };
}
</script>

<template>
    <div>
        <PageHeader :title="t('attendance.month.title')" :description="t('attendance.month.text', { unit: org.name })">
            <template #actions>
                <div class="flex items-center gap-1">
                    <AppButton size="icon-sm" variant="ghost" :icon="ChevronLeft" :aria-label="t('attendance.common.previous_month')" @click="month = shiftMonth(month, -1); page = 1" />
                    <span class="min-w-32 text-center text-[14px] font-semibold">{{ formatDate(`${month}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' }) }}</span>
                    <AppButton size="icon-sm" variant="ghost" :icon="ChevronRight" :aria-label="t('attendance.common.next_month')" :disabled="month >= today.slice(0, 7)" @click="month = shiftMonth(month, 1); page = 1" />
                </div>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!people.length" :icon="Users" :title="t('attendance.today.empty')" :text="t('attendance.today.empty_text')" compact />
            <div v-else class="overflow-x-auto">
                <table class="w-max min-w-full border-separate border-spacing-0 text-[12px]">
                    <thead>
                        <tr>
                            <th class="sticky start-0 z-10 border-b border-line bg-surface px-4 py-2 text-start text-[12px] font-medium text-muted">{{ t('attendance.month.employee') }}</th>
                            <th v-for="date in dates" :key="date" class="border-b border-line px-0.5 py-2 text-center font-medium text-muted">
                                {{ formatNumber(Number(date.slice(8))) }}
                            </th>
                            <th class="border-b border-line px-3 py-2 text-end font-medium text-muted">{{ t('attendance.month.attended') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="person in people" :key="person.id">
                            <th scope="row" class="sticky start-0 z-10 max-w-48 truncate border-b border-line bg-surface px-4 py-1.5 text-start text-[13px] font-medium">{{ person.name }}</th>
                            <td v-for="date in dates" :key="date" class="border-b border-line p-0.5 text-center">
                                <button
                                    v-if="grid[person.id]?.[date]"
                                    type="button"
                                    class="grid size-7 place-items-center rounded-md text-[11px] font-semibold"
                                    :class="CELL[grid[person.id][date].status]"
                                    :aria-label="`${formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' })}: ${t(`attendance.status.${grid[person.id][date].status}`)}`"
                                    @click="openDay(person, date)"
                                >
                                    {{ t(`attendance.short.${grid[person.id][date].status}`) }}
                                </button>
                            </td>
                            <td class="tabular border-b border-line px-3 py-1.5 text-end font-semibold">{{ formatNumber(attended(person.id)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line px-5 py-3 text-[12px] text-muted">
                <span v-for="status in STATUSES" :key="status" class="flex items-center gap-1.5">
                    <span class="grid size-5 place-items-center rounded text-[10px] font-semibold" :class="CELL[status]">{{ t(`attendance.short.${status}`) }}</span>
                    {{ t(`attendance.status.${status}`) }}
                </span>
                <span class="flex-1"></span>
                <template v-if="pages > 1">
                    <AppButton size="sm" variant="ghost" :disabled="page <= 1" @click="page--">{{ t('attendance.common.previous') }}</AppButton>
                    <span>{{ t('attendance.common.page', { page: formatNumber(page), pages: formatNumber(pages) }) }}</span>
                    <AppButton size="sm" variant="ghost" :disabled="page >= pages" @click="page++">{{ t('attendance.common.next') }}</AppButton>
                </template>
            </footer>
        </section>

        <DayDrawer :day="open" :attendance="attendance" :can-manage="can('attendance.manage')" @close="open = null" @changed="list.reload()" @correct="(day) => { correcting = day; open = null; }" />
        <CorrectionDialog
            :open="correcting !== null"
            :date="correcting?.work_date ?? ''"
            :employee-name="correcting?.employee_name ?? ''"
            :send="(body) => attendance.askFor({ ...body, employee_id: correcting.employee_id })"
            @close="correcting = null"
            @sent="correcting = null"
        />
    </div>
</template>
