<script setup>
import { computed } from 'vue';
import { FileText } from 'lucide-vue-next';
import { PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { portalPrescriptions } from '../api';

/**
 * A patient's own prescriptions in the client's portal (B2B2C): the doctor,
 * the date, the diagnosis, medicines with how to take them, tests, advice and
 * the follow-up date. Only finished prescriptions in force.
 */
const view = useResource(() => portalPrescriptions());
const rows = computed(() => view.data.value?.data?.prescriptions ?? []);
</script>

<template>
    <PageFrame :title="t('outpatient.portal.title')" :description="view.data.value?.data?.organization ?? ''" width="md" :resource="view"
               :empty="{ when: !rows.length, icon: FileText, title: t('outpatient.portal.empty'), text: t('outpatient.portal.empty_text') }">
        <ul class="grid gap-4">
            <li v-for="item in rows" :key="item.id" class="card p-4 text-[13.5px]">
                <p class="text-[15px] font-semibold">{{ formatDate(item.date) }} · {{ textIn(item.doctor.name) }}</p>
                <p v-if="item.doctor.degrees" class="text-[12.5px] text-muted">{{ item.doctor.degrees }}</p>
                <p v-if="item.diagnosis" class="mt-2"><span class="font-medium">{{ t('outpatient.consult.diagnosis') }}:</span> {{ item.diagnosis }}</p>
                <ol v-if="item.medicines.length" class="mt-2 grid gap-1">
                    <li v-for="(medicine, index) in item.medicines" :key="index">
                        <span class="font-medium">{{ index + 1 }}. {{ medicine.medicine_text }}</span>
                        <span class="text-muted"><template v-if="medicine.dose"> · <span dir="ltr">{{ medicine.dose }}</span></template><template v-if="medicine.timing"> · {{ t(`outpatient.timing.${medicine.timing}`) }}</template><template v-if="medicine.duration"> · {{ medicine.duration }}</template></span>
                        <span v-if="medicine.note" class="block text-[12.5px] text-muted">{{ medicine.note }}</span>
                    </li>
                </ol>
                <p v-if="item.tests.length" class="mt-2"><span class="font-medium">{{ t('outpatient.consult.tests') }}:</span> {{ item.tests.map((test) => test.test_text).join(', ') }}</p>
                <p v-if="item.advice" class="mt-2 whitespace-pre-line"><span class="font-medium">{{ t('outpatient.consult.advice') }}:</span> {{ item.advice }}</p>
                <p v-if="item.follow_up_on" class="mt-2 font-medium">{{ t('outpatient.consult.follow_up') }}: {{ formatDate(item.follow_up_on) }}</p>
            </li>
        </ul>
    </PageFrame>
</template>
