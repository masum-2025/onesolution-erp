<script setup>
import { computed, ref, watch } from 'vue';
import { Handshake, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { readPref, writePref } from '@/lib/storage';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { useCrmSetup } from '../setup';
import DealDialog from '../components/DealDialog.vue';

/**
 * The deal board of a pipeline: a column per stage with its total and
 * weighted value (by the stage's chance), cards dragged to another stage
 * (or moved with the stage picker on a phone); dropping on "lost" asks why.
 * Won and lost show the last 90 days.
 */
const crm = crmApi(currentOrganization().id);
const setup = useCrmSetup();
const pipelineId = ref(readPref('crm.pipeline') ?? '');
const mine = ref(false);
const pipelines = computed(() => (setup.data.value?.pipelines ?? []).filter((pipeline) => pipeline.is_active));
const pipeline = computed(() => pipelines.value.find((row) => row.id === pipelineId.value) ?? pipelines.value.find((row) => row.is_default) ?? pipelines.value[0] ?? null);
const board = useResource(() => (pipeline.value ? crm.deals({ pipeline_id: pipeline.value.id, status: 'all', ...(mine.value ? { mine: 1 } : {}) }) : Promise.resolve({ data: [] })));
watch([pipeline, mine], () => {
    if (pipeline.value) writePref('crm.pipeline', pipeline.value.id);
    board.reload();
});
const deals = computed(() => board.data.value?.data ?? []);
const stages = computed(() => (pipeline.value?.stages ?? []).filter((stage) => stage.is_active));
const columns = computed(() => stages.value.map((stage) => {
    const cards = deals.value.filter((deal) => deal.stage_id === stage.id);
    const total = cards.reduce((sum, deal) => sum + deal.value_minor, 0);
    return { stage, cards, total, weighted: Math.floor((total * stage.probability_bp) / 10000) };
}));
const money = (amount) => formatMoney({ amount, currency: setup.currency.value });
const openTotal = computed(() => columns.value.filter((column) => column.stage.outcome === 'open').reduce((sum, column) => sum + column.weighted, 0));

const moving = ref(null);
async function moveTo(deal, stage) {
    if (!deal || deal.stage_id === stage.id) return;
    let reason = null;
    if (stage.outcome === 'lost') {
        const confirmed = await confirmAction({ title: t('crm.deals.lost_title', { deal: deal.title }), message: t('crm.deals.lost_text'), confirmLabel: t('crm.deals.mark_lost'), reason: 'required', danger: true });
        if (!confirmed) return;
        reason = confirmed.reason;
    }
    moving.value = deal.id;
    try {
        await crm.moveDeal(deal.id, { base_version: deal.version, stage_id: stage.id, ...(reason ? { lost_reason: reason } : {}) });
        if (stage.outcome === 'won') toast.success(t('crm.deals.won', { deal: deal.title }));
        board.reload();
    } catch (error) {
        if (error.code === 'version_conflict') board.reload();
        toast.error(error.message);
    } finally {
        moving.value = null;
    }
}
const dragged = ref(null);
const over = ref(null);
function drop(stage) {
    over.value = null;
    moveTo(deals.value.find((deal) => deal.id === dragged.value), stage);
    dragged.value = null;
}
const adding = ref(false);
const editing = ref(null);
</script>

<template>
    <div>
        <PageHeader :title="t('crm.deals.title')" :description="t('crm.deals.text', { amount: money(openTotal) })">
            <template #actions>
                <select v-if="pipelines.length > 1" v-model="pipelineId" class="field-input w-auto" :aria-label="t('crm.deals.pipeline')">
                    <option v-for="row in pipelines" :key="row.id" :value="row.id">{{ row.name }}</option>
                </select>
                <AppSwitch v-model="mine" :label="t('crm.deals.mine')" show-label />
                <AppButton v-if="can('crm.edit')" variant="primary" :icon="Plus" @click="adding = true">{{ t('crm.deals.add') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="(board.loading.value && !board.data.value) || setup.loading.value" :rows="6" />
        <ErrorState v-else-if="board.error.value || setup.error.value" :error="board.error.value ?? setup.error.value" @retry="board.reload()" />
        <EmptyState v-else-if="!deals.length" :icon="Handshake" :title="t('crm.deals.empty')" :text="t('crm.deals.empty_text')">
            <AppButton v-if="can('crm.edit')" variant="primary" :icon="Plus" @click="adding = true">{{ t('crm.deals.add') }}</AppButton>
        </EmptyState>
        <div v-else class="-mx-4 overflow-x-auto px-4 pb-3">
            <div class="flex min-w-max gap-3">
                <section v-for="column in columns" :key="column.stage.id" class="flex w-72 flex-col rounded-2xl border border-line bg-subtle"
                    :class="over === column.stage.id ? 'ring-2 ring-brand' : ''"
                    @dragover.prevent="over = column.stage.id" @dragleave="over = over === column.stage.id ? null : over" @drop.prevent="drop(column.stage)">
                    <header class="border-b border-line px-3 py-2.5">
                        <p class="flex items-center justify-between gap-2 text-[13.5px] font-semibold">
                            <span :class="{ won: 'text-ok', lost: 'text-bad' }[column.stage.outcome] ?? ''">{{ column.stage.name }}</span>
                            <span class="rounded-full bg-surface px-2 text-[12px] text-muted">{{ formatNumber(column.cards.length) }}</span>
                        </p>
                        <p class="tabular text-[12px] text-muted">{{ money(column.total) }}<template v-if="column.stage.outcome === 'open'"> · {{ t('crm.deals.chance', { percent: formatNumber(column.stage.probability_bp / 100) }) }}</template></p>
                    </header>
                    <ul class="grid min-h-24 content-start gap-2 p-2">
                        <li v-for="deal in column.cards" :key="deal.id" :draggable="can('crm.edit')" class="card cursor-grab p-3 text-[13px] active:cursor-grabbing"
                            :class="moving === deal.id ? 'opacity-50' : ''" @dragstart="dragged = deal.id">
                            <button type="button" class="block w-full text-start" @click="editing = deal">
                                <span class="block font-medium leading-snug">{{ deal.title }}</span>
                                <span class="mt-0.5 block text-[12px] text-muted">{{ deal.contact_company || deal.contact_name }}</span>
                            </button>
                            <span class="mt-2 flex items-center justify-between gap-2">
                                <b class="tabular">{{ money(deal.value_minor) }}</b>
                                <span v-if="deal.expected_on" class="text-[11.5px] text-muted">{{ formatDate(`${deal.expected_on}T00:00:00Z`, { day: 'numeric', month: 'short', timeZone: 'UTC' }) }}</span>
                            </span>
                            <p v-if="deal.lost_reason" class="mt-1 text-[11.5px] text-bad">{{ deal.lost_reason }}</p>
                            <RouterLink :to="{ name: 'crm-contact', params: { id: deal.contact_id } }" class="mt-1 inline-block text-[11.5px] text-brand-text hover:underline">{{ t('crm.deals.open_contact') }}</RouterLink>
                            <!-- On a phone (no dragging): move with a picker. -->
                            <select v-if="can('crm.edit')" class="field-input mt-2 h-8 text-[12px] md:hidden" :value="deal.stage_id" :aria-label="t('crm.deals.move')"
                                @change="moveTo(deal, stages.find((stage) => stage.id === $event.target.value))">
                                <option v-for="stage in stages" :key="stage.id" :value="stage.id">{{ stage.name }}</option>
                            </select>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <DealDialog v-if="adding" :pipeline-id="pipeline?.id" @close="adding = false" @saved="adding = false; board.reload()" />
        <DealDialog v-if="editing" :deal="editing" @close="editing = null" @saved="editing = null; board.reload()" />
    </div>
</template>
