<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, FlaskConical, Printer } from 'lucide-vue-next';
import { AppButton, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime } from '@/lib/format';
import { barcodeModules } from '@/lib/barcode';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { labApi } from '../api';

/**
 * Tube labels on their own paper (one 50 × 25 mm label a page, for a label
 * printer): the barcode, the patient's name and number, the sample and time.
 */
const lab = labApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const view = useResource(() => lab.order(route.params.id));
const order = computed(() => view.data.value?.data ?? null);
const labels = computed(() => (order.value?.samples ?? []).filter((sample) => !sample.rejected_reason).map((sample) => ({ ...sample, modules: barcodeModules(sample.barcode) })));
// Bars as rectangles: each run of '1' becomes one bar.
const bars = (modules) => {
    const out = [];
    for (let index = 0; index < modules.length; index++) {
        if (modules[index] !== '1') continue;
        let width = 1;
        while (modules[index + width] === '1') width++;
        out.push({ x: index + 10, width });
        index += width - 1;
    }
    return out;
};
const printLabels = () => window.print();
</script>

<template>
    <PageFrame :title="order ? t('diagnostics.labels.title', { number: order.number }) : ''" :resource="view" :not-found="{ icon: FlaskConical, title: t('diagnostics.order.not_found') }" width="md" own-paper>
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" class="print:hidden" @click="router.push({ name: 'diagnostics-order', params: { id: route.params.id } })">{{ t('diagnostics.labels.back') }}</AppButton>
        </template>
        <template v-if="labels.length" #actions>
            <AppButton variant="primary" :icon="Printer" @click="printLabels">{{ t('diagnostics.labels.print') }}</AppButton>
        </template>
        <p v-if="order && !labels.length" class="text-[13px] text-muted">{{ t('diagnostics.labels.none') }}</p>
        <div class="grid justify-center gap-3 print:block">
            <div v-for="label in labels" :key="label.id" class="tube-label flex h-[25mm] w-[50mm] flex-col justify-between overflow-hidden rounded bg-white p-[1.5mm] text-black ring-1 ring-line print:rounded-none print:ring-0">
                <p class="truncate text-[7pt] font-semibold leading-tight">{{ order.patient?.name }} · <span dir="ltr">{{ order.patient?.mrn }}</span></p>
                <svg class="h-[11mm] w-full" :viewBox="`0 0 ${label.modules.length + 20} 40`" preserveAspectRatio="none" role="img" :aria-label="label.barcode">
                    <rect v-for="bar in bars(label.modules)" :key="bar.x" :x="bar.x" y="0" :width="bar.width" height="40" fill="#000" />
                </svg>
                <p class="flex justify-between text-[7pt] leading-tight"><span class="font-mono font-bold" dir="ltr">{{ label.barcode }}</span><span>{{ t(`diagnostics.sample.${label.sample_type}`) }} · {{ formatDateTime(label.collected_at) }}</span></p>
            </div>
        </div>
    </PageFrame>
</template>

<style scoped>
@media print {
    @page { size: 50mm 25mm; margin: 0; }
    .tube-label { break-after: page; }
}
</style>
