<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Ban, Printer, SearchX } from 'lucide-vue-next';
import { AppBadge, AppButton, EmptyState, ErrorState, SkeletonRows, confirmAction, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { lettersApi, portalLettersApi } from '../api';
import { letterTone } from '../lib';
import LetterPaper from '../components/LetterPaper.vue';

/**
 * One letter as issued, on its own paper, to read, print or save as a PDF
 * (the browser's "Save as PDF"). The same page serves HR (who may revoke it
 * with a reason), the employee ("my letters") and the portal. A revoked
 * letter carries the mark on screen and on paper.
 */
const route = useRoute();
const mode = route.meta.letter;
const letters = mode === 'portal' ? null : lettersApi(currentOrganization().id);
const record = useResource(() => {
    if (mode === 'portal') return portalLettersApi.letter(route.params.id);
    return mode === 'mine' ? letters.myLetter(route.params.id) : letters.letter(route.params.id);
});
const letter = computed(() => record.data.value?.data ?? null);
const back = { staff: { name: 'letters' }, mine: { name: 'letters-me' }, portal: { name: 'letters-portal' } }[mode];
const printPage = () => window.print();

async function revoke() {
    const result = await confirmAction({
        title: t('letters.letter.revoke_title', { number: letter.value.number }),
        message: t('letters.letter.revoke_text'),
        confirmLabel: t('letters.letter.revoke'),
        reason: 'required',
        danger: true,
    });
    if (!result) return;
    try {
        await letters.revoke(letter.value.id, result.reason);
        toast.success(t('letters.letter.revoked'));
        record.reload();
    } catch (error) {
        toast.error(Object.values(error.errors ?? {})[0]?.[0] ?? error.message);
    }
}
</script>

<template>
    <div class="mx-auto max-w-[230mm]">
        <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" :to="back">{{ t('letters.common.back') }}</AppButton>
            <template v-if="letter">
                <span class="min-w-0 flex-1 truncate text-[14.5px] font-semibold">
                    {{ letter.title }} · <span class="font-mono text-[13px] text-muted" dir="ltr">{{ letter.number }}</span>
                </span>
                <AppBadge :tone="letterTone(letter)" dot>{{ t(letter.revoked ? 'letters.status.revoked' : 'letters.status.issued') }}</AppBadge>
                <AppButton v-if="letter.can?.revoke" size="sm" variant="ghost" :icon="Ban" @click="revoke">{{ t('letters.letter.revoke') }}</AppButton>
                <AppButton size="sm" variant="primary" :icon="Printer" @click="printPage">{{ t('letters.letter.print') }}</AppButton>
            </template>
        </div>

        <SkeletonRows v-if="record.loading.value && !letter" :rows="8" />
        <section v-else-if="record.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('letters.letter.not_found')" /></section>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />
        <template v-else-if="letter">
            <div v-if="mode === 'staff'" class="mb-4 grid gap-1 rounded-xl bg-subtle px-4 py-3 text-[12.5px] text-muted sm:grid-cols-2 print:hidden">
                <span>{{ t('letters.letter.for', { name: letter.employee.name, code: letter.employee.code }) }}</span>
                <span>{{ t('letters.letter.issued', { date: formatDate(letter.issued_at, { dateStyle: 'medium' }), by: letter.issued_by ?? '—' }) }}</span>
                <span>{{ letter.shared ? t('letters.letter.shared') : t('letters.letter.not_shared') }}</span>
                <span v-if="letter.revoked" class="text-bad">{{ t('letters.letter.revoked_on', { date: formatDate(letter.revoked_at, { dateStyle: 'medium' }), reason: letter.revoke_reason }) }}</span>
            </div>
            <p class="mb-3 text-center text-[12px] text-muted print:hidden">{{ t('letters.letter.pdf_hint') }}</p>
            <div class="overflow-x-auto pb-6 print:overflow-visible print:pb-0">
                <LetterPaper :content="letter.content" :images="letter.images" :revoked="letter.revoked" />
            </div>
        </template>
    </div>
</template>
