<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Plus, Printer, Stethoscope } from 'lucide-vue-next';
import { AppButton, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { opdApi } from '../api';

/**
 * The serial slip on its own small paper (80 mm, a receipt printer): the
 * company, the doctor and chamber, the date and time, the serial number in
 * large print, the patient's name and number, and the fee or "free".
 */
const opd = opdApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const view = useResource(() => opd.serial(route.params.id));
const serial = computed(() => view.data.value?.data ?? null);
const organization = currentOrganization();
const money = (amount) => formatMoney({ amount, currency: serial.value?.currency });
const printSlip = () => window.print();
</script>

<template>
    <PageFrame :title="serial ? t('outpatient.slip.title', { number: serial.serial_no }) : ''" :resource="view" width="md" own-paper
               :not-found="{ icon: Stethoscope, title: t('outpatient.slip.not_found') }">
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" class="print:hidden" @click="router.push({ name: 'outpatient-serials', query: serial ? { date: serial.date } : {} })">{{ t('outpatient.slip.back') }}</AppButton>
        </template>
        <template v-if="serial" #actions>
            <AppButton variant="ghost" :icon="Plus" @click="router.push({ name: 'outpatient-book' })">{{ t('outpatient.slip.another') }}</AppButton>
            <AppButton variant="primary" :icon="Printer" @click="printSlip">{{ t('outpatient.slip.print') }}</AppButton>
        </template>
        <div v-if="serial" class="slip mx-auto w-[80mm] rounded bg-white p-[4mm] text-center text-black ring-1 ring-line print:rounded-none print:ring-0">
            <p class="text-[11pt] font-semibold">{{ organization.name }}</p>
            <p class="mt-1 text-[10pt] font-medium">{{ serial.doctor?.name }}</p>
            <p v-if="serial.doctor?.specialty" class="text-[8.5pt]">{{ serial.doctor.specialty }}</p>
            <p class="text-[8.5pt]">{{ serial.chamber?.name }}<template v-if="serial.chamber?.room"> · {{ t('outpatient.slip.room', { room: serial.chamber.room }) }}</template></p>
            <p class="mt-3 text-[8.5pt] uppercase tracking-wide">{{ t('outpatient.slip.serial') }}</p>
            <p class="text-[34pt] font-bold leading-none tabular">{{ serial.serial_no }}</p>
            <p class="mt-2 text-[9pt]">{{ formatDate(serial.date) }}<template v-if="serial.session"> · <span dir="ltr">{{ serial.session.starts_at }}–{{ serial.session.ends_at }}</span></template></p>
            <div class="mt-3 border-t border-dashed border-black/40 pt-2 text-start text-[9pt]">
                <p><span class="font-medium">{{ serial.patient?.name }}</span> · <span class="font-mono" dir="ltr">{{ serial.patient?.mrn }}</span></p>
                <p>{{ t(`outpatient.kind.${serial.kind}`) }} · {{ serial.is_free ? t('outpatient.slip.free') : t('outpatient.slip.fee', { amount: money(serial.fee_minor) }) }}</p>
                <p v-if="serial.due_minor > 0" class="font-medium">{{ t('outpatient.slip.due', { amount: money(serial.due_minor) }) }}</p>
            </div>
            <p class="mt-3 text-[7.5pt]">{{ t('outpatient.slip.footer') }}</p>
        </div>
    </PageFrame>
</template>

<style scoped>
@media print {
    @page { size: 80mm auto; margin: 0; }
}
</style>
