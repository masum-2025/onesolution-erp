<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, CheckCheck, Info, RefreshCw, SearchX, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import { billState } from '../lib';
import { day, runTitle } from '../labels';

/**
 * One billing run: a draft to check (each student's bill, recalculated
 * after a change), then made final or dropped; final bills open from here.
 */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();
const route = useRoute();

const record = useResource(() => api.run(route.params.id));
const run = computed(() => record.data.value?.data ?? null);
const skipped = ref({ billed: Number(route.query.billed ?? 0), none: Number(route.query.none ?? 0) });
const tone = { draft: 'warn', final: 'ok', cancelled: 'outline' };

const busy = ref(null);
async function act(name) {
    if (name !== 'refresh') {
        const confirmed = await confirmAction({
            title: t(`education_fees.run.${name}_title`, { count: run.value.bills_count }),
            message: t(`education_fees.run.${name}_text`),
            confirmLabel: t(`education_fees.run.${name}`),
            danger: name === 'cancel',
        });
        if (!confirmed) return;
    }
    busy.value = name;
    try {
        if (name === 'refresh') {
            const { meta } = await api.refreshRun(run.value.id, run.value.version);
            skipped.value = { billed: meta?.skipped?.billed ?? 0, none: meta?.skipped?.no_amounts ?? 0 };
        } else if (name === 'finalize') {
            await api.finalizeRun(run.value.id, run.value.version);
        } else {
            await api.cancelRun(run.value.id, run.value.version);
        }
        toast.success(t(`education_fees.run.${{ refresh: 'refreshed', finalize: 'finalized', cancel: 'cancelled' }[name]}`));
        record.reload();
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('education_fees.conflict') : error.message);
        record.reload();
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'fees-runs' }" :icon="ArrowLeft" class="mb-3">{{ t('education_fees.run.back') }}</AppButton>

        <SkeletonRows v-if="record.loading.value && !run" :rows="8" />
        <section v-else-if="record.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('education_fees.run.not_found')" /></section>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="run">
            <PageHeader :title="runTitle(run)" :description="`${setup.sessionName(run.session_id)} · ${day(run.issue_date)} · ${t('education_fees.counter.due', { date: day(run.due_date) })}`">
                <template #eyebrow>
                    <span class="mb-2 inline-flex"><AppBadge :tone="tone[run.status]" dot>{{ t(`education_fees.run_statuses.${run.status}`) }}</AppBadge></span>
                </template>
                <template v-if="run.status === 'draft' && setup.can('bill')" #actions>
                    <AppButton variant="danger-soft" :icon="Trash2" :loading="busy === 'cancel'" @click="act('cancel')">{{ t('education_fees.run.cancel') }}</AppButton>
                    <AppButton :icon="RefreshCw" :loading="busy === 'refresh'" @click="act('refresh')">{{ t('education_fees.run.refresh') }}</AppButton>
                    <AppButton variant="primary" :icon="CheckCheck" :loading="busy === 'finalize'" @click="act('finalize')">{{ t('education_fees.run.finalize') }}</AppButton>
                </template>
            </PageHeader>

            <div class="mb-5 space-y-2">
                <p v-if="run.status === 'draft'" class="flex items-start gap-2 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-fg"><Info class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />{{ t('education_fees.run.draft_note') }}</p>
                <p v-if="skipped.billed" class="rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ t('education_fees.run.skipped_billed', { count: skipped.billed }) }}</p>
                <p v-if="skipped.none" class="rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ t('education_fees.run.skipped_none', { count: skipped.none }) }}</p>
            </div>

            <dl class="mb-5 grid grid-cols-2 gap-3">
                <div class="card p-4">
                    <dt class="text-[12px] text-muted">{{ t('education_fees.run.bills') }}</dt>
                    <dd class="tabular text-[22px] font-semibold text-fg">{{ run.bills_count }}</dd>
                </div>
                <div class="card p-4">
                    <dt class="text-[12px] text-muted">{{ t('education_fees.run.total') }}</dt>
                    <dd class="tabular text-[22px] font-semibold text-fg">{{ setup.money(run.total_minor) }}</dd>
                </div>
            </dl>

            <section class="card">
                <ul class="divide-y divide-line">
                    <li v-for="bill in run.bills" :key="bill.id">
                        <component :is="bill.status === 'draft' ? 'div' : 'RouterLink'" :to="bill.status === 'draft' ? undefined : { name: 'fees-bill', params: { id: bill.id } }"
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3" :class="bill.status === 'draft' ? '' : 'transition hover:bg-subtle/60'">
                            <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                                <span class="block truncate text-[14px] font-medium text-fg">{{ bill.student?.name ?? '—' }}</span>
                                <span class="block text-[12.5px] text-muted">
                                    <span class="font-mono" dir="ltr">{{ bill.student?.code }}</span>
                                    <template v-if="bill.number"> · <span class="whitespace-nowrap font-mono" dir="ltr">{{ bill.number }}</span></template>
                                    <template v-if="bill.discount_minor"> · {{ t('education_fees.run.discount') }} {{ setup.money(bill.discount_minor) }}</template>
                                </span>
                            </span>
                            <AppBadge v-if="bill.status !== 'draft'" :tone="billState(bill).tone" dot>{{ t(`education_fees.bill_states.${billState(bill).key}`) }}</AppBadge>
                            <span class="tabular ms-auto w-32 text-end text-[14px] font-semibold text-fg">{{ setup.money(bill.total_minor) }}</span>
                        </component>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
