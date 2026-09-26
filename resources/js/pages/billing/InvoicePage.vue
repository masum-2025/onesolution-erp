<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import InvoiceDocument from './InvoiceDocument.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { currentOrganization, session } from '@/lib/session';
import { t } from '@/lib/i18n';

/**
 * One invoice or credit note, from the partner console or from the client's
 * billing screen (each reads it through its own, separately checked API).
 */
const route = useRoute();
const partner = computed(() => session.me?.context?.type === 'partner');
const back = computed(() => (partner.value ? '/partner/billing' : '/billing'));

const print = () => window.print();

const invoice = useResource(() =>
    partner.value
        ? api(`/api/partner/billing/invoices/${route.params.id}`).then((response) => response.data)
        : api(`/api/organizations/${currentOrganization().id}/billing/invoices/${route.params.id}`).then((response) => response.data),
);
</script>

<template>
    <div class="mx-auto max-w-4xl">
        <div class="mb-5 flex items-center justify-between gap-3 print:hidden">
            <AppButton variant="ghost" :icon="ArrowLeft" :to="back">{{ t('billing.document.back') }}</AppButton>
            <AppButton v-if="invoice.data.value" :icon="Printer" @click="print">{{ t('billing.document.print') }}</AppButton>
        </div>

        <SkeletonRows v-if="invoice.loading.value && !invoice.data.value" :rows="8" />
        <ErrorState v-else-if="invoice.error.value" :error="invoice.error.value" @retry="invoice.reload()" />
        <InvoiceDocument v-else-if="invoice.data.value" :invoice="invoice.data.value" />
    </div>
</template>
