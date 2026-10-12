<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Lock, Printer, SearchX } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { t } from '@/lib/i18n';
import { portalResultsApi } from '../api';
import ReportCard from '../components/ReportCard.vue';

/** One published result's report card in the portal, ready to print on A4. */
const route = useRoute();
const card = useResource(() => portalResultsApi(route.params.record).reportCard(route.params.id));
const data = computed(() => card.data.value?.data ?? null);
const printPage = () => window.print();
</script>

<template>
    <div>
        <component :is="'style'">@media print { @page { size: A4 portrait; margin: 12mm; } }</component>
        <div class="mb-4 flex flex-wrap items-center gap-3 print:hidden">
            <RouterLink :to="{ name: 'exams-portal', params: { record: route.params.record } }" class="inline-flex items-center gap-1.5 text-[13px] text-muted hover:text-fg">
                <ArrowLeft class="size-4 rtl:rotate-180" aria-hidden="true" />{{ t('education_exams.portal.back') }}
            </RouterLink>
            <AppButton class="ms-auto" variant="primary" :icon="Printer" :disabled="!data" @click="printPage">{{ t('education_exams.cards.print') }}</AppButton>
        </div>
        <SkeletonRows v-if="card.loading.value && !data" :rows="8" />
        <section v-else-if="card.error.value?.code === 'result_held'" class="card flex items-start gap-3 px-5 py-4">
            <Lock class="mt-0.5 size-5 shrink-0 text-warn" aria-hidden="true" />
            <p class="text-[13.5px] text-fg">{{ card.error.value.message }}</p>
        </section>
        <section v-else-if="card.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('education_exams.portal.not_found')" /></section>
        <ErrorState v-else-if="card.error.value" :error="card.error.value" @retry="card.reload()" />
        <ReportCard v-else-if="data?.students.length" :card="data.students[0]" :data="data" />
    </div>
</template>
