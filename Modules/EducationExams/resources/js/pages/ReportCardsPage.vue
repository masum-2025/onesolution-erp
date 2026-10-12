<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, FileText, Printer } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import ReportCard from '../components/ReportCard.vue';
import TabulationSheet from '../components/TabulationSheet.vue';

/**
 * Report cards (one A4 page each) or a tabulation sheet (A4 landscape) of
 * an exam's or a final result's students, a section or one student at a
 * time, ready to print. Only students whose whole result the reader may see.
 */
const route = useRoute();
const router = useRouter();
const api = examsApi(currentOrganization().id);
const type = computed(() => (route.params.type === 'combination' ? 'combination' : 'exam'));

// Sections to choose from (the results list names them).
const list = useResource(() => (type.value === 'exam' ? api.results(route.params.id) : api.combinationResults(route.params.id)));
const sections = computed(() => Object.entries(list.data.value?.meta?.sections ?? {}).map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name)));
const section = ref(route.query.section ?? '');
const student = computed(() => route.query.student ?? '');
watch(sections, (items) => {
    if (!section.value && !student.value && items.length) section.value = items[0].id;
}, { immediate: true });
const layout = ref(route.query.layout === 'tabulation' ? 'tabulation' : 'cards');
const layouts = computed(() => [{ value: 'cards', label: t('education_exams.cards.cards') }, { value: 'tabulation', label: t('education_exams.cards.tabulation') }]);
watch([section, layout], () => router.replace({ query: { ...route.query, section: section.value || undefined, layout: layout.value } }));

const query = () => ({ ...(student.value ? { student_id: student.value } : {}), ...(section.value && !student.value ? { section_id: section.value } : {}) });
const cards = useResource(() => (type.value === 'exam' ? api.reportCards(route.params.id, query()) : api.combinationReportCards(route.params.id, query())), { immediate: false });
watch([section, student], () => (section.value || student.value || !sections.value.length) && cards.reload(), { immediate: true });
const data = computed(() => cards.data.value?.data ?? null);
const back = computed(() => (type.value === 'exam' ? { name: 'exams-exam', params: { id: route.params.id }, query: { tab: 'results' } } : { name: 'exams-combination', params: { id: route.params.id } }));
const printPage = () => window.print();
const pageCss = computed(() => `@media print { @page { size: A4 ${layout.value === 'tabulation' ? 'landscape' : 'portrait'}; margin: 12mm; } }`);
</script>

<template>
    <div>
        <component :is="'style'">{{ pageCss }}</component>
        <div class="mb-4 flex flex-wrap items-center gap-3 print:hidden">
            <RouterLink :to="back" class="inline-flex items-center gap-1.5 text-[13px] text-muted hover:text-fg">
                <ArrowLeft class="size-4 rtl:rotate-180" aria-hidden="true" />{{ data ? textIn(data.source.name) : t('education_exams.results.title') }}
            </RouterLink>
            <div class="ms-auto flex flex-wrap items-center gap-2">
                <label v-if="!student && sections.length" class="w-40">
                    <span class="sr-only">{{ t('education_exams.results.section') }}</span>
                    <select v-model="section" class="field-input">
                        <option v-for="item in sections" :key="item.id" :value="item.id">{{ item.name }}</option>
                    </select>
                </label>
                <AppSegmented v-if="!student" v-model="layout" :options="layouts" :label="t('education_exams.cards.layout')" size="sm" />
                <AppButton variant="primary" :icon="Printer" :disabled="!data?.students.length" @click="printPage">{{ t('education_exams.cards.print') }}</AppButton>
            </div>
        </div>
        <p v-if="data?.students.length" class="mb-3 text-[12.5px] text-muted print:hidden">{{ t('education_exams.cards.count', { count: formatNumber(data.students.length) }) }} · {{ t('education_exams.cards.print_hint') }}</p>

        <SkeletonRows v-if="(cards.loading.value || list.loading.value) && !data" :rows="8" />
        <ErrorState v-else-if="cards.error.value" :error="cards.error.value" @retry="cards.reload()" />
        <div v-else-if="data && !data.students.length" class="card"><EmptyState :icon="FileText" :title="t('education_exams.cards.empty')" :text="t('education_exams.cards.empty_text')" /></div>
        <template v-else-if="data">
            <TabulationSheet v-if="layout === 'tabulation' && !student" :data="data" />
            <div v-else class="space-y-6 print:space-y-0">
                <ReportCard v-for="card in data.students" :key="card.result_id" :card="card" :data="data" class="break-after-page last:break-after-auto" />
            </div>
        </template>
    </div>
</template>
