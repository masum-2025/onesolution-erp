<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Landmark, Lock, Save, SearchX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { lcFormToApi, lcToForm, stagePercent } from '../lib';
import LcForm from '../components/LcForm.vue';

/**
 * One letter of credit: what it has paid for against its amount (with the
 * tolerance), its terms (changed with its version while open), the
 * buyer's orders it pays for (ticked), its shipments, and closing it.
 */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const route = useRoute();
const view = useResource(() => api.lc(route.params.id));
const lc = computed(() => view.data.value?.data ?? null);
const meta = computed(() => view.data.value?.meta ?? {});
const open = computed(() => lc.value?.status === 'open');
const canEdit = computed(() => open.value && setup.can('merchandise'));
const money = (minor) => formatMoney({ amount: minor, currency: lc.value?.currency });
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });

const form = ref(lcToForm());
const orderIds = ref([]);
watch(lc, (value) => {
    if (!value) return;
    form.value = lcToForm(value);
    orderIds.value = [...value.order_ids];
}, { immediate: true });
const body = computed(() => lcFormToApi(form.value));
const errors = ref({});
const busy = ref(false);
async function save(extra = {}) {
    busy.value = true;
    errors.value = {};
    try {
        await api.updateLc(lc.value.id, { ...body.value, order_ids: orderIds.value, ...extra, base_version: lc.value.version });
        toast.success(t('factory_erp.lcs.saved'));
        view.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('factory_erp.conflict'));
            view.reload();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
async function close() {
    const yes = await confirmAction({ title: t('factory_erp.lcs.close_title', { number: lc.value.number }), message: t('factory_erp.lcs.close_text'), confirmLabel: t('factory_erp.lcs.close') });
    if (yes) await save({ status: 'closed' });
}
const toggleOrder = (id) => {
    const index = orderIds.value.indexOf(id);
    if (index >= 0) orderIds.value.splice(index, 1);
    else orderIds.value.push(id);
};
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'fac-lcs' }" :icon="ArrowLeft" class="mb-3">{{ t('factory_erp.lcs.title') }}</AppButton>
        <SkeletonRows v-if="view.loading.value && !lc" :rows="8" />
        <section v-else-if="view.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('factory_erp.lcs.not_found')" /></section>
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <template v-else-if="lc">
            <PageHeader :title="lc.number" :description="`${meta.buyer?.name ?? ''} · ${lc.issuing_bank}`">
                <template #eyebrow>
                    <AppBadge class="mb-2" :tone="open ? 'ok' : 'neutral'" dot>{{ t(`factory_erp.lcs.statuses.${lc.status}`) }}</AppBadge>
                </template>
                <template v-if="canEdit" #actions>
                    <AppButton :icon="Lock" @click="close">{{ t('factory_erp.lcs.close') }}</AppButton>
                    <AppButton variant="primary" :icon="Save" :loading="busy" :disabled="!body" @click="save()">{{ t('factory_erp.save') }}</AppButton>
                </template>
            </PageHeader>

            <section class="card mb-5 p-5">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-[12px] text-muted">{{ t('factory_erp.lcs.used') }}</p>
                        <p class="tabular text-[20px] font-bold text-fg">{{ money(lc.usage.shipped) }} <span class="text-[13px] font-medium text-muted">/ {{ money(lc.amount_minor) }}</span></p>
                    </div>
                    <p class="text-[12.5px] text-muted">{{ t('factory_erp.lcs.usage_text', { left: money(lc.usage.left), ceiling: money(lc.ceiling_minor), drafts: money(lc.usage.drafts) }) }}</p>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-subtle" role="progressbar" :aria-valuenow="stagePercent(lc.usage.shipped, lc.amount_minor)" aria-valuemin="0" aria-valuemax="100" :aria-label="t('factory_erp.lcs.used')">
                    <div class="h-full rounded-full bg-brand" :style="{ inlineSize: `${stagePercent(lc.usage.shipped, lc.amount_minor)}%` }" />
                </div>
            </section>

            <section class="card mb-5 p-5">
                <LcForm v-model="form" :documents="meta.documents ?? []" :disabled="!canEdit" :errors="errors" />
            </section>

            <section class="card mb-5">
                <header class="border-b border-line px-5 py-3">
                    <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.lcs.orders') }}</h2>
                    <p class="text-[12.5px] text-muted">{{ t('factory_erp.lcs.orders_text') }}</p>
                </header>
                <EmptyState v-if="!(meta.orders ?? []).length" compact :icon="Landmark" :title="t('factory_erp.lcs.no_orders')" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="order in meta.orders" :key="order.id">
                        <label class="flex items-center gap-3 px-5 py-2.5 text-[13.5px]">
                            <input type="checkbox" class="size-4 accent-brand" :checked="orderIds.includes(order.id)" :disabled="!canEdit" @change="toggleOrder(order.id)" />
                            <span class="font-mono font-medium" dir="ltr">{{ order.number }}</span>
                            <span class="text-muted">{{ order.po_number ?? '' }}</span>
                            <span class="ms-auto text-[12px] text-muted">{{ t(`factory_erp.statuses.${order.status}`) }} · {{ day(order.ship_on) }}</span>
                        </label>
                    </li>
                </ul>
            </section>

            <section class="card">
                <header class="border-b border-line px-5 py-3"><h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.shipments.title') }}</h2></header>
                <EmptyState v-if="!(meta.shipments ?? []).length" compact :icon="Landmark" :title="t('factory_erp.lcs.no_shipments')" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in meta.shipments" :key="item.id">
                        <RouterLink :to="{ name: 'fac-shipment', params: { id: item.id } }" class="flex flex-wrap items-center gap-3 px-5 py-3 hover:bg-subtle/60">
                            <span class="font-mono text-[13px] font-semibold" dir="ltr">{{ item.number }}</span>
                            <AppBadge :tone="item.status === 'shipped' ? 'ok' : 'neutral'" dot>{{ t(`factory_erp.shipments.statuses.${item.status}`) }}</AppBadge>
                            <AppBadge v-if="item.discrepancies.length" tone="warn">{{ t('factory_erp.lcs.with_discrepancies', { count: formatNumber(item.discrepancies.length) }) }}</AppBadge>
                            <span class="min-w-0 flex-1 text-[12.5px] text-muted">{{ day(item.ship_on) }}</span>
                            <span class="tabular font-semibold">{{ money(item.value_minor) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
