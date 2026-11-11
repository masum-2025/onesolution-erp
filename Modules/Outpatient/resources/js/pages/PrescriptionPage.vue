<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, FileText, Printer } from 'lucide-vue-next';
import { AppButton, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { loadNamespaces, t, tIn } from '@/lib/i18n';
import { textIn } from '@/lib/texts';
import { opdApi } from '../api';

/**
 * The prescription on its own A5 paper: the doctor (name, degrees, BMDC
 * number), the patient, findings and diagnosis, ℞ medicines with dose, days
 * and when to take them, tests, advice and the follow-up date. Headings and
 * "when to take" print in the rule's language: Bangla, English or both.
 */
const opd = opdApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const view = useResource(() => opd.prescription(route.params.id));
const paper = computed(() => view.data.value?.data ?? null);
const organization = computed(() => currentOrganization()?.name ?? '');
const languages = computed(() => (paper.value?.language === 'both' ? ['bn', 'en'] : [paper.value?.language ?? 'bn']));
const ready = ref(false);
watch(languages, async (list) => {
    await Promise.all(list.map((locale) => loadNamespaces(['outpatient'], locale)));
    ready.value = true;
}, { immediate: true });
// A heading or word in the prescription's language(s): "উপদেশ / Advice".
const say = (key) => languages.value.map((locale) => tIn(locale, key)).join(' / ');
const findingKeys = computed(() => Object.keys(paper.value?.findings ?? {}).filter((key) => paper.value.findings[key]));
const printPaper = () => window.print();
</script>

<template>
    <PageFrame :title="paper ? t('outpatient.prescription.title', { name: paper.serial.patient?.name ?? '' }) : ''" :resource="view" width="md" own-paper
               :not-found="{ icon: FileText, title: t('outpatient.prescription.not_found') }">
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" class="print:hidden" @click="router.push({ name: 'outpatient-consult', params: { id: route.params.id } })">{{ t('outpatient.prescription.back') }}</AppButton>
        </template>
        <template v-if="paper" #actions>
            <AppButton variant="primary" :icon="Printer" @click="printPaper">{{ t('outpatient.slip.print') }}</AppButton>
        </template>
        <article v-if="paper && ready" class="rx mx-auto min-h-[200mm] w-full max-w-[148mm] bg-white p-[8mm] text-[10pt] text-black ring-1 ring-line print:ring-0">
            <header class="flex items-start justify-between gap-4 border-b-2 border-black pb-2">
                <div>
                    <p class="text-[13pt] font-bold">{{ textIn(paper.doctor.name) }}</p>
                    <p v-if="paper.doctor.degrees">{{ paper.doctor.degrees }}</p>
                    <p v-if="paper.doctor.specialty">{{ paper.doctor.specialty }}</p>
                    <p v-if="paper.doctor.registration_no">{{ say('outpatient.prescription.registration') }}: <span dir="ltr">{{ paper.doctor.registration_no }}</span></p>
                </div>
                <div class="text-end">
                    <p class="font-semibold">{{ organization }}</p>
                    <p>{{ paper.serial.chamber?.name }}<template v-if="paper.serial.chamber?.room"> · {{ paper.serial.chamber.room }}</template></p>
                    <p>{{ formatDate(paper.serial.date) }}</p>
                </div>
            </header>
            <p class="mt-2 flex flex-wrap gap-x-4 border-b border-black/30 pb-2">
                <span><span class="font-semibold">{{ say('outpatient.prescription.patient') }}:</span> {{ paper.serial.patient?.name }}</span>
                <span dir="ltr">{{ paper.serial.patient?.mrn }}</span>
                <span>{{ say('outpatient.prescription.age') }}: {{ paper.serial.patient?.age ?? '—' }}</span>
            </p>
            <div class="mt-3 grid grid-cols-[38%_1fr] gap-4">
                <div class="grid content-start gap-3 border-e border-black/30 pe-3">
                    <section v-if="paper.complaints"><p class="font-semibold">{{ say('outpatient.prescription.complaints') }}</p><p class="whitespace-pre-line">{{ paper.complaints }}</p></section>
                    <section v-if="findingKeys.length">
                        <p class="font-semibold">{{ say('outpatient.prescription.findings') }}</p>
                        <p v-for="key in findingKeys" :key="key">{{ say(`outpatient.finding.${key}`) }}: {{ paper.findings[key] }}</p>
                    </section>
                    <section v-if="paper.diagnosis"><p class="font-semibold">{{ say('outpatient.prescription.diagnosis') }}</p><p class="whitespace-pre-line">{{ paper.diagnosis }}</p></section>
                    <section v-if="paper.tests.length">
                        <p class="font-semibold">{{ say('outpatient.prescription.tests') }}</p>
                        <ul class="list-inside list-disc"><li v-for="(test, index) in paper.tests" :key="index">{{ test.test_text }}</li></ul>
                    </section>
                </div>
                <div class="grid content-start gap-3">
                    <p class="text-[20pt] font-bold leading-none" aria-hidden="true">℞</p>
                    <ol class="grid gap-2">
                        <li v-for="(item, index) in paper.medicines" :key="index">
                            <p class="font-semibold">{{ index + 1 }}. {{ item.medicine_text }}</p>
                            <p class="ps-4">
                                <span v-if="item.dose" dir="ltr">{{ item.dose }}</span><template v-if="item.timing"> · {{ say(`outpatient.timing.${item.timing}`) }}</template><template v-if="item.duration"> · {{ item.duration }}</template>
                            </p>
                            <p v-if="item.note" class="ps-4 text-[9pt]">{{ item.note }}</p>
                        </li>
                    </ol>
                    <section v-if="paper.advice" class="mt-2"><p class="font-semibold">{{ say('outpatient.prescription.advice') }}</p><p class="whitespace-pre-line">{{ paper.advice }}</p></section>
                    <p v-if="paper.follow_up_on" class="mt-2 font-semibold">{{ say('outpatient.prescription.follow_up') }}: {{ formatDate(paper.follow_up_on) }}</p>
                </div>
            </div>
            <footer class="mt-10 flex justify-end">
                <p class="border-t border-black px-6 pt-1 text-[9pt]">{{ say('outpatient.prescription.signature') }}</p>
            </footer>
        </article>
    </PageFrame>
</template>

<style scoped>
@media print {
    @page { size: A5; margin: 0; }
    .rx { max-width: none; min-height: 0; }
}
</style>
