<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Printer, SearchX } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { t } from '@/lib/i18n';
import { portalFeesApi } from '../api';
import ReceiptSlip from '../components/ReceiptSlip.vue';

/** A family's receipt in the portal, to keep or print (A5). */
const route = useRoute();
const record = useResource(() => portalFeesApi.receipt(route.params.record, route.params.id));
const receipt = computed(() => record.data.value?.data ?? null);
const printPage = () => window.print();
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <div class="mb-4 flex items-center justify-between gap-2 print:hidden">
            <AppButton variant="ghost" size="sm" :to="{ name: 'fees-portal', params: { record: route.params.record } }" :icon="ArrowLeft">{{ t('education_fees.portal.back') }}</AppButton>
            <AppButton v-if="receipt" size="sm" variant="primary" :icon="Printer" @click="printPage">{{ t('education_fees.print') }}</AppButton>
        </div>
        <SkeletonRows v-if="record.loading.value && !receipt" :rows="6" />
        <section v-else-if="record.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('education_fees.receipt.not_found')" /></section>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />
        <div v-else-if="receipt" class="card overflow-hidden print:border-0 print:shadow-none">
            <ReceiptSlip :receipt="receipt" size="a5" />
        </div>
    </div>
</template>
