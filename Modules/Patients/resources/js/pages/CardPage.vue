<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Printer, UserX } from 'lucide-vue-next';
import { AppButton, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { patientsApi } from '../api';

/**
 * The patient's card on its own paper (credit-card size): the company's
 * name, the patient's name, number, age, sex and blood group, and a QR code
 * holding the patient number only. Printing it is written down as a look.
 */
const pt = patientsApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const view = useResource(() => pt.card(route.params.id));
const card = computed(() => view.data.value?.data ?? null);
const printCard = () => window.print();
</script>

<template>
    <PageFrame :title="card ? t('patients.card.title', { name: card.name }) : ''" :resource="view" :not-found="{ icon: UserX, title: t('patients.patient.not_found') }" width="md" own-paper>
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" class="print:hidden" @click="router.push({ name: 'patients-patient', params: { id: route.params.id } })">{{ t('patients.card.back') }}</AppButton>
        </template>
        <template v-if="card" #actions>
            <AppButton variant="primary" :icon="Printer" @click="printCard">{{ t('patients.card.print') }}</AppButton>
        </template>
        <div v-if="card" class="mx-auto flex h-[54mm] w-[86mm] items-center gap-3 rounded-xl bg-white p-3 text-black ring-1 ring-line print:ring-black">
            <img :src="card.qr" :alt="t('patients.card.qr_alt')" class="size-[30mm] shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-[9pt] font-semibold">{{ card.organization }}</p>
                <p class="mt-1 text-[12pt] font-bold leading-tight">{{ card.name }}</p>
                <p class="mt-1 font-mono text-[13pt] font-bold" dir="ltr">{{ card.mrn }}</p>
                <p class="mt-1 text-[9pt]">{{ t(`patients.sex.${card.sex}`) }} · {{ t('patients.common.years', { count: card.age ?? '—' }) }}<template v-if="card.blood_group"> · <span dir="ltr">{{ card.blood_group }}</span></template></p>
            </div>
        </div>
    </PageFrame>
</template>
