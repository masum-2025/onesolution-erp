<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { brand } from '@/lib/brand';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { recruitmentApi } from '../api';
import { offerTone } from '../lib';

/**
 * A job offer as a letter on an A4 page, to read and print (the browser's
 * "Save as PDF" makes the file): the letter as sent, or, before that, as
 * it would read now (marked as a draft). The toolbar is not printed.
 */
const route = useRoute();
const org = currentOrganization();
const calls = recruitmentApi(org.id);
const view = useResource(() => calls.offer(route.params.id));
const offer = computed(() => view.data.value?.data ?? null);
const draft = computed(() => offer.value && ['draft', 'pending_approval'].includes(offer.value.status));
const print = () => window.print();
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2 print:hidden">
            <AppButton variant="ghost" :icon="ArrowLeft" @click="$router.back()">{{ t('recruitment.offer.back') }}</AppButton>
            <div class="flex items-center gap-2">
                <AppBadge v-if="offer" :tone="offerTone(offer.past ? 'expired' : offer.status)">{{ t(`recruitment.offer_status.${offer.past ? 'expired' : offer.status}`) }}</AppBadge>
                <AppButton variant="primary" :icon="Printer" :disabled="!offer" @click="print">{{ t('recruitment.offer.print') }}</AppButton>
            </div>
        </div>
        <p v-if="draft" class="rounded-xl border border-warn/40 bg-warn/10 px-4 py-2 text-[13px] print:hidden">{{ t('recruitment.offer.draft_note') }}</p>

        <ErrorState v-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <SkeletonRows v-else-if="!offer" :rows="6" />
        <article v-else class="offer-sheet mx-auto bg-white text-[#16181d] shadow-sm print:shadow-none">
            <header class="mb-8 flex items-center justify-between gap-4 border-b border-[#e3e6ec] pb-4">
                <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="h-12 w-auto" />
                <p class="text-end text-[15px] font-semibold">{{ offer.company }}</p>
            </header>
            <p v-if="draft" class="mb-4 text-[12px] font-semibold uppercase tracking-wide text-[#b42318]">{{ t('recruitment.offer.draft_mark') }}</p>
            <div class="whitespace-pre-line text-[14.5px] leading-7">{{ offer.letter }}</div>
        </article>
    </div>
</template>

<style scoped>
.offer-sheet {
    inline-size: 100%;
    max-inline-size: 210mm;
    min-block-size: 297mm;
    padding: 22mm 20mm;
    border-radius: 6px;
}
@media print {
    .offer-sheet {
        padding: 0;
        min-block-size: auto;
        border-radius: 0;
    }
    @page {
        size: A4;
        margin: 20mm;
    }
}
</style>
