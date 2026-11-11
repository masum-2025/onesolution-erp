<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, FileText, Printer } from 'lucide-vue-next';
import { AppButton, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate, formatDateTime } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { loadNamespaces, t, tIn } from '@/lib/i18n';
import { textIn } from '@/lib/texts';
import { ipdApi } from '../api';

/**
 * The discharge paper on A4: the hospital, the patient and the stay (number,
 * bed, admitted and discharged), diagnosis, treatment, advice, follow-up and
 * outcome, signed. Headings print in the rule's language: Bangla, English or
 * both. Only the signed summary in force.
 */
const ipd = ipdApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const view = useResource(() => ipd.dischargePaper(route.params.id));
const paper = computed(() => view.data.value?.data ?? null);
const organization = computed(() => currentOrganization()?.name ?? '');
const languages = computed(() => (paper.value?.language === 'both' ? ['bn', 'en'] : [paper.value?.language ?? 'bn']));
const ready = ref(false);
watch(languages, async (list) => {
    await Promise.all(list.map((locale) => loadNamespaces(['inpatient'], locale)));
    ready.value = true;
}, { immediate: true });
// A heading in the paper's language(s): "উপদেশ / Advice".
const say = (key) => languages.value.map((locale) => tIn(locale, key)).join(' / ');
const sections = ['diagnosis', 'treatment', 'advice'];
const printPaper = () => window.print();
</script>

<template>
    <PageFrame :title="paper ? t('inpatient.paper.title', { number: paper.number }) : ''" :resource="view" width="md" own-paper
               :not-found="{ icon: FileText, title: t('inpatient.paper.not_found') }">
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" class="print:hidden" @click="router.push({ name: 'inpatient-admission', params: { id: route.params.id }, query: { tab: 'discharge' } })">{{ t('inpatient.paper.back') }}</AppButton>
        </template>
        <template v-if="paper" #actions>
            <AppButton variant="primary" :icon="Printer" @click="printPaper">{{ t('inpatient.discharge.print') }}</AppButton>
        </template>
        <article v-if="paper && ready" class="sheet mx-auto w-full max-w-[210mm] bg-white p-[12mm] text-[10.5pt] text-black ring-1 ring-line print:ring-0">
            <header class="border-b-2 border-black pb-2 text-center">
                <p class="text-[15pt] font-bold">{{ organization }}</p>
                <p v-if="paper.unit && paper.unit !== organization">{{ paper.unit }}</p>
                <p class="mt-1 text-[13pt] font-semibold">{{ say('inpatient.paper.heading') }}</p>
            </header>
            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 border-b border-black/30 pb-3">
                <div><dt class="inline font-semibold">{{ say('inpatient.paper.patient') }}:</dt> <dd class="inline">{{ paper.patient?.name }}</dd></div>
                <div><dt class="inline font-semibold">{{ say('inpatient.paper.number') }}:</dt> <dd class="inline" dir="ltr">{{ paper.number }}</dd></div>
                <div><dt class="inline font-semibold">{{ say('inpatient.paper.mrn') }}:</dt> <dd class="inline" dir="ltr">{{ paper.patient?.mrn }}</dd></div>
                <div><dt class="inline font-semibold">{{ say('inpatient.paper.age') }}:</dt> <dd class="inline">{{ paper.patient?.age ?? '—' }}</dd></div>
                <div><dt class="inline font-semibold">{{ say('inpatient.paper.bed') }}:</dt> <dd class="inline">{{ textIn(paper.bed?.ward ?? {}) }} <span dir="ltr">{{ paper.bed?.code }}</span></dd></div>
                <div><dt class="inline font-semibold">{{ say('inpatient.paper.doctor') }}:</dt> <dd class="inline">{{ paper.doctor }}</dd></div>
                <div><dt class="inline font-semibold">{{ say('inpatient.paper.admitted') }}:</dt> <dd class="inline">{{ formatDateTime(paper.admitted_at) }}</dd></div>
                <div><dt class="inline font-semibold">{{ say('inpatient.paper.discharged') }}:</dt> <dd class="inline">{{ formatDateTime(paper.discharged_at) }}</dd></div>
            </dl>
            <section v-if="paper.reason" class="mt-3"><p class="font-semibold">{{ say('inpatient.paper.reason') }}</p><p class="whitespace-pre-line">{{ paper.reason }}</p></section>
            <template v-for="key in sections" :key="key">
                <section v-if="paper[key]" class="mt-3"><p class="font-semibold">{{ say(`inpatient.discharge.${key}`) }}</p><p class="whitespace-pre-line">{{ paper[key] }}</p></section>
            </template>
            <p v-if="paper.outcome" class="mt-3"><span class="font-semibold">{{ say('inpatient.discharge.outcome') }}:</span> {{ say(`inpatient.outcome.${paper.outcome}`) }}</p>
            <p v-if="paper.follow_up_on" class="mt-2 font-semibold">{{ say('inpatient.discharge.follow_up_on') }}: {{ formatDate(paper.follow_up_on) }}</p>
            <footer class="mt-14 flex justify-end">
                <div class="border-t border-black px-6 pt-1 text-center text-[9.5pt]">
                    <p>{{ paper.signed_by }}</p>
                    <p>{{ say('inpatient.paper.signature') }}</p>
                </div>
            </footer>
        </article>
    </PageFrame>
</template>

<style scoped>
@media print {
    @page { size: A4; margin: 0; }
    .sheet { max-width: none; }
}
</style>
