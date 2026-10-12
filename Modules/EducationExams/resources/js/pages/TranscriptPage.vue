<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { FileText, GraduationCap, ScrollText, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { marksShown, pointShown } from '../labels';

/**
 * A student's published results, newest first, each with its subjects,
 * and the cumulative grade point. Find a student by name, ID or phone;
 * teachers find only students whose whole result they may see.
 */
const route = useRoute();
const router = useRouter();
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();

const query = ref('');
const found = ref([]);
const searching = ref(false);
const searchError = ref(null);
let timer = null;
watch(query, (text) => {
    clearTimeout(timer);
    searchError.value = null;
    if (text.trim().length < 2) {
        found.value = [];
        return;
    }
    timer = setTimeout(async () => {
        searching.value = true;
        try {
            found.value = (await api.findStudents(text.trim())).data;
        } catch (error) {
            searchError.value = error;
        } finally {
            searching.value = false;
        }
    }, 300);
});
function choose(student) {
    query.value = '';
    found.value = [];
    router.push({ name: 'exams-transcript', params: { id: student.id } });
}

const transcript = useResource(() => (route.params.id ? api.transcript(route.params.id) : Promise.resolve(null)), { immediate: false });
watch(() => route.params.id, () => transcript.reload(), { immediate: true });
const data = computed(() => transcript.data.value?.data ?? null);
const subjectLabel = (id) => {
    const subject = setup.data.value?.subjects.find((item) => item.id === id);
    return subject ? `${textIn(subject.name)} (${subject.code})` : '—';
};
const published = (iso) => (iso ? formatDate(iso, { dateStyle: 'medium' }) : '');
</script>

<template>
    <div>
        <PageHeader :title="t('education_exams.transcript.title')" :description="t('education_exams.transcript.text')" />

        <div class="relative mb-5 sm:w-96">
            <label>
                <span class="sr-only">{{ t('education_exams.transcript.search') }}</span>
                <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                <input v-model="query" type="search" class="field-input ps-9" :placeholder="t('education_exams.transcript.search')" autocomplete="off" />
            </label>
            <div v-if="query.trim().length >= 2" class="absolute inset-x-0 top-full z-20 mt-1 overflow-hidden rounded-lg border border-line bg-raised shadow-lg">
                <p v-if="searching" class="px-4 py-3 text-[13px] text-muted">{{ t('education_exams.transcript.searching') }}</p>
                <p v-else-if="searchError" class="px-4 py-3 text-[13px] text-bad">{{ searchError.message }}</p>
                <p v-else-if="!found.length" class="px-4 py-3 text-[13px] text-muted">{{ t('education_exams.transcript.none_found') }}</p>
                <ul v-else class="max-h-72 divide-y divide-line overflow-y-auto">
                    <li v-for="student in found" :key="student.id">
                        <button type="button" class="block w-full px-4 py-2.5 text-start hover:bg-subtle" @click="choose(student)">
                            <span class="block text-[14px] text-fg">{{ student.name }}</span>
                            <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ student.code }}</span>
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="!route.params.id" class="card">
            <EmptyState :icon="ScrollText" :title="t('education_exams.transcript.pick')" :text="t('education_exams.transcript.pick_text')" />
        </div>
        <SkeletonRows v-else-if="transcript.loading.value && !data" :rows="6" />
        <ErrorState v-else-if="transcript.error.value" :error="transcript.error.value" @retry="transcript.reload()" />
        <template v-else-if="data">
            <section class="card mb-5 flex flex-wrap items-center gap-x-6 gap-y-3 px-5 py-4">
                <span class="min-w-0 flex-1">
                    <span class="block text-[17px] font-semibold text-fg">{{ data.student.name }}</span>
                    <span class="block text-[13px] text-muted"><span class="font-mono" dir="ltr">{{ data.student.code }}</span><template v-if="data.student.name_local"> · {{ data.student.name_local }}</template></span>
                </span>
                <span class="text-end">
                    <span class="block text-[12px] text-muted">{{ t('education_exams.transcript.cgpa') }}</span>
                    <span class="tabular block text-[26px] font-bold leading-tight text-fg">{{ data.cgpa_centi === null ? '—' : pointShown(data.cgpa_centi) }}</span>
                </span>
            </section>
            <p v-if="data.cgpa_centi !== null" class="-mt-3 mb-4 text-[12px] text-faint">{{ t('education_exams.transcript.cgpa_how') }}</p>

            <div v-if="!data.results.length" class="card"><EmptyState :icon="GraduationCap" compact :title="t('education_exams.transcript.empty')" /></div>
            <div v-else class="space-y-4">
                <section v-for="result in data.results" :key="result.id" class="card overflow-hidden">
                    <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line px-5 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-semibold text-fg">{{ textIn(result.name) }}</span>
                            <span class="block text-[12px] text-muted">{{ setup.sessionName(result.session_id) }} · {{ setup.levelName(result.level_id) }}<template v-if="result.published_at"> · {{ t('education_exams.transcript.published_on', { date: published(result.published_at) }) }}</template></span>
                        </span>
                        <AppBadge v-if="result.source_type === 'combination'" tone="brand">{{ t('education_exams.transcript.final') }}</AppBadge>
                        <span v-if="data.show_rank && result.rank" class="tabular text-[12.5px] text-muted">{{ t('education_exams.transcript.position', { rank: formatNumber(result.rank) }) }}</span>
                        <span class="inline-flex items-baseline gap-1.5">
                            <span class="text-[17px] font-bold" :class="result.passed ? 'text-fg' : 'text-bad'">{{ result.letter ?? '—' }}</span>
                            <span class="tabular text-[13px] text-muted">{{ pointShown(result.gpa_centi) }}</span>
                        </span>
                        <RouterLink :to="{ name: 'exams-report-cards', params: { type: result.source_type, id: result.source_id }, query: { student: data.student.id } }" class="inline-flex items-center gap-1 text-[12.5px] font-medium text-brand-text hover:underline print:hidden">
                            <FileText class="size-3.5" aria-hidden="true" />{{ t('education_exams.cards.one') }}
                        </RouterLink>
                    </header>
                    <table class="w-full text-[13px]">
                        <thead class="bg-subtle/60 text-[11.5px] text-muted">
                            <tr>
                                <th scope="col" class="px-5 py-1.5 text-start font-medium">{{ t('education_exams.paper.subject') }}</th>
                                <th scope="col" class="px-3 py-1.5 text-end font-medium">{{ t('education_exams.paper.marks') }}</th>
                                <th scope="col" class="px-3 py-1.5 text-center font-medium">{{ t('education_exams.grading.letter') }}</th>
                                <th scope="col" class="px-5 py-1.5 text-end font-medium">{{ t('education_exams.grading.point') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="subject in result.subjects" :key="subject.subject_id">
                                <td class="px-5 py-1.5">{{ subjectLabel(subject.subject_id) }}<span v-if="subject.kind === 'optional'" class="text-[11px] text-muted"> · {{ t('education_exams.paper_kinds.optional') }}</span></td>
                                <td class="tabular px-3 py-1.5 text-end">
                                    <span v-if="subject.absent" class="text-warn">{{ t('education_exams.entry.absent') }}</span>
                                    <template v-else>{{ marksShown(subject.marks_centi) }}<span class="text-faint">/{{ marksShown(subject.full_marks_centi) }}</span></template>
                                </td>
                                <td class="px-3 py-1.5 text-center font-semibold" :class="subject.passed ? 'text-fg' : 'text-bad'">{{ subject.letter ?? '—' }}</td>
                                <td class="tabular px-5 py-1.5 text-end">{{ pointShown(subject.point_centi) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </template>
    </div>
</template>
