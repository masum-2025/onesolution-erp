<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, CheckCheck, Factory, Save, SearchX, Ship, XCircle } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppTabs from '@/components/AppTabs.vue';
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
import { fieldText, orderTone, piecesOf } from '../lib';
import SizeColorSheet from '../components/SizeColorSheet.vue';
import MaterialsPanel from '../components/MaterialsPanel.vue';
import CuttingPanel from '../components/CuttingPanel.vue';
import SewingPanel from '../components/SewingPanel.vue';
import QualityPanel from '../components/QualityPanel.vue';
import ShipmentsPanel from '../components/ShipmentsPanel.vue';

/**
 * One buyer's order: buyer and style, ship date and price, own fields; the
 * size × colour sheet (typed while draft or confirmed, then read-only);
 * the steps (confirm, into production, shipped, cancel with a reason); in
 * tabs, the materials (needed, given to the floor, taken back) and the
 * cutting table. The tab is kept in the address (?tab=).
 */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const route = useRoute();
const router = useRouter();
const TABS = ['sheet', 'materials', 'cutting', 'sewing', 'quality', 'shipments'];
const tab = computed({
    get: () => (TABS.includes(route.query.tab) ? route.query.tab : 'sheet'),
    set: (value) => router.replace({ query: { ...route.query, tab: value } }),
});
const tabs = computed(() => TABS.map((key) => ({ key, label: t(`factory_erp.order_tabs.${key}`) })));
const view = useResource(() => api.order(route.params.id));
const order = computed(() => view.data.value?.data ?? null);
const style = computed(() => view.data.value?.meta?.style ?? null);
const buyer = computed(() => view.data.value?.meta?.buyer ?? null);
const open = computed(() => order.value && ['draft', 'confirmed'].includes(order.value.status) && setup.can('merchandise'));
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const money = (minor) => formatMoney({ amount: minor, currency: order.value?.currency });

// The sheet: what was saved, then what is typed.
const sheet = ref({});
watch(order, (value) => {
    sheet.value = Object.fromEntries((value?.lines ?? []).map((line) => [`${line.size}|${line.color}`, String(line.quantity)]));
}, { immediate: true });
const sheetBad = computed(() => Object.values(sheet.value).some((text) => piecesOf(text) === null));
const dirty = computed(() => {
    const saved = Object.fromEntries((order.value?.lines ?? []).map((line) => [`${line.size}|${line.color}`, line.quantity]));
    const typed = Object.fromEntries(Object.entries(sheet.value).map(([key, text]) => [key, piecesOf(text) ?? -1]).filter(([, value]) => value !== 0));
    return JSON.stringify(Object.entries(saved).sort()) !== JSON.stringify(Object.entries(typed).sort());
});
const busy = ref(false);
async function saveSheet() {
    busy.value = true;
    const lines = Object.entries(sheet.value).map(([key, text]) => {
        const [size, color] = key.split('|');
        return { size, color, quantity: piecesOf(text) ?? 0 };
    });
    try {
        await api.updateOrder(order.value.id, { lines, base_version: order.value.version });
        toast.success(t('factory_erp.orders.sheet_saved'));
        view.reload();
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('factory_erp.conflict') : error.message);
        if (error.code === 'version_conflict') view.reload();
    } finally {
        busy.value = false;
    }
}

async function step(name) {
    if (name !== 'cancel') {
        const yes = await confirmAction({ title: t(`factory_erp.steps.${name}_title`, { number: order.value.number }), message: t(`factory_erp.steps.${name}_text`), confirmLabel: t(`factory_erp.steps.${name}`) });
        if (!yes) return;
    }
    busy.value = true;
    try {
        await api.step(order.value.id, name, { base_version: order.value.version, ...(name === 'cancel' ? { reason: reason.value.trim() } : {}) });
        toast.success(t(`factory_erp.steps.${name}_done`));
        cancelling.value = false;
        view.reload();
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('factory_erp.conflict') : error.message);
        view.reload();
    } finally {
        busy.value = false;
    }
}
const cancelling = ref(false);
const reason = ref('');

const fields = computed(() => setup.fields('order').filter((field) => order.value?.extra?.[field.key] !== undefined));
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'fac-orders' }" :icon="ArrowLeft" class="mb-3">{{ t('factory_erp.orders.back') }}</AppButton>
        <SkeletonRows v-if="view.loading.value && !order" :rows="8" />
        <section v-else-if="view.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('factory_erp.orders.not_found')" /></section>
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <template v-else-if="order">
            <PageHeader :title="order.number" :description="`${style?.code ?? ''} · ${style?.name ?? ''}`">
                <template #eyebrow>
                    <AppBadge class="mb-2" :tone="orderTone(order.status)" dot>{{ t(`factory_erp.statuses.${order.status}`) }}</AppBadge>
                </template>
                <template v-if="setup.can('merchandise')" #actions>
                    <AppButton v-if="order.status === 'draft'" variant="primary" :icon="CheckCheck" :disabled="dirty" @click="step('confirm')">{{ t('factory_erp.steps.confirm') }}</AppButton>
                    <AppButton v-if="order.status === 'confirmed'" variant="primary" :icon="Factory" :disabled="dirty" @click="step('start')">{{ t('factory_erp.steps.start') }}</AppButton>
                    <AppButton v-if="order.status === 'in_production'" variant="primary" :icon="Ship" @click="step('ship')">{{ t('factory_erp.steps.ship') }}</AppButton>
                    <AppButton v-if="['draft', 'confirmed'].includes(order.status)" variant="danger-soft" :icon="XCircle" @click="cancelling = true; reason = ''">{{ t('factory_erp.steps.cancel') }}</AppButton>
                </template>
            </PageHeader>

            <p v-if="order.status === 'cancelled'" class="mb-4 rounded-xl bg-bad-soft px-4 py-3 text-[13px] text-bad">{{ t('factory_erp.orders.cancelled_because', { reason: order.cancelled_reason }) }}</p>

            <dl class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="card p-4"><dt class="text-[12px] text-muted">{{ t('factory_erp.fields_.buyer') }}</dt><dd class="truncate text-[14.5px] font-semibold text-fg">{{ buyer?.name ?? '—' }}</dd></div>
                <div class="card p-4"><dt class="text-[12px] text-muted">{{ t('factory_erp.fields_.ship_on') }}</dt><dd class="text-[14.5px] font-semibold text-fg">{{ day(order.ship_on) }}</dd></div>
                <div class="card p-4"><dt class="text-[12px] text-muted">{{ t('factory_erp.orders.pieces_total') }}</dt><dd class="tabular text-[19px] font-bold text-fg">{{ formatNumber(order.pieces) }}</dd></div>
                <div class="card p-4"><dt class="text-[12px] text-muted">{{ t('factory_erp.orders.value', { price: money(order.unit_price_minor) }) }}</dt><dd class="tabular text-[19px] font-bold text-fg">{{ money(order.value_minor) }}</dd></div>
            </dl>
            <dl v-if="order.po_number || fields.length" class="card mb-5 grid grid-cols-1 gap-x-6 gap-y-3 p-5 text-[13.5px] sm:grid-cols-3">
                <div v-if="order.po_number"><dt class="text-muted">{{ t('factory_erp.fields_.po_number') }}</dt><dd class="font-mono font-medium" dir="ltr">{{ order.po_number }}</dd></div>
                <div v-for="field in fields" :key="field.key"><dt class="text-muted">{{ field.label }}</dt><dd class="font-medium">{{ fieldText(field, order.extra[field.key], money) }}</dd></div>
            </dl>

            <AppTabs v-model="tab" :tabs="tabs" :label="order.number" class="mb-4" />
            <section v-if="tab === 'sheet' && style" class="card">
                <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.sheet.title') }}</h2>
                        <p class="text-[12.5px] text-muted">{{ open ? t('factory_erp.sheet.text_open') : t('factory_erp.sheet.text_closed') }}</p>
                    </div>
                    <AppButton v-if="open" size="sm" variant="primary" :icon="Save" :loading="busy" :disabled="!dirty || sheetBad" @click="saveSheet">{{ t('factory_erp.save') }}</AppButton>
                </header>
                <div class="p-3 sm:p-4">
                    <SizeColorSheet v-model="sheet" :sizes="style.sizes" :colors="style.colors" :readonly="!open" />
                </div>
            </section>
            <MaterialsPanel v-else-if="tab === 'materials'" :order="order" />
            <CuttingPanel v-else-if="tab === 'cutting' && style" :order="order" :style="style" @changed="view.reload()" />
            <SewingPanel v-else-if="tab === 'sewing' && style" :order="order" :style="style" />
            <QualityPanel v-else-if="tab === 'quality'" :order="order" />
            <ShipmentsPanel v-else-if="tab === 'shipments'" :order="order" />

            <AppDialog :open="cancelling" tone="bad" :icon="XCircle" :title="t('factory_erp.steps.cancel_title', { number: order.number })" :description="t('factory_erp.steps.cancel_text')" @close="cancelling = false">
                <AppField v-slot="{ id }" :label="t('factory_erp.steps.reason')">
                    <input :id="id" v-model="reason" class="field-input" maxlength="300" :placeholder="t('factory_erp.steps.reason_hint')" />
                </AppField>
                <template #footer>
                    <AppButton variant="ghost" @click="cancelling = false">{{ t('factory_erp.cancel') }}</AppButton>
                    <AppButton variant="danger" :loading="busy" :disabled="reason.trim().length < 3" @click="step('cancel')">{{ t('factory_erp.steps.cancel') }}</AppButton>
                </template>
            </AppDialog>
        </template>
    </div>
</template>
