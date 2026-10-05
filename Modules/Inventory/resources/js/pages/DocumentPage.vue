<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Check, PackageCheck, Plus, ScanBarcode, Send, Trash2, Truck, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { inventoryApi } from '../api';
import { emptyLine, formatQuantity, lineToApi, milliToText, minorToText, quantityToMilli, statusTone } from '../lib';

/**
 * One inventory document. A draft (new or saved) is a form: warehouses,
 * day, who it came from or went to, a reason for adjustments, and lines —
 * scan a barcode or pick an item; a second scan adds one more. Once saved,
 * the steps this reader may take show (the server checks each again):
 * post, dispatch and receive (with what arrived), approve or send back,
 * cancel, delete the draft.
 */
const inventory = inventoryApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const isNew = computed(() => route.name === 'inventory-document-new');
const record = useResource(() => (isNew.value ? Promise.resolve({ data: null }) : inventory.document(route.params.id)));
const document = computed(() => record.data.value?.data ?? null);
const type = computed(() => (isNew.value ? route.params.type : document.value?.type));
const editable = computed(() => isNew.value || document.value?.can?.edit);

const items = useResource(() => inventory.list('items', { active: 1 }));
const warehouses = useResource(() => inventory.list('warehouses'));
const units = useResource(() => inventory.list('units'));
const stockItems = computed(() => (items.data.value?.data ?? []).filter((item) => item.kind === 'stock'));
const itemOf = (id) => (items.data.value?.data ?? []).find((item) => item.id === id);
const unitOf = (item) => (units.data.value?.data ?? []).find((unit) => unit.id === item?.unit_id);
const warehouseName = (id) => (warehouses.data.value?.data ?? []).find((row) => row.id === id)?.name ?? '—';
const activeWarehouses = computed(() => (warehouses.data.value?.data ?? []).filter((row) => row.is_active));
const currency = computed(() => items.data.value?.meta?.currency ?? document.value?.currency);
const money = (amount) => formatMoney({ amount, currency: currency.value });
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });

const form = reactive({ warehouse_id: '', to_warehouse_id: '', document_date: new Date().toISOString().slice(0, 10), counterparty: '', reference: '', reason: '', lines: [emptyLine()] });
const errors = ref({});
const saving = ref(false);
const busy = ref(null);
const opId = crypto.randomUUID();

watch([document, warehouses.data], () => {
    if (document.value && document.value.can?.edit) {
        Object.assign(form, {
            warehouse_id: document.value.warehouse_id, to_warehouse_id: document.value.to_warehouse_id ?? '', document_date: document.value.document_date,
            counterparty: document.value.counterparty ?? '', reference: document.value.reference ?? '', reason: document.value.reason ?? '',
            lines: document.value.lines.map((line) => ({
                item_id: line.item_id, quantity: milliToText(line.quantity_milli), unit_cost: minorToText(line.unit_cost_minor, currency.value),
                batch_number: line.batch_number ?? '', expires_on: line.expires_on ?? '',
            })),
        });
    } else if (isNew.value && !form.warehouse_id && activeWarehouses.value.length) {
        form.warehouse_id = activeWarehouses.value[0].id;
    }
}, { immediate: true });

// Scanning: an exact barcode or SKU adds the item (or one more of it).
const scan = ref('');
async function scanned() {
    const code = scan.value.trim();
    if (!code) return;
    const local = stockItems.value.find((item) => item.barcode === code || item.sku === code);
    let item = local;
    if (!item) {
        try {
            item = (await inventory.lookup(code)).data;
        } catch (error) {
            toast.error(error.message);
            return;
        }
    }
    const line = form.lines.find((row) => row.item_id === item.id);
    if (line) {
        const current = quantityToMilli(line.quantity, 3) ?? 0;
        line.quantity = milliToText(current + 1000);
    } else {
        const blank = form.lines.find((row) => !row.item_id);
        if (blank) Object.assign(blank, { item_id: item.id, quantity: '1' });
        else form.lines.push({ ...emptyLine(), item_id: item.id, quantity: '1' });
    }
    scan.value = '';
}

async function save() {
    const lines = [];
    const bad = {};
    form.lines.forEach((line, index) => {
        if (!line.item_id && !String(line.quantity).trim()) return;
        const item = itemOf(line.item_id);
        const [out, problem] = lineToApi(line, { type: type.value, decimals: unitOf(item)?.decimals ?? 0, currency: currency.value, tracksBatches: item?.track_batches });
        if (problem) bad[`lines.${index}`] = [t(`inventory.line_errors.${problem}`, { decimals: unitOf(item)?.decimals ?? 0 })];
        else lines.push(out);
    });
    if (!lines.length) bad.lines = [t('inventory.line_errors.none')];
    if (Object.keys(bad).length) {
        errors.value = bad;
        return;
    }
    const body = {
        warehouse_id: form.warehouse_id, to_warehouse_id: type.value === 'transfer' ? form.to_warehouse_id || null : null, document_date: form.document_date,
        counterparty: form.counterparty.trim() || null, reference: form.reference.trim() || null, reason: form.reason.trim() || null, lines,
    };
    saving.value = true;
    errors.value = {};
    try {
        if (isNew.value) {
            const { data } = await inventory.createDocument({ ...body, type: type.value, op_id: opId });
            toast.success(t('inventory.document.saved'));
            router.replace({ name: 'inventory-document', params: { id: data.id } });
        } else {
            await inventory.updateDocument(document.value.id, { ...body, base_version: document.value.version });
            toast.success(t('inventory.document.saved'));
            record.reload();
        }
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

// What arrived of a transfer (all, unless changed).
const received = reactive({});
watch(document, (value) => {
    for (const line of value?.lines ?? []) received[line.id] = milliToText(line.quantity_milli);
}, { immediate: true });

async function step(name) {
    const danger = ['reject', 'cancel'].includes(name);
    const confirmed = await confirmAction({
        title: t(`inventory.document.confirm.${name}_title`, { type: t(`inventory.types.${type.value}`) }),
        message: t(`inventory.document.confirm.${name}_text`),
        confirmLabel: t(`inventory.document.steps.${name}`),
        reason: name === 'reject' ? 'required' : 'none',
        danger,
    });
    if (!confirmed) return;
    const body = { base_version: document.value.version };
    if (name === 'reject') body.reason = confirmed.reason;
    if (name === 'receive') {
        body.received = {};
        for (const line of document.value.lines) {
            const value = quantityToMilli(received[line.id], 3);
            if (value === null) {
                toast.error(t('inventory.line_errors.quantity', { decimals: 3 }));
                return;
            }
            body.received[line.id] = value;
        }
    }
    busy.value = name;
    try {
        await inventory.documentStep(document.value.id, name, body);
        toast.success(t(`inventory.document.done.${name}`));
        record.reload();
    } catch (error) {
        if (error.code === 'version_conflict') record.reload();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function remove() {
    const confirmed = await confirmAction({ title: t('inventory.document.confirm.delete_title'), message: t('inventory.document.confirm.delete_text'), confirmLabel: t('inventory.common.delete'), danger: true });
    if (!confirmed) return;
    try {
        await inventory.deleteDocument(document.value.id, document.value.version);
        toast.success(t('inventory.document.deleted'));
        router.push({ name: 'inventory-documents' });
    } catch (error) {
        toast.error(error.message);
    }
}

const showCost = computed(() => ['receipt', 'adjustment'].includes(type.value));
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'inventory-documents' }" :icon="ArrowLeft" class="mb-3">{{ t('inventory.documents.title') }}</AppButton>
        <SkeletonRows v-if="record.loading.value && !document && !isNew" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else>
            <PageHeader :title="isNew ? t(`inventory.document.new.${type}`) : `${t(`inventory.types.${type}`)} ${document?.number ?? ''}`" :description="t(`inventory.document.help.${type}`)">
                <template v-if="document" #eyebrow>
                    <span class="mb-2 flex"><AppBadge :tone="statusTone(document.status)" dot>{{ t(`inventory.status.${document.status}`) }}</AppBadge></span>
                </template>
                <template v-if="document" #actions>
                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="document.can.post" variant="primary" :icon="Check" :loading="busy === 'post'" @click="step('post')">{{ t('inventory.document.steps.post') }}</AppButton>
                        <AppButton v-if="document.can.dispatch" variant="primary" :icon="Truck" :loading="busy === 'dispatch'" @click="step('dispatch')">{{ t('inventory.document.steps.dispatch') }}</AppButton>
                        <AppButton v-if="document.can.receive" variant="primary" :icon="PackageCheck" :loading="busy === 'receive'" @click="step('receive')">{{ t('inventory.document.steps.receive') }}</AppButton>
                        <AppButton v-if="document.can.approve" variant="primary" :icon="Check" :loading="busy === 'approve'" @click="step('approve')">{{ t('inventory.document.steps.approve') }}</AppButton>
                        <AppButton v-if="document.can.reject" variant="ghost" :icon="X" @click="step('reject')">{{ t('inventory.document.steps.reject') }}</AppButton>
                        <AppButton v-if="document.can.cancel" variant="ghost" :icon="X" @click="step('cancel')">{{ t('inventory.document.steps.cancel') }}</AppButton>
                        <AppButton v-if="document.can.edit" variant="ghost" :icon="Trash2" :aria-label="t('inventory.common.delete')" @click="remove" />
                    </div>
                </template>
            </PageHeader>

            <p v-if="document?.reject_reason && document.status === 'draft'" class="mb-4 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn" role="status">{{ t('inventory.document.sent_back', { reason: document.reject_reason }) }}</p>
            <p v-if="document?.status === 'pending_approval'" class="mb-4 rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted" role="status">{{ t('inventory.document.waiting') }}</p>

            <!-- A draft: the form. -->
            <form v-if="editable" id="inventory-document" novalidate @submit.prevent="save">
                <section class="card mb-5 grid grid-cols-1 gap-4 p-5 sm:grid-cols-3">
                    <AppField v-slot="{ id }" :label="t(type === 'transfer' ? 'inventory.document.from' : 'inventory.common.warehouse')" :error="errors.warehouse_id?.[0]">
                        <select :id="id" v-model="form.warehouse_id" class="field-input">
                            <option v-for="warehouse in activeWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                        </select>
                    </AppField>
                    <AppField v-if="type === 'transfer'" v-slot="{ id }" :label="t('inventory.document.to')" :error="errors.to_warehouse_id?.[0]">
                        <select :id="id" v-model="form.to_warehouse_id" class="field-input">
                            <option value="" disabled>{{ t('inventory.document.choose_to') }}</option>
                            <option v-for="warehouse in activeWarehouses.filter((row) => row.id !== form.warehouse_id)" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('inventory.document.date')" :error="errors.document_date?.[0]">
                        <input :id="id" v-model="form.document_date" type="date" class="field-input" />
                    </AppField>
                    <template v-if="['receipt', 'issue'].includes(type)">
                        <AppField v-slot="{ id }" :label="t(`inventory.document.counterparty_${type}`)" :error="errors.counterparty?.[0]" optional>
                            <input :id="id" v-model="form.counterparty" class="field-input" maxlength="150" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('inventory.document.reference')" :error="errors.reference?.[0]" optional>
                            <input :id="id" v-model="form.reference" class="field-input" maxlength="80" :placeholder="t('inventory.document.reference_hint')" />
                        </AppField>
                    </template>
                    <AppField v-if="type === 'adjustment'" v-slot="{ id }" :label="t('inventory.document.reason')" :error="errors.reason?.[0]" class="sm:col-span-2">
                        <input :id="id" v-model="form.reason" class="field-input" maxlength="300" :placeholder="t('inventory.document.reason_hint')" />
                    </AppField>
                </section>

                <section class="card mb-5">
                    <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                        <h2 class="min-w-0 flex-1 text-[14.5px] font-semibold">{{ t('inventory.document.lines') }}</h2>
                        <div class="relative w-full sm:w-72">
                            <ScanBarcode class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                            <input v-model="scan" class="field-input ps-9 font-mono" dir="ltr" :placeholder="t('inventory.document.scan')" :aria-label="t('inventory.document.scan')" @keydown.enter.prevent="scanned" />
                        </div>
                    </header>
                    <p v-if="errors.lines" class="px-5 pt-3 text-[12.5px] text-bad">{{ errors.lines[0] }}</p>
                    <ul class="divide-y divide-line">
                        <li v-for="(line, index) in form.lines" :key="index" class="grid grid-cols-1 gap-2 px-5 py-3 sm:grid-cols-[minmax(0,1fr)_8rem_9rem_auto] sm:items-start">
                            <div class="min-w-0">
                                <select v-model="line.item_id" class="field-input" :aria-label="t('inventory.document.item')">
                                    <option value="">{{ t('inventory.document.choose_item') }}</option>
                                    <option v-for="item in stockItems" :key="item.id" :value="item.id">{{ item.name }} · {{ item.sku }}</option>
                                </select>
                                <div v-if="itemOf(line.item_id)?.track_batches" class="mt-2 grid grid-cols-2 gap-2">
                                    <input v-model="line.batch_number" class="field-input font-mono" dir="ltr" maxlength="60" :placeholder="t('inventory.document.batch')" :aria-label="t('inventory.document.batch')" />
                                    <input v-if="type !== 'issue' && type !== 'transfer'" v-model="line.expires_on" type="date" class="field-input" :aria-label="t('inventory.document.expires')" />
                                </div>
                                <p v-if="errors[`lines.${index}`] || errors[`lines.${index}.quantity_milli`] || errors[`lines.${index}.item_id`] || errors[`lines.${index}.batch_number`]" class="mt-1 text-[12px] text-bad">
                                    {{ (errors[`lines.${index}`] ?? errors[`lines.${index}.quantity_milli`] ?? errors[`lines.${index}.item_id`] ?? errors[`lines.${index}.batch_number`])[0] }}
                                </p>
                            </div>
                            <label class="grid gap-0.5 text-[11.5px] text-muted">
                                {{ t('inventory.document.quantity') }}{{ unitOf(itemOf(line.item_id)) ? ` (${unitOf(itemOf(line.item_id)).name})` : '' }}
                                <input v-model="line.quantity" inputmode="decimal" class="field-input tabular text-end text-[14px] text-fg" dir="ltr" :placeholder="type === 'adjustment' ? '−2 / 5' : '0'" />
                            </label>
                            <label v-if="showCost" class="grid gap-0.5 text-[11.5px] text-muted">
                                {{ t('inventory.document.unit_cost') }}
                                <input v-model="line.unit_cost" inputmode="decimal" class="field-input tabular text-end text-[14px] text-fg" dir="ltr" :placeholder="type === 'adjustment' ? t('inventory.document.current_cost') : '0.00'" />
                            </label>
                            <span v-else class="hidden sm:block" />
                            <AppButton size="icon-sm" variant="ghost" :icon="Trash2" class="sm:mt-5" :aria-label="t('inventory.document.remove_line')" @click="form.lines.length > 1 ? form.lines.splice(index, 1) : Object.assign(line, emptyLine())" />
                        </li>
                    </ul>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3">
                        <AppButton size="sm" variant="ghost" :icon="Plus" @click="form.lines.push(emptyLine())">{{ t('inventory.document.add_line') }}</AppButton>
                        <AppButton type="submit" form="inventory-document" variant="primary" :icon="Send" :loading="saving">{{ isNew ? t('inventory.document.save_draft') : t('inventory.common.save') }}</AppButton>
                    </div>
                </section>
            </form>

            <!-- Saved and no longer a draft: what it holds. -->
            <template v-else-if="document">
                <section class="card mb-5 grid grid-cols-2 gap-4 p-5 sm:grid-cols-4">
                    <div><p class="text-[12.5px] text-muted">{{ t(type === 'transfer' ? 'inventory.document.from' : 'inventory.common.warehouse') }}</p><p class="text-[14px] font-medium">{{ warehouseName(document.warehouse_id) }}</p></div>
                    <div v-if="document.to_warehouse_id"><p class="text-[12.5px] text-muted">{{ t('inventory.document.to') }}</p><p class="text-[14px] font-medium">{{ warehouseName(document.to_warehouse_id) }}</p></div>
                    <div><p class="text-[12.5px] text-muted">{{ t('inventory.document.date') }}</p><p class="text-[14px] font-medium">{{ day(document.document_date) }}</p></div>
                    <div><p class="text-[12.5px] text-muted">{{ t('inventory.document.value') }}</p><p class="tabular text-[16px] font-semibold text-brand-text">{{ money(Math.abs(document.value_minor)) }}</p></div>
                    <div v-if="document.counterparty || document.reference" class="col-span-2"><p class="text-[12.5px] text-muted">{{ t(`inventory.document.counterparty_${type === 'issue' ? 'issue' : 'receipt'}`) }}</p><p class="text-[14px]">{{ document.counterparty ?? '—' }}<template v-if="document.reference"> · {{ document.reference }}</template></p></div>
                    <div v-if="document.reason" class="col-span-2"><p class="text-[12.5px] text-muted">{{ t('inventory.document.reason') }}</p><p class="text-[14px]">{{ document.reason }}</p></div>
                </section>
                <section class="card">
                    <h2 class="border-b border-line px-5 py-3 text-[14.5px] font-semibold">{{ t('inventory.document.lines') }}</h2>
                    <ul class="divide-y divide-line">
                        <li v-for="line in document.lines" :key="line.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-[13.5px]">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium">{{ itemOf(line.item_id)?.name ?? '—' }} <span class="font-mono text-[11.5px] text-muted" dir="ltr">{{ itemOf(line.item_id)?.sku }}</span></span>
                                <span v-if="line.batch_number" class="block font-mono text-[12px] text-muted" dir="ltr">{{ line.batch_number }}<template v-if="line.expires_on"> · {{ day(line.expires_on) }}</template></span>
                            </span>
                            <span class="tabular">{{ formatQuantity(line.quantity_milli) }} {{ unitOf(itemOf(line.item_id))?.name }}</span>
                            <label v-if="document.can.receive" class="flex items-center gap-2 text-[12px] text-muted">
                                {{ t('inventory.document.arrived') }}
                                <input v-model="received[line.id]" inputmode="decimal" class="field-input w-24 tabular text-end" dir="ltr" />
                            </label>
                            <span v-else-if="line.received_milli !== null" class="text-[12px] text-muted">{{ t('inventory.document.arrived_was', { quantity: formatQuantity(line.received_milli) }) }}</span>
                            <span class="tabular w-28 text-end font-semibold">{{ document.status === 'posted' || document.status === 'in_transit' ? money(Math.abs(line.value_minor)) : '' }}</span>
                        </li>
                    </ul>
                </section>
            </template>
        </template>
    </div>
</template>
