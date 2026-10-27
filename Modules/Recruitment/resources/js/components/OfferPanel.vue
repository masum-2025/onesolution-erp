<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { Check, FilePlus2, PenLine, Printer, Send, Undo2, X } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatMoney } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import OfferDialog from './OfferDialog.vue';
import { offerTone } from '../lib';

/**
 * The offers of an application: written by HR, approved or sent back by
 * someone else who may hire (when the rule asks), sent, answered by the
 * candidate through their link, withdrawn. Each opens as a letter to read
 * or print. One offer goes at a time.
 */
const props = defineProps({
    application: { type: Object, required: true },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['changed']);

const writing = ref(null); // 'new' | a draft
const busy = ref(null);
const offers = computed(() => props.application.offers ?? []);
const live = computed(() => offers.value.some((offer) => ['draft', 'pending_approval', 'sent', 'accepted'].includes(offer.status)));
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });

async function step(offer, name) {
    const confirmed = await confirmAction({
        title: t(`recruitment.offer.${name}_title`),
        message: t(`recruitment.offer.${name}_text`, { name: props.application.name }),
        confirmLabel: t(`recruitment.offer.${name}`),
        reason: name === 'return' ? 'required' : name === 'withdraw' ? 'optional' : 'none',
        danger: name === 'withdraw',
    });
    if (!confirmed) return;
    busy.value = offer.id;
    try {
        await props.calls.stepOffer(offer.id, name, { base_version: offer.version, ...(confirmed.reason ? { note: confirmed.reason } : {}) });
        toast.success(t(`recruitment.offer.${name}_done`));
        emit('changed');
    } catch (error) {
        if (error.code === 'version_conflict') emit('changed');
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

function saved() {
    writing.value = null;
    emit('changed');
}
</script>

<template>
    <section :aria-label="t('recruitment.offer.heading')">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h3 class="text-[13px] font-semibold text-fg-2">{{ t('recruitment.offer.heading') }}</h3>
            <AppButton v-if="application.can?.manage && !live" size="sm" :icon="FilePlus2" @click="writing = 'new'">{{ t('recruitment.offer.new') }}</AppButton>
        </div>
        <p v-if="!offers.length" class="text-[12.5px] text-muted">{{ t('recruitment.offer.none') }}</p>
        <ol v-else class="space-y-2">
            <li v-for="offer in offers" :key="offer.id" class="rounded-xl border border-line p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="tabular text-[14px] font-semibold">{{ formatMoney({ amount: offer.salary_minor, currency: offer.currency }) }} <span class="text-[12.5px] font-normal text-muted">{{ t('recruitment.offer.a_month') }}</span></p>
                    <AppBadge :tone="offerTone(offer.past ? 'expired' : offer.status)">{{ t(`recruitment.offer_status.${offer.past ? 'expired' : offer.status}`) }}</AppBadge>
                </div>
                <p class="text-[12.5px] text-muted">
                    {{ t('recruitment.offer.dates', { start: day(offer.start_on), by: day(offer.respond_by) }) }}
                    · {{ t('recruitment.offer.written_by', { name: offer.written_by }) }}<template v-if="offer.approved_by"> · {{ t('recruitment.offer.approved_by', { name: offer.approved_by }) }}</template>
                </p>
                <p v-if="offer.note" class="mt-1 rounded-lg bg-subtle px-2.5 py-1.5 text-[12.5px]">“{{ offer.note }}”</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <RouterLink :to="`/recruitment/offers/${offer.id}`" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[13px] font-medium text-brand hover:bg-subtle">
                        <Printer class="size-4" aria-hidden="true" />{{ t('recruitment.offer.letter') }}
                    </RouterLink>
                    <AppButton v-if="offer.can.edit" size="sm" variant="ghost" :icon="PenLine" @click="writing = offer">{{ t('recruitment.offer.edit') }}</AppButton>
                    <AppButton v-if="offer.can.edit" size="sm" variant="primary" :icon="Send" :loading="busy === offer.id" @click="step(offer, 'send')">{{ t('recruitment.offer.send') }}</AppButton>
                    <AppButton v-if="offer.can.approve" size="sm" variant="primary" :icon="Check" :loading="busy === offer.id" @click="step(offer, 'approve')">{{ t('recruitment.offer.approve') }}</AppButton>
                    <AppButton v-if="offer.can.approve" size="sm" variant="ghost" :icon="Undo2" :loading="busy === offer.id" @click="step(offer, 'return')">{{ t('recruitment.offer.return') }}</AppButton>
                    <AppButton v-if="offer.can.withdraw" size="sm" variant="ghost" :icon="X" :loading="busy === offer.id" @click="step(offer, 'withdraw')">{{ t('recruitment.offer.withdraw') }}</AppButton>
                </div>
            </li>
        </ol>
        <OfferDialog :open="writing !== null" :application="application" :offer="writing === 'new' ? null : writing" :calls="calls" @close="writing = null" @saved="saved" />
    </section>
</template>
