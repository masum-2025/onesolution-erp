<script setup>
import { computed, ref } from 'vue';
import { Download, PackageCheck, PackageMinus, PackagePlus, Truck } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { downloadText, toCsv } from '@/lib/csv';
import { formatDate, formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { formatMilli, milliToText } from '../lib';
import IssueDialog from './IssueDialog.vue';

/**
 * An order's materials: what it needs (bill of materials with wastage),
 * what has gone to the floor, what is still to go and what the stores
 * hold; give more or take some back (store keepers, once the order is
 * confirmed); and every issue and return with its cost.
 */
const props = defineProps({
    order: { type: Object, required: true },
});
const emit = defineEmits(['changed']);
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const needs = useResource(() => api.requirements(props.order.id));
const issues = useResource(() => api.issues(props.order.id));
const rows = computed(() => needs.data.value?.data ?? []);
const running = computed(() => ['confirmed', 'in_production'].includes(props.order.status));
const canIssue = computed(() => running.value && setup.can('issue'));
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const money = (minor, currency) => formatMoney({ amount: minor, currency });

const dialog = ref(null);
function done() {
    dialog.value = null;
    needs.reload();
    issues.reload();
    emit('changed');
}

function download() {
    const head = ['sku', 'item', 'unit', 'need', 'issued', 'to_issue', 'on_hand', 'short'].map((key) => t(`factory_erp.needs.${key}`));
    const body = rows.value.map((row) => [row.sku, row.name, setup.unitCode(row.item_id), milliToText(row.need_milli), milliToText(row.issued_milli),
        milliToText(row.to_issue_milli), milliToText(row.on_hand_milli), milliToText(row.short_milli)]);
    downloadText(`${props.order.number}-materials.csv`, toCsv([head, ...body]));
}
const linesText = (issue) => issue.lines.map((line) => `${setup.itemName(line.item_id)} ${formatMilli(line.quantity_milli)} ${setup.unitCode(line.item_id)}`).join(' · ');
</script>

<template>
    <div class="space-y-5">
        <section class="card">
            <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                <div class="min-w-0 flex-1">
                    <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.needs.title') }}</h2>
                    <p class="text-[12.5px] text-muted">{{ t('factory_erp.needs.text') }}</p>
                </div>
                <AppButton v-if="rows.length" size="sm" variant="ghost" :icon="Download" @click="download">{{ t('factory_erp.needs.csv') }}</AppButton>
                <template v-if="canIssue && rows.length">
                    <AppButton size="sm" :icon="PackageMinus" @click="dialog = 'return'">{{ t('factory_erp.issues.take_back') }}</AppButton>
                    <AppButton size="sm" variant="primary" :icon="PackagePlus" @click="dialog = 'issue'">{{ t('factory_erp.issues.give') }}</AppButton>
                </template>
            </header>
            <p v-if="setup.can('issue') && !running && order.status !== 'shipped' && order.status !== 'cancelled'" class="border-b border-line bg-subtle/60 px-5 py-2 text-[12.5px] text-muted">
                {{ t('factory_erp.issues.confirm_first') }}
            </p>
            <SkeletonRows v-if="needs.loading.value && !needs.data.value" :rows="3" />
            <ErrorState v-else-if="needs.error.value" compact :error="needs.error.value" @retry="needs.reload()" />
            <EmptyState v-else-if="!rows.length" compact :icon="PackageCheck" :title="t('factory_erp.needs.empty_title')" :text="t('factory_erp.needs.empty_text')" />
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-[13px]">
                    <thead class="bg-subtle/60 text-[11.5px] text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-2 text-start font-medium">{{ t('factory_erp.needs.item') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ t('factory_erp.needs.need') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ t('factory_erp.needs.issued') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ t('factory_erp.needs.to_issue') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ t('factory_erp.needs.on_hand') }}</th>
                            <th scope="col" class="px-5 py-2 text-end font-medium">{{ t('factory_erp.needs.short') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in rows" :key="row.item_id">
                            <td class="px-5 py-2">
                                <span class="font-medium text-fg">{{ row.name }}</span> <span class="font-mono text-[12px] text-muted" dir="ltr">{{ row.sku }}</span>
                                <span class="mt-1 block h-1.5 w-full max-w-[180px] overflow-hidden rounded-full bg-subtle" role="presentation">
                                    <span class="block h-full rounded-full bg-brand" :style="{ inlineSize: `${row.need_milli ? Math.min(100, Math.round((row.issued_milli * 100) / row.need_milli)) : 0}%` }" />
                                </span>
                            </td>
                            <td class="tabular px-3 py-2 text-end">{{ formatMilli(row.need_milli) }} <span class="text-[11.5px] text-muted">{{ setup.unitCode(row.item_id) }}</span></td>
                            <td class="tabular px-3 py-2 text-end">{{ formatMilli(row.issued_milli) }}</td>
                            <td class="tabular px-3 py-2 text-end font-medium">{{ row.to_issue_milli > 0 ? formatMilli(row.to_issue_milli) : t('factory_erp.needs.all_given') }}</td>
                            <td class="tabular px-3 py-2 text-end">{{ formatMilli(row.on_hand_milli) }}</td>
                            <td class="tabular px-5 py-2 text-end font-semibold" :class="row.short_milli > 0 ? 'text-bad' : 'text-ok'">
                                {{ row.short_milli > 0 ? formatMilli(row.short_milli) : t('factory_erp.needs.enough') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <header class="border-b border-line px-5 py-3">
                <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.issues.title') }}</h2>
                <p class="text-[12.5px] text-muted">{{ t('factory_erp.issues.text') }}</p>
            </header>
            <SkeletonRows v-if="issues.loading.value && !issues.data.value" :rows="2" />
            <ErrorState v-else-if="issues.error.value" compact :error="issues.error.value" @retry="issues.reload()" />
            <EmptyState v-else-if="!(issues.data.value?.data ?? []).length" compact :icon="Truck" :title="t('factory_erp.issues.empty_title')" :text="t('factory_erp.issues.empty_text')" />
            <ul v-else class="divide-y divide-line">
                <li v-for="issue in issues.data.value.data" :key="issue.id" class="flex flex-wrap items-start gap-3 px-5 py-3">
                    <AppBadge :tone="issue.kind === 'issue' ? 'brand' : 'neutral'" dot>{{ t(`factory_erp.issues.kinds.${issue.kind}`) }}</AppBadge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[13.5px] text-fg">{{ linesText(issue) }}</p>
                        <p class="text-[12px] text-muted">{{ day(issue.issued_on) }} · {{ setup.warehouseName(issue.warehouse_id) }}<template v-if="issue.note"> · {{ issue.note }}</template></p>
                    </div>
                    <span class="tabular text-[13.5px] font-semibold" :class="issue.kind === 'return' ? 'text-muted' : 'text-fg'">{{ issue.kind === 'return' ? '−' : '' }}{{ money(issue.cost_minor, issue.currency) }}</span>
                </li>
            </ul>
        </section>

        <IssueDialog :open="dialog !== null" :kind="dialog ?? 'issue'" :order="order" :rows="rows" @close="dialog = null" @done="done" />
    </div>
</template>
