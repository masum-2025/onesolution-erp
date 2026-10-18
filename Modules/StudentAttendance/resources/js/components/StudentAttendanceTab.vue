<script setup>
import { computed, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization } from '@/lib/session';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { addMonths, holidayOn, monthDays, statusTone, weekdayKey } from '../lib';

/**
 * The attendance tab of a student's page in Education: a month as a
 * calendar (each school day's state as a letter on its colour, days off
 * marked), and the month's totals with the share of days present.
 */
const props = defineProps({ studentId: { type: String, required: true } });
const api = attendanceApi(currentOrganization().id);
const month = ref(new Date().toISOString().slice(0, 7));
const view = useResource(() => api.student(props.studentId, month.value), { immediate: false });
watch(() => [props.studentId, month.value], () => view.reload(), { immediate: true });

const data = computed(() => view.data.value?.data ?? null);
// Weeks start on Saturday, as school weeks do where the weekend is Friday.
const WEEK = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];
const cells = computed(() => {
    if (!data.value) return [];
    const byDate = Object.fromEntries(data.value.days.map((day) => [day.date, day]));
    const days = monthDays(month.value);
    const blanks = WEEK.indexOf(weekdayKey(days[0]));
    return [
        ...Array.from({ length: blanks }, (_, index) => ({ key: `blank-${index}` })),
        ...days.map((date) => ({
            key: date, date, day: byDate[date] ?? null,
            off: (data.value.weekend_days ?? []).includes(weekdayKey(date)) || holidayOn(data.value.holidays ?? [], date) !== null,
            holiday: holidayOn(data.value.holidays ?? [], date),
        })),
    ];
});
const share = computed(() => (data.value?.totals.days ? Math.round((data.value.totals.present / data.value.totals.days) * 100) : null));
const CELL = { ok: 'bg-ok-soft text-ok ring-ok/25', bad: 'bg-bad-soft text-bad ring-bad/25', warn: 'bg-warn-soft text-warn ring-warn/30', brand: 'bg-brand-soft text-brand-text ring-brand/25' };
const cellLabel = (cell) => [formatDate(cell.date, { dateStyle: 'full' }), cell.day ? t(`student_attendance.status.${cell.day.status}`) : cell.holiday ? textIn(cell.holiday.name) : cell.off ? t('student_attendance.day_off.short') : t('student_attendance.register.not_taken')].join(': ');
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between gap-2">
            <AppButton size="icon-sm" variant="ghost" :icon="ChevronLeft" :aria-label="t('student_attendance.register.previous_month')" @click="month = addMonths(month, -1)" />
            <p class="font-semibold text-fg">{{ formatDate(`${month}-01`, { month: 'long', year: 'numeric' }) }}</p>
            <AppButton size="icon-sm" variant="ghost" :icon="ChevronRight" :aria-label="t('student_attendance.register.next_month')" @click="month = addMonths(month, 1)" />
        </div>

        <SkeletonRows v-if="view.loading.value && !view.data.value" :rows="4" />
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <div v-else-if="data" class="grid items-start gap-3 lg:grid-cols-[minmax(0,26rem)_1fr]">
            <div class="card p-3">
                <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-medium text-muted" aria-hidden="true">
                    <span v-for="day in WEEK" :key="day">{{ t(`student_attendance.weekdays_short.${day}`) }}</span>
                </div>
                <ol class="mt-1 grid grid-cols-7 gap-1" :aria-label="t('student_attendance.student_tab.calendar')">
                    <li v-for="cell in cells" :key="cell.key" :aria-hidden="!cell.date" :aria-label="cell.date ? cellLabel(cell) : undefined">
                        <span v-if="cell.date" class="flex aspect-square flex-col items-center justify-center rounded-lg text-[11px] ring-1" :class="cell.day ? CELL[statusTone(cell.day.status)] : cell.off ? 'bg-subtle text-faint ring-transparent' : 'text-fg-2 ring-line'" :title="cellLabel(cell)">
                            <span class="tabular">{{ formatNumber(Number(cell.date.slice(8))) }}</span>
                            <span v-if="cell.day" class="text-[10px] font-semibold">{{ t(`student_attendance.short.${cell.day.status}`) }}</span>
                        </span>
                    </li>
                </ol>
            </div>
            <dl class="grid grid-cols-3 gap-2 text-center sm:grid-cols-6 lg:grid-cols-2">
                <div class="card p-2"><dt class="text-[11.5px] text-muted">{{ t('student_attendance.status.present') }}</dt><dd class="tabular text-[17px] font-semibold text-ok">{{ formatNumber(data.totals.present) }}</dd></div>
                <div class="card p-2"><dt class="text-[11.5px] text-muted">{{ t('student_attendance.status.absent') }}</dt><dd class="tabular text-[17px] font-semibold text-bad">{{ formatNumber(data.totals.absent) }}</dd></div>
                <div class="card p-2"><dt class="text-[11.5px] text-muted">{{ t('student_attendance.status.late') }}</dt><dd class="tabular text-[17px] font-semibold text-warn">{{ formatNumber(data.totals.late) }}</dd></div>
                <div class="card p-2"><dt class="text-[11.5px] text-muted">{{ t('student_attendance.status.leave') }}</dt><dd class="tabular text-[17px] font-semibold text-brand-text">{{ formatNumber(data.totals.leave) }}</dd></div>
                <div class="card p-2"><dt class="text-[11.5px] text-muted">{{ t('student_attendance.register.days') }}</dt><dd class="tabular text-[17px] font-semibold text-fg">{{ formatNumber(data.totals.days) }}</dd></div>
                <div class="card p-2"><dt class="text-[11.5px] text-muted">{{ t('student_attendance.student_tab.share') }}</dt><dd class="tabular text-[17px] font-semibold text-fg">{{ share === null ? '—' : `${formatNumber(share)}%` }}</dd></div>
            </dl>
        </div>
    </div>
</template>
