<script setup>
import { computed, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight, Download, Printer, TableProperties } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization } from '@/lib/session';
import { readPref, writePref } from '@/lib/storage';
import { useResource } from '@/lib/useResource';
import { formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { useAttendanceSetup } from '../setup';
import { addMonths, statusTone } from '../lib';

/**
 * A section's month as a register: one row per student (roll order), one
 * column per school day (days attendance was taken), each cell a letter on
 * its colour, and the month's totals. Downloads as CSV for the office and
 * prints on paper. The section is remembered.
 */
const api = attendanceApi(currentOrganization().id);
const setup = useAttendanceSetup();
const sectionId = ref(readPref('sta.register.section', ''));
const month = ref('');
watch(() => setup.today.value, (today) => { if (!month.value && today) month.value = today.slice(0, 7); }, { immediate: true });
watch(() => setup.sections.value, (sections) => {
    if (sections.length && !sections.some((section) => section.id === sectionId.value)) sectionId.value = sections[0].id;
}, { immediate: true });
const register = useResource(() => api.register(sectionId.value, month.value), { immediate: false });
watch([sectionId, month], ([section, value]) => {
    if (!section || !value) return;
    writePref('sta.register.section', section);
    register.reload();
}, { immediate: true });

const data = computed(() => register.data.value?.data ?? null);
const sectionName = (section) => [textIn(section.level ?? {}), section.name].filter(Boolean).join(' · ');
const CELL = {
    ok: 'bg-ok-soft text-ok',
    bad: 'bg-bad-soft text-bad',
    warn: 'bg-warn-soft text-warn',
    brand: 'bg-brand-soft text-brand-text',
};
const dayNumber = (date) => formatNumber(Number(date.slice(8, 10)));
// The CSV comes from the server (signed-in session), in the language of the screen.
const download = () => globalThis.location.assign(api.registerCsvUrl(sectionId.value, month.value));
const printIt = () => globalThis.print();
</script>

<template>
    <div>
        <PageHeader :title="t('student_attendance.register.title')" :description="t('student_attendance.register.text')">
            <template #actions>
                <AppButton :icon="Printer" class="print:hidden" :disabled="!data?.dates.length" @click="printIt">{{ t('student_attendance.register.print') }}</AppButton>
                <AppButton :icon="Download" class="print:hidden" :disabled="!data?.dates.length" @click="download">{{ t('student_attendance.register.csv') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-end gap-3 print:hidden">
            <AppField :label="t('student_attendance.register.section')" class="w-full sm:w-72">
                <template #default="{ id }">
                    <select :id="id" v-model="sectionId" class="field-input">
                        <option v-for="section in setup.sections.value" :key="section.id" :value="section.id">{{ sectionName(section) }}</option>
                    </select>
                </template>
            </AppField>
            <div class="flex items-center gap-1.5" role="group" :aria-label="t('student_attendance.register.month')">
                <AppButton size="icon" variant="ghost" :icon="ChevronLeft" :aria-label="t('student_attendance.register.previous_month')" @click="month = addMonths(month, -1)" />
                <input v-model="month" type="month" class="field-input w-auto" :aria-label="t('student_attendance.register.month')" />
                <AppButton size="icon" variant="ghost" :icon="ChevronRight" :aria-label="t('student_attendance.register.next_month')" @click="month = addMonths(month, 1)" />
            </div>
        </div>

        <SkeletonRows v-if="(setup.loading.value && !setup.data.value) || (register.loading.value && !register.data.value)" :rows="6" />
        <ErrorState v-else-if="setup.error.value || register.error.value" :error="setup.error.value || register.error.value" @retry="register.reload()" />
        <section v-else-if="!setup.sections.value.length" class="card">
            <EmptyState :icon="TableProperties" :title="t('student_attendance.today.no_sections_title')" :text="t('student_attendance.today.no_sections_text')" />
        </section>
        <section v-else-if="data && !data.dates.length" class="card">
            <EmptyState :icon="TableProperties" :title="t('student_attendance.register.empty_title')" :text="t('student_attendance.register.empty_text')" />
        </section>
        <section v-else-if="data" class="card overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <caption class="sr-only">{{ t('student_attendance.register.caption', { section: data.section.name, month }) }}</caption>
                <thead class="bg-subtle/60 text-[11.5px] text-muted">
                    <tr>
                        <th scope="col" class="sticky start-0 z-10 bg-subtle px-3 py-2 text-start font-medium">{{ t('student_attendance.register.student') }}</th>
                        <th v-for="date in data.dates" :key="date" scope="col" class="tabular w-8 px-1 py-2 text-center font-medium">{{ dayNumber(date) }}</th>
                        <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('student_attendance.short.present') }}</th>
                        <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('student_attendance.short.absent') }}</th>
                        <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('student_attendance.short.late') }}</th>
                        <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('student_attendance.short.leave') }}</th>
                        <th scope="col" class="px-3 py-2 text-end font-medium">{{ t('student_attendance.register.days') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="student in data.students" :key="student.id">
                        <th scope="row" class="sticky start-0 z-10 max-w-48 truncate bg-surface px-3 py-1.5 text-start font-medium text-fg">
                            <span class="tabular me-1.5 text-muted">{{ student.roll ? formatNumber(student.roll) : '–' }}</span>{{ student.name }}
                        </th>
                        <td v-for="date in data.dates" :key="date" class="p-0.5 text-center">
                            <span
                                v-if="student.days[date]"
                                class="grid h-6 w-7 place-items-center rounded text-[11px] font-semibold"
                                :class="CELL[statusTone(student.days[date])]"
                                :title="t(`student_attendance.status.${student.days[date]}`)"
                            >{{ t(`student_attendance.short.${student.days[date]}`) }}</span>
                            <span v-else class="text-faint" :aria-label="t('student_attendance.register.not_taken')">·</span>
                        </td>
                        <td class="tabular px-2 text-end">{{ formatNumber(student.totals.present) }}</td>
                        <td class="tabular px-2 text-end">{{ formatNumber(student.totals.absent) }}</td>
                        <td class="tabular px-2 text-end">{{ formatNumber(student.totals.late) }}</td>
                        <td class="tabular px-2 text-end">{{ formatNumber(student.totals.leave) }}</td>
                        <td class="tabular px-3 text-end font-medium">{{ formatNumber(student.totals.days) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="border-t border-line px-3 py-2 text-[12px] text-muted">{{ t('student_attendance.register.legend') }}</p>
        </section>
    </div>
</template>
