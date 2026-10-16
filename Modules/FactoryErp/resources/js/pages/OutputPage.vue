<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Ban, CloudOff, Gauge, Plus, Save } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization } from '@/lib/session';
import { enqueueOffline, offline, offlineRecords } from '@/lib/offline';
import { readPref, writePref } from '@/lib/storage';
import { newOpId } from '@/lib/billing';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { currentHour, piecesOf, sewLeft } from '../lib';

/**
 * A sewing line's output, made for a phone on the floor: choose the line
 * (remembered), the hour (where the company writes by the hour) and the
 * order in production; type good pieces and rejects for each size and
 * colour cut (big boxes, "+10"), and save. Without a network the entry is
 * kept on the phone (Offline mode) and sent when it is back; the lines and
 * orders come from what the phone keeps. Below, the day's entries (a wrong
 * one is voided with a reason) and each line's total and reject rate.
 */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const byHour = computed(() => (setup.rules.value.output_by ?? 'hour') === 'hour');
const canWrite = computed(() => setup.can('produce'));
const hours = Array.from({ length: 24 }, (_, hour) => hour);
const hourText = (hour) => `${formatNumber(hour).padStart(2, formatNumber(0))}:${formatNumber(0)}${formatNumber(0)}`;

// The day: its entries, lines and orders in production (or, offline, what the phone keeps).
const day = reactive({ loading: true, error: null, entries: [], lines: [], orders: [], offline: false, date: '' });
async function load() {
    day.loading = true;
    day.error = null;
    try {
        const { data, meta } = await api.outputs(day.date ? { date: day.date } : {});
        Object.assign(day, { entries: data, lines: meta.lines.filter((line) => line.is_active), orders: meta.orders.filter((order) => order.status === 'in_production'), offline: false, date: meta.date });
    } catch (error) {
        const kept = error.code === 'network' && offline.enabled ? await offlineRecords('factory_erp.output') : [];
        if (kept.length) {
            Object.assign(day, { entries: [], lines: kept.filter((record) => record.type === 'line'), orders: kept.filter((record) => record.type === 'order'), offline: true });
        } else {
            day.error = error;
        }
    } finally {
        day.loading = false;
    }
}
load();

const form = reactive({ lineId: readPref('factory.output.line', ''), hour: currentHour(), orderId: '' });
watch(() => form.lineId, (id) => writePref('factory.output.line', id));
watch(() => day.lines, (lines) => {
    if (!lines.some((line) => line.id === form.lineId)) form.lineId = lines[0]?.id ?? '';
});
watch(() => day.orders, (orders) => {
    if (!orders.some((order) => order.id === form.orderId)) form.orderId = orders[0]?.id ?? '';
});
const order = computed(() => day.orders.find((item) => item.id === form.orderId) ?? null);

// What each size and colour of the order can still take (online only).
const cells = ref({});
const cellsLoading = ref(false);
async function loadCells() {
    cells.value = {};
    if (!form.orderId || day.offline) return;
    cellsLoading.value = true;
    try {
        cells.value = (await api.progress(form.orderId)).data.cells;
    } catch {
        cells.value = {};
    } finally {
        cellsLoading.value = false;
    }
}
watch(() => form.orderId, loadCells, { immediate: true });
const rows = computed(() => {
    if (!order.value) return [];
    const all = order.value.colors.flatMap((color) => order.value.sizes.map((size) => ({ key: `${size}|${color}`, size, color })));
    return day.offline ? all : all.filter((row) => (cells.value[row.key]?.cut ?? 0) > 0);
});

const typed = ref({});
watch(() => form.orderId, () => { typed.value = {}; });
const value = (key, field) => typed.value[key]?.[field] ?? '';
const set = (key, field, text) => { typed.value = { ...typed.value, [key]: { ...(typed.value[key] ?? {}), [field]: text } }; };
const bump = (key, by) => set(key, 'good', String((piecesOf(value(key, 'good')) ?? 0) + by));
const bad = (key) => ['good', 'rejects'].some((field) => piecesOf(value(key, field)) === null);
const over = (key) => !day.offline && (piecesOf(value(key, 'good')) ?? 0) + (piecesOf(value(key, 'rejects')) ?? 0) > sewLeft(cells.value[key]);
const lines = computed(() => rows.value
    .map((row) => ({ size: row.size, color: row.color, good: piecesOf(value(row.key, 'good')) ?? 0, rejects: piecesOf(value(row.key, 'rejects')) ?? 0 }))
    .filter((line) => line.good + line.rejects > 0));
const sum = computed(() => lines.value.reduce((total, line) => ({ good: total.good + line.good, rejects: total.rejects + line.rejects }), { good: 0, rejects: 0 }));
const ready = computed(() => canWrite.value && form.lineId && form.orderId && lines.value.length > 0 && !rows.value.some((row) => bad(row.key) || over(row.key)));

const saving = ref(false);
async function save() {
    const body = { line_id: form.lineId, order_id: form.orderId, ...(day.date ? { worked_on: day.date } : {}), ...(byHour.value ? { hour: form.hour } : {}), lines: lines.value };
    const opId = newOpId();
    saving.value = true;
    try {
        if (globalThis.navigator?.onLine === false || day.offline) {
            await keepOffline(body);
            return;
        }
        await api.createOutput({ ...body, op_id: opId });
        toast.success(t('factory_erp.output.saved', { good: formatNumber(sum.value.good), rejects: formatNumber(sum.value.rejects) }));
        typed.value = {};
        await Promise.all([load(), loadCells()]);
    } catch (error) {
        if (error.code === 'network') await keepOffline(body);
        else toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
async function keepOffline(body) {
    if (!offline.enabled) {
        toast.error(t('factory_erp.output.offline_off'));
        return;
    }
    await enqueueOffline('factory_erp.output', 'create', body);
    toast.success(t('factory_erp.output.kept_offline'));
    typed.value = {};
}

// The day's totals per line, and voiding a wrong entry.
const lineCode = (id) => day.lines.find((line) => line.id === id)?.code ?? '—';
const orderNumber = (id) => day.orders.find((item) => item.id === id)?.number ?? '—';
const perLine = computed(() => {
    const totals = {};
    for (const entry of day.entries.filter((item) => !item.voided_at)) {
        totals[entry.line_id] ??= { good: 0, rejects: 0 };
        totals[entry.line_id].good += entry.good;
        totals[entry.line_id].rejects += entry.rejects;
    }
    return Object.entries(totals).map(([id, total]) => ({ id, ...total, rate: total.good + total.rejects ? Math.round((total.rejects * 1000) / (total.good + total.rejects)) / 10 : 0 }));
});
const warnRate = computed(() => (setup.rules.value.reject_warn_bp ?? 500) / 100);
const voiding = ref(null);
const reason = ref('');
async function voidEntry() {
    saving.value = true;
    try {
        await api.voidOutput(voiding.value.id, { base_version: voiding.value.version, reason: reason.value.trim() });
        toast.success(t('factory_erp.output.voided'));
        voiding.value = null;
        await Promise.all([load(), loadCells()]);
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('factory_erp.conflict') : error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <PageHeader :title="t('factory_erp.output.title')" :description="t('factory_erp.output.text')" />
        <p v-if="day.offline" class="mb-4 flex items-center gap-2 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn"><CloudOff class="size-4 shrink-0" aria-hidden="true" />{{ t('factory_erp.output.offline_note') }}</p>

        <SkeletonRows v-if="day.loading && !day.lines.length" :rows="6" />
        <ErrorState v-else-if="day.error" :error="day.error" @retry="load()" />
        <section v-else-if="!day.lines.length" class="card"><EmptyState :icon="Gauge" :title="t('factory_erp.output.no_lines_title')" :text="t('factory_erp.output.no_lines_text')" /></section>
        <section v-else-if="!day.orders.length" class="card"><EmptyState :icon="Gauge" :title="t('factory_erp.output.no_orders_title')" :text="t('factory_erp.output.no_orders_text')" /></section>
        <template v-else>
            <section v-if="canWrite" class="card mb-5 p-4">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <AppField v-slot="{ id }" :label="t('factory_erp.output.line')">
                        <select :id="id" v-model="form.lineId" class="field-input h-12 text-[15px]">
                            <option v-for="line in day.lines" :key="line.id" :value="line.id">{{ line.code }} · {{ line.name }}</option>
                        </select>
                    </AppField>
                    <AppField v-if="byHour" v-slot="{ id }" :label="t('factory_erp.output.hour')">
                        <select :id="id" v-model.number="form.hour" class="field-input tabular h-12 text-[15px]">
                            <option v-for="hour in hours" :key="hour" :value="hour">{{ hourText(hour) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" class="col-span-2 sm:col-span-1" :label="t('factory_erp.output.order')">
                        <select :id="id" v-model="form.orderId" class="field-input h-12 text-[15px]">
                            <option v-for="item in day.orders" :key="item.id" :value="item.id">{{ item.number }} · {{ item.style_code }}</option>
                        </select>
                    </AppField>
                </div>

                <SkeletonRows v-if="cellsLoading" :rows="3" class="mt-4" />
                <p v-else-if="!rows.length" class="mt-4 rounded-xl bg-subtle px-3 py-3 text-center text-[13px] text-muted">{{ t('factory_erp.output.nothing_cut') }}</p>
                <ul v-else class="mt-4 space-y-2">
                    <li v-for="row in rows" :key="row.key" class="rounded-xl border p-3" :class="over(row.key) || bad(row.key) ? 'border-bad' : 'border-line'">
                        <div class="mb-2 flex items-baseline justify-between gap-2">
                            <span class="text-[15px] font-semibold text-fg">{{ row.size }} / {{ row.color }}</span>
                            <span v-if="!day.offline" class="tabular text-[12px] text-muted">{{ t('factory_erp.output.left', { count: formatNumber(sewLeft(cells[row.key])) }) }}</span>
                        </div>
                        <div class="grid grid-cols-[1fr_auto_1fr] items-end gap-2">
                            <label class="block">
                                <span class="mb-1 block text-[12px] text-muted">{{ t('factory_erp.output.good') }}</span>
                                <input :value="value(row.key, 'good')" inputmode="numeric" class="field-input tabular h-12 text-center text-[18px] font-semibold" :aria-label="t('factory_erp.output.good_of', { cell: `${row.size} / ${row.color}` })" placeholder="0" @input="set(row.key, 'good', $event.target.value)" />
                            </label>
                            <AppButton class="h-12" :icon="Plus" :aria-label="t('factory_erp.output.add_ten', { cell: `${row.size} / ${row.color}` })" @click="bump(row.key, 10)">{{ formatNumber(10) }}</AppButton>
                            <label class="block">
                                <span class="mb-1 block text-[12px] text-muted">{{ t('factory_erp.output.rejects') }}</span>
                                <input :value="value(row.key, 'rejects')" inputmode="numeric" class="field-input tabular h-12 text-center text-[18px]" :aria-label="t('factory_erp.output.rejects_of', { cell: `${row.size} / ${row.color}` })" placeholder="0" @input="set(row.key, 'rejects', $event.target.value)" />
                            </label>
                        </div>
                        <p v-if="over(row.key)" class="mt-1 text-[12px] text-bad" role="alert">{{ t('factory_erp.output.too_many') }}</p>
                    </li>
                </ul>
                <AppButton class="mt-4 h-12 w-full text-[15px]" variant="primary" :icon="Save" :loading="saving" :disabled="!ready" @click="save">
                    {{ t('factory_erp.output.save', { good: formatNumber(sum.good), rejects: formatNumber(sum.rejects) }) }}
                </AppButton>
            </section>

            <section v-if="!day.offline" class="card">
                <header class="border-b border-line px-5 py-3">
                    <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.output.today') }}</h2>
                </header>
                <ul v-if="perLine.length" class="flex flex-wrap gap-2 border-b border-line px-5 py-3">
                    <li v-for="line in perLine" :key="line.id" class="rounded-xl bg-subtle px-3 py-2 text-[13px]">
                        <span class="font-semibold text-fg">{{ lineCode(line.id) }}</span>
                        <span class="tabular ms-2">{{ formatNumber(line.good) }}</span>
                        <span class="tabular ms-2" :class="line.rate >= warnRate ? 'font-semibold text-bad' : 'text-muted'">{{ t('factory_erp.output.rate', { rate: formatNumber(line.rate) }) }}</span>
                    </li>
                </ul>
                <EmptyState v-if="!day.entries.length" compact :icon="Gauge" :title="t('factory_erp.output.empty_title')" :text="t('factory_erp.output.empty_text')" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="entry in day.entries" :key="entry.id" class="flex flex-wrap items-center gap-3 px-5 py-3" :class="entry.voided_at ? 'opacity-70' : ''">
                        <span class="tabular w-14 shrink-0 text-[13px] font-semibold text-fg">{{ entry.hour === null ? '—' : hourText(entry.hour) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13.5px] text-fg" :class="entry.voided_at ? 'line-through' : ''">
                                {{ lineCode(entry.line_id) }} · {{ orderNumber(entry.order_id) }} · {{ t('factory_erp.output.entry', { good: formatNumber(entry.good), rejects: formatNumber(entry.rejects) }) }}
                            </p>
                            <p class="text-[12px] text-muted">{{ entry.lines.map((line) => `${line.size}/${line.color} ${formatNumber(line.good)}`).join(' · ') }}</p>
                            <p v-if="entry.voided_at" class="text-[12px] text-bad">{{ t('factory_erp.cutting.voided_because', { reason: entry.void_reason }) }}</p>
                        </div>
                        <AppBadge v-if="entry.voided_at" tone="bad">{{ t('factory_erp.cutting.void_badge') }}</AppBadge>
                        <AppButton v-else-if="canWrite" size="sm" variant="ghost" :icon="Ban" @click="voiding = entry; reason = ''">{{ t('factory_erp.cutting.void') }}</AppButton>
                    </li>
                </ul>
            </section>
        </template>

        <AppDialog :open="voiding !== null" tone="bad" confirm :icon="Ban" :title="t('factory_erp.output.void_title')" :description="t('factory_erp.output.void_text')" @close="voiding = null">
            <AppField v-slot="{ id }" :label="t('factory_erp.steps.reason')">
                <input :id="id" v-model="reason" class="field-input" maxlength="300" :placeholder="t('factory_erp.output.void_hint')" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="voiding = null">{{ t('factory_erp.cancel') }}</AppButton>
                <AppButton variant="danger" :loading="saving" :disabled="reason.trim().length < 3" @click="voidEntry">{{ t('factory_erp.cutting.void') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
