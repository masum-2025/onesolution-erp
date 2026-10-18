<script setup>
import { computed, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { CalendarOff, Check, ChevronLeft, ChevronRight, ClipboardList, CloudOff, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization } from '@/lib/session';
import { offline } from '@/lib/offline';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { useAttendanceSetup } from '../setup';
import { addDays } from '../lib';

/**
 * A day's attendance at a glance: each section the person sees, whether
 * its attendance is taken (with how many were absent, late, on leave) or
 * still to take, and, by period, each period's state. A teacher sees their
 * own sections and taps one to take it; the office sees who has not taken
 * it yet. Days to come cannot be opened.
 */
const route = useRoute();
const router = useRouter();
const api = attendanceApi(currentOrganization().id);
const setup = useAttendanceSetup();
const date = ref(typeof route.query.date === 'string' ? route.query.date : '');
watch(() => setup.today.value, (today) => { if (!date.value && today) date.value = today; }, { immediate: true });
const day = useResource(() => api.day(date.value), { immediate: false });
watch(date, (value) => {
    if (!value) return;
    router.replace({ query: value === setup.today.value ? {} : { date: value } });
    day.reload();
}, { immediate: true });

const isToday = computed(() => date.value === setup.today.value);
const bySection = computed(() => Object.fromEntries((day.data.value?.data ?? []).map((row) => [row.section_id, row])));
const periodsOf = (unitId) => setup.periods.value.filter((period) => period.unit_id === unitId);
const cards = computed(() => setup.sections.value.map((section) => {
    const row = bySection.value[section.id] ?? { sheets: [], day_off: { weekend: false, holiday: null } };
    const daySheet = row.sheets.find((sheet) => sheet.slot === 'day') ?? null;
    const periods = section.by_period ? periodsOf(section.unit_id).map((period) => ({ ...period, sheet: row.sheets.find((sheet) => sheet.period_id === period.id) ?? null })) : [];
    const mayTake = setup.can('manage') || section.class_teacher || (section.by_period && section.my_subjects.length > 0);
    const taken = section.by_period ? periods.length > 0 && periods.every((period) => period.sheet) : daySheet !== null;
    const absent = section.by_period ? null : daySheet?.counts.absent ?? null;
    return { section, row, daySheet, periods, mayTake, taken, absent };
}));
const takenCount = computed(() => cards.value.filter((card) => card.taken).length);
const offDay = computed(() => cards.value.find((card) => card.row.day_off.weekend || card.row.day_off.holiday)?.row.day_off ?? null);
const sheetLink = (sectionId, periodId = null) => ({ name: 'student-attendance-sheet', params: { section: sectionId, date: date.value }, query: periodId ? { period: periodId } : {} });
const sectionName = (section) => [textIn(section.level ?? {}), section.name].filter(Boolean).join(' · ');
</script>

<template>
    <div>
        <PageHeader :title="t('student_attendance.today.title')" :description="t('student_attendance.today.text')">
            <template #actions>
                <div class="flex items-center gap-1.5" role="group" :aria-label="t('student_attendance.today.day')">
                    <AppButton size="icon" variant="ghost" :icon="ChevronLeft" :aria-label="t('student_attendance.today.previous_day')" @click="date = addDays(date, -1)" />
                    <input v-model="date" type="date" class="field-input w-auto" :max="setup.today.value" :aria-label="t('student_attendance.today.day')" />
                    <AppButton size="icon" variant="ghost" :icon="ChevronRight" :aria-label="t('student_attendance.today.next_day')" :disabled="isToday" @click="date = addDays(date, 1)" />
                    <AppButton v-if="!isToday" size="sm" @click="date = setup.today.value">{{ t('student_attendance.today.back_to_today') }}</AppButton>
                </div>
            </template>
        </PageHeader>

        <p v-if="offline.pending > 0" class="mb-4 flex items-center gap-2 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn" role="status">
            <CloudOff class="size-4 shrink-0" aria-hidden="true" />{{ t('student_attendance.today.waiting_offline', { count: formatNumber(offline.pending) }) }}
        </p>
        <p v-if="offDay" class="mb-4 flex items-center gap-2 rounded-xl bg-subtle px-4 py-3 text-[13px] text-fg-2" role="note">
            <CalendarOff class="size-4 shrink-0" aria-hidden="true" />
            {{ offDay.holiday ? t('student_attendance.day_off.holiday', { name: textIn(offDay.holiday) }) : t('student_attendance.day_off.weekend') }}
        </p>

        <SkeletonRows v-if="(setup.loading.value && !setup.data.value) || (day.loading.value && !day.data.value)" :rows="4" />
        <ErrorState v-else-if="setup.error.value || day.error.value" :error="setup.error.value || day.error.value" @retry="setup.reload(); day.reload()" />
        <section v-else-if="!cards.length" class="card">
            <EmptyState :icon="ClipboardList" :title="t('student_attendance.today.no_sections_title')" :text="t('student_attendance.today.no_sections_text')" />
        </section>
        <template v-else>
            <p class="mb-3 text-[13px] text-muted">
                {{ t('student_attendance.today.summary', { taken: formatNumber(takenCount), total: formatNumber(cards.length), date: formatDate(date) }) }}
            </p>
            <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="card in cards" :key="card.section.id" class="card flex flex-col gap-3 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-fg">{{ sectionName(card.section) }}</p>
                            <p class="mt-0.5 flex items-center gap-1 text-[12.5px] text-muted">
                                <Users class="size-3.5" aria-hidden="true" />{{ t('student_attendance.today.students', { count: formatNumber(card.section.students) }) }}
                            </p>
                        </div>
                        <AppBadge v-if="card.taken" tone="ok" :icon="Check">{{ t('student_attendance.today.taken') }}</AppBadge>
                        <AppBadge v-else tone="warn" dot>{{ t('student_attendance.today.pending') }}</AppBadge>
                    </div>

                    <!-- Once a day: the counts, or the button to take it. -->
                    <template v-if="!card.section.by_period">
                        <p v-if="card.daySheet" class="flex flex-wrap gap-1.5 text-[12.5px]">
                            <AppBadge tone="ok">{{ t('student_attendance.short.present') }} {{ formatNumber(card.daySheet.counts.present) }}</AppBadge>
                            <AppBadge tone="bad">{{ t('student_attendance.short.absent') }} {{ formatNumber(card.daySheet.counts.absent) }}</AppBadge>
                            <AppBadge v-if="card.daySheet.counts.late" tone="warn">{{ t('student_attendance.short.late') }} {{ formatNumber(card.daySheet.counts.late) }}</AppBadge>
                            <AppBadge v-if="card.daySheet.counts.leave" tone="brand">{{ t('student_attendance.short.leave') }} {{ formatNumber(card.daySheet.counts.leave) }}</AppBadge>
                        </p>
                        <AppButton class="mt-auto" :to="sheetLink(card.section.id)" :variant="card.daySheet || !card.mayTake ? 'secondary' : 'primary'" block :icon="ClipboardList">
                            {{ !card.daySheet && card.mayTake ? t('student_attendance.today.take') : t('student_attendance.today.open') }}
                        </AppButton>
                    </template>

                    <!-- By period: each period, taken or not; tap one to open it. -->
                    <template v-else>
                        <p v-if="!card.periods.length" class="text-[12.5px] text-muted">{{ t('student_attendance.today.no_periods') }}</p>
                        <div v-else class="flex flex-wrap gap-1.5" role="list" :aria-label="t('student_attendance.today.periods')">
                            <RouterLink
                                v-for="period in card.periods"
                                :key="period.id"
                                role="listitem"
                                :to="sheetLink(card.section.id, period.id)"
                                class="inline-flex min-w-11 items-center justify-center gap-1 rounded-lg px-2.5 py-1.5 text-[12.5px] font-medium ring-1 transition-colors"
                                :class="period.sheet ? 'bg-ok-soft text-ok ring-ok/25 hover:bg-ok/15' : 'bg-subtle text-fg-2 ring-line hover:bg-hover'"
                                :title="textIn(period.name)"
                                :aria-label="`${textIn(period.name)}: ${period.sheet ? t('student_attendance.today.taken') : t('student_attendance.today.pending')}`"
                            >
                                <Check v-if="period.sheet" class="size-3.5" aria-hidden="true" />{{ formatNumber(period.number) }}
                            </RouterLink>
                        </div>
                    </template>
                </li>
            </ul>
        </template>
    </div>
</template>
