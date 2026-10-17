<script setup>
import { computed } from 'vue';
import { ShieldCheck } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatDate, formatDecimal, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { dhuText } from '../lib';

/**
 * An order's quality: end-line pieces checked, defects and DHU with the
 * kinds found most; its final inspections (sample, defects, pass or fail);
 * and, where the company needs it, that it ships only after a passed final.
 */
const props = defineProps({ order: { type: Object, required: true } });
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const quality = useResource(() => api.orderQuality(props.order.id));
const types = useResource(() => api.defectTypes());
const data = computed(() => quality.data.value?.data ?? null);
const typeName = (id) => (types.data.value?.data ?? []).find((type) => type.id === id)?.name ?? '—';
const byType = computed(() => Object.entries(data.value?.endline?.by_type ?? {}).map(([id, count]) => ({ id, count })));
const most = computed(() => Math.max(1, ...byType.value.map((row) => row.count)));
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
</script>

<template>
    <div class="space-y-5">
        <SkeletonRows v-if="quality.loading.value && !data" :rows="4" />
        <ErrorState v-else-if="quality.error.value" :error="quality.error.value" @retry="quality.reload()" />
        <template v-else-if="data">
            <p v-if="data.ship_needs_pass && order.status === 'in_production'" class="rounded-xl px-4 py-3 text-[13px]" :class="data.passed ? 'bg-ok-soft text-ok' : 'bg-warn-soft text-warn'">
                {{ data.passed ? t('factory_erp.quality.can_ship') : t('factory_erp.quality.needs_final') }}
            </p>
            <section class="card">
                <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.quality.endline') }}</h2>
                        <p class="text-[12.5px] text-muted">
                            {{ t('factory_erp.quality.endline_text', { checked: formatNumber(data.endline.checked), defects: formatNumber(data.endline.defects), dhu: formatDecimal(dhuText(data.endline.dhu_bp)) }) }}
                        </p>
                    </div>
                    <AppButton v-if="setup.can('qc')" size="sm" variant="primary" :icon="ShieldCheck" :to="{ name: 'fac-qc' }">{{ t('factory_erp.quality.check') }}</AppButton>
                </header>
                <EmptyState v-if="!byType.length" compact :icon="ShieldCheck" :title="t('factory_erp.quality.no_defects')" />
                <ul v-else class="space-y-2 px-5 py-4">
                    <li v-for="row in byType" :key="row.id" class="grid grid-cols-[minmax(0,10rem)_1fr_auto] items-center gap-3 text-[13px]">
                        <span class="truncate text-fg">{{ typeName(row.id) }}</span>
                        <span class="h-2 overflow-hidden rounded-full bg-subtle"><span class="block h-full rounded-full bg-bad" :style="{ inlineSize: `${Math.round((row.count * 100) / most)}%` }" /></span>
                        <span class="tabular font-semibold">{{ formatNumber(row.count) }}</span>
                    </li>
                </ul>
            </section>
            <section class="card">
                <header class="border-b border-line px-5 py-3"><h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.quality.finals') }}</h2></header>
                <EmptyState v-if="!data.finals.length" compact :icon="ShieldCheck" :title="t('factory_erp.quality.no_finals')" :text="t('factory_erp.quality.no_finals_text')" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in data.finals" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3" :class="item.voided_at ? 'opacity-70' : ''">
                        <AppBadge :tone="item.voided_at ? 'neutral' : item.result === 'pass' ? 'ok' : 'bad'" dot>{{ item.voided_at ? t('factory_erp.cutting.void_badge') : t(`factory_erp.qc.result_${item.result}`) }}</AppBadge>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13.5px] text-fg" :class="item.voided_at ? 'line-through' : ''">
                                {{ t('factory_erp.quality.final_line', { lot: formatNumber(item.lot_size), aql: item.aql, sample: formatNumber(item.checked), defects: formatNumber(item.defects), accept: formatNumber(item.accept) }) }}
                            </p>
                            <p class="text-[12px] text-muted">{{ day(item.inspected_on) }}<template v-if="item.by_type.length"> · {{ item.by_type.map((row) => `${typeName(row.defect_type_id)} ${formatNumber(row.count)}`).join(' · ') }}</template></p>
                        </div>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
