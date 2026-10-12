<script setup>
import { computed } from 'vue';
import { ChevronRight, ClipboardCheck } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { sheetTone } from '../lib';
import { dateRange, day, subjectName } from '../labels';

/**
 * A teacher's own mark sheets in every exam open for marks: the ones to
 * enter (and those sent back) first, then the ones handed in. Named on a
 * sheet, or the class teacher of one that names nobody.
 */
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();
const list = useResource(() => api.mySheets());

const ORDER = { returned: 0, open: 1, submitted: 2, approved: 3 };
const byExam = computed(() => {
    const groups = new Map();
    for (const sheet of list.data.value?.data ?? []) {
        if (!groups.has(sheet.exam.id)) groups.set(sheet.exam.id, { exam: sheet.exam, rows: [] });
        groups.get(sheet.exam.id).rows.push(sheet);
    }
    return [...groups.values()].map((group) => ({
        ...group,
        todo: group.rows.filter((sheet) => ['open', 'returned'].includes(sheet.status)).length,
        rows: group.rows.sort((a, b) => ORDER[a.status] - ORDER[b.status] || (a.paper.sits_on ?? '').localeCompare(b.paper.sits_on ?? '')),
    }));
});
</script>

<template>
    <div>
        <PageHeader :title="t('education_exams.mine.title')" :description="t('education_exams.mine.text')" />

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <div v-else-if="!byExam.length" class="card">
            <EmptyState :icon="ClipboardCheck" :title="t('education_exams.mine.empty')" :text="setup.data.value && !setup.data.value.hrm ? t('education_exams.mine.empty_no_hrm') : t('education_exams.mine.empty_text')" />
        </div>
        <div v-else class="space-y-5">
            <section v-for="group in byExam" :key="group.exam.id" class="card">
                <header class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                    <h2 class="flex-1 text-[13.5px] font-semibold text-fg">{{ textIn(group.exam.name) }}</h2>
                    <span v-if="group.exam.starts_on" class="text-[12px] text-muted">{{ dateRange(group.exam.starts_on, group.exam.ends_on) }}</span>
                    <AppBadge v-if="group.todo" tone="warn">{{ t('education_exams.mine.todo', { count: group.todo }) }}</AppBadge>
                    <AppBadge v-else tone="ok">{{ t('education_exams.mine.all_in') }}</AppBadge>
                </header>
                <ul class="divide-y divide-line">
                    <li v-for="sheet in group.rows" :key="sheet.id">
                        <RouterLink :to="{ name: 'exams-sheet', params: { id: sheet.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 transition hover:bg-subtle/60">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium text-fg">{{ subjectName(sheet.paper.subject) }}</span>
                                <span class="block text-[12.5px] text-muted">
                                    {{ setup.levelName(sheet.section?.level_id) }} · {{ sheet.section?.name ?? '—' }}
                                    <template v-if="sheet.paper.sits_on"> · {{ day(sheet.paper.sits_on) }}</template>
                                </span>
                                <span v-if="sheet.status === 'returned' && sheet.note" class="mt-0.5 block text-[12.5px] text-bad">{{ t('education_exams.sheets.returned_note', { note: sheet.note }) }}</span>
                            </span>
                            <AppBadge :tone="sheetTone[sheet.status]" dot>{{ t(`education_exams.sheet_statuses.${sheet.status}`) }}</AppBadge>
                            <ChevronRight class="size-4 text-faint rtl:rotate-180" aria-hidden="true" />
                        </RouterLink>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
