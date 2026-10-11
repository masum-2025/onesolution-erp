<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Ban, Printer, SearchX } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { readPref, writePref } from '@/lib/storage';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import ReceiptSlip from '../components/ReceiptSlip.vue';
import ReasonDialog from '../components/ReasonDialog.vue';

/**
 * One receipt, ready to print on A5 or an 80 mm roll (the choice is kept
 * per browser). Opened from the counter with ?print=1 it prints at once.
 * Someone at the counter may ask to void it, with a reason.
 */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();
const route = useRoute();
const SIZE_KEY = 'fees.receipt_size';

const record = useResource(() => api.receipt(route.params.id));
const receipt = computed(() => record.data.value?.data ?? null);
const size = ref(readPref(SIZE_KEY) ?? 'a5');
watch(size, (value) => writePref(SIZE_KEY, value));
const sizes = computed(() => [{ value: 'a5', label: t('education_fees.receipt.a5') }, { value: 'thermal', label: t('education_fees.receipt.thermal') }]);

const printPage = () => window.print();
let printed = false;
watch(receipt, async (value) => {
    if (value && route.query.print && !printed) {
        printed = true;
        await nextTick();
        window.print();
    }
});

const voiding = ref(false);
const busy = ref(false);
async function askVoid(reason) {
    busy.value = true;
    try {
        const { data } = await api.requestVoid(receipt.value.id, reason);
        toast.success(t(data.status === 'approved' ? 'education_fees.receipt.void_done' : 'education_fees.receipt.void_asked'));
        voiding.value = false;
        record.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <AppButton variant="ghost" size="sm" :to="receipt ? { name: 'fees-student', params: { id: receipt.student_id } } : { name: 'fees-counter' }" :icon="ArrowLeft">{{ t('education_fees.receipt.back') }}</AppButton>
            <div v-if="receipt" class="flex flex-wrap items-center gap-2">
                <span class="text-[12.5px] text-muted">{{ t('education_fees.receipt.size') }}</span>
                <AppSegmented v-model="size" :options="sizes" />
                <AppButton v-if="setup.can('collect') && receipt.status === 'valid' && !receipt.void_pending" size="sm" variant="danger-soft" :icon="Ban" @click="voiding = true">{{ t('education_fees.receipt.void') }}</AppButton>
                <AppButton size="sm" variant="primary" :icon="Printer" @click="printPage">{{ t('education_fees.print') }}</AppButton>
            </div>
        </div>

        <SkeletonRows v-if="record.loading.value && !receipt" :rows="8" />
        <section v-else-if="record.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('education_fees.receipt.not_found')" /></section>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />
        <template v-else-if="receipt">
            <p v-if="receipt.void_pending" class="mb-4 rounded-xl border border-warn/30 bg-warn-soft px-4 py-3 text-[13px] text-fg print:hidden">{{ t('education_fees.receipt.void_pending') }}</p>
            <div class="card overflow-hidden print:border-0 print:shadow-none">
                <ReceiptSlip :receipt="receipt" :size="size" />
            </div>
        </template>

        <ReasonDialog :open="voiding" :title="receipt ? t('education_fees.receipt.void_title', { number: receipt.number }) : ''" :text="t('education_fees.receipt.void_text')"
            :confirm-label="t('education_fees.receipt.void')" :busy="busy" @close="voiding = false" @submit="askVoid" />
    </div>
</template>
