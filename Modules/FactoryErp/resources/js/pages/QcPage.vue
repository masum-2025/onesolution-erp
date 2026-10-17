<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Ban, Minus, Plus, Save, ShieldCheck } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization } from '@/lib/session';
import { readPref, writePref } from '@/lib/storage';
import { newOpId } from '@/lib/billing';
import { formatDecimal, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { aqlSample, currentHour, dhuBp, dhuText, piecesOf } from '../lib';

/**
 * Quality checks, made for a phone: an end-line check (line, hour, order,
 * pieces checked, a big button per kind of defect to count it, the DHU as
 * it goes) or an order's final inspection (lot size; the sample and the
 * most defects accepted from the AQL table; pass or fail as counted).
 * Below, the day's checks; a wrong one is voided with a reason.
 */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const canCheck = computed(() => setup.can('qc'));
const hours = Array.from({ length: 24 }, (_, hour) => hour);
const hourText = (hour) => `${formatNumber(hour).padStart(2, formatNumber(0))}:${formatNumber(0)}${formatNumber(0)}`;

const day = reactive({ loading: true, error: null, items: [], types: [], lines: [], orders: [], levels: [], aql: '' });
async function load() {
    day.loading = true;
    day.error = null;
    try {
        const { data, meta } = await api.inspections();
        Object.assign(day, { items: data, types: meta.defect_types, lines: meta.lines, orders: meta.orders.filter((order) => order.status === 'in_production'), levels: meta.aql_levels, aql: meta.aql });
    } catch (error) {
        day.error = error;
    } finally {
        day.loading = false;
    }
}
load();

const kind = ref(readPref('factory.qc.kind', 'endline'));
watch(kind, (value) => writePref('factory.qc.kind', value));
const kinds = computed(() => [{ value: 'endline', label: t('factory_erp.qc.endline') }, { value: 'final', label: t('factory_erp.qc.final') }]);
const form = reactive({ lineId: readPref('factory.qc.line', ''), hour: currentHour(), orderId: '', checked: '', lot: '', note: '' });
watch(() => form.lineId, (id) => writePref('factory.qc.line', id));
watch(() => day.lines, (lines) => { if (!lines.some((line) => line.id === form.lineId)) form.lineId = lines[0]?.id ?? ''; });
watch(() => day.orders, (orders) => { if (!orders.some((order) => order.id === form.orderId)) form.orderId = orders[0]?.id ?? ''; });

const counts = ref({});
const bump = (id, by) => { counts.value = { ...counts.value, [id]: Math.max(0, (counts.value[id] ?? 0) + by) }; };
const defects = computed(() => Object.values(counts.value).reduce((sum, count) => sum + count, 0));
const checked = computed(() => piecesOf(form.checked));
const sample = computed(() => aqlSample(day.levels, piecesOf(form.lot)));
const liveDhu = computed(() => dhuBp(defects.value, checked.value ?? 0));
const verdict = computed(() => (sample.value ? (defects.value <= sample.value.accept ? 'pass' : 'fail') : null));
const ready = computed(() => canCheck.value && form.orderId && (kind.value === 'final' ? sample.value !== null : form.lineId && checked.value > 0));

const saving = ref(false);
let opId = newOpId();
async function save() {
    saving.value = true;
    const body = {
        kind: kind.value, order_id: form.orderId, op_id: opId,
        defects: Object.entries(counts.value).filter(([, count]) => count > 0).map(([id, count]) => ({ defect_type_id: id, count })),
        ...(kind.value === 'final' ? { lot_size: piecesOf(form.lot) } : { line_id: form.lineId, hour: form.hour, checked: checked.value }),
        ...(form.note.trim() ? { note: form.note.trim() } : {}),
    };
    try {
        const { data } = await api.createInspection(body);
        toast.success(kind.value === 'final' ? t(`factory_erp.qc.final_${data.result}`) : t('factory_erp.qc.saved', { dhu: formatDecimal(dhuText(data.dhu_bp)) }));
        counts.value = {};
        Object.assign(form, { checked: '', lot: '', note: '' });
        opId = newOpId();
        await load();
    } catch (error) {
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const typeName = (id) => day.types.find((type) => type.id === id)?.name ?? '—';
const lineCode = (id) => day.lines.find((line) => line.id === id)?.code ?? '—';
const orderNumber = (id) => day.orders.find((order) => order.id === id)?.number ?? '—';
const voiding = ref(null);
const reason = ref('');
async function voidCheck() {
    saving.value = true;
    try {
        await api.voidInspection(voiding.value.id, { base_version: voiding.value.version, reason: reason.value.trim() });
        toast.success(t('factory_erp.qc.voided'));
        voiding.value = null;
        await load();
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('factory_erp.conflict') : error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <PageHeader :title="t('factory_erp.qc.title')" :description="t('factory_erp.qc.text')" />
        <SkeletonRows v-if="day.loading && !day.types.length" :rows="6" />
        <ErrorState v-else-if="day.error" :error="day.error" @retry="load()" />
        <section v-else-if="!day.orders.length" class="card"><EmptyState :icon="ShieldCheck" :title="t('factory_erp.output.no_orders_title')" :text="t('factory_erp.output.no_orders_text')" /></section>
        <template v-else>
            <section v-if="canCheck" class="card mb-5 p-4">
                <AppSegmented v-model="kind" :options="kinds" class="mb-4" />
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <AppField v-if="kind === 'endline'" v-slot="{ id }" :label="t('factory_erp.output.line')">
                        <select :id="id" v-model="form.lineId" class="field-input h-12 text-[15px]">
                            <option v-for="line in day.lines" :key="line.id" :value="line.id">{{ line.code }} · {{ line.name }}</option>
                        </select>
                    </AppField>
                    <AppField v-if="kind === 'endline'" v-slot="{ id }" :label="t('factory_erp.output.hour')">
                        <select :id="id" v-model.number="form.hour" class="field-input tabular h-12 text-[15px]">
                            <option v-for="hour in hours" :key="hour" :value="hour">{{ hourText(hour) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" class="col-span-2 sm:col-span-1" :label="t('factory_erp.output.order')">
                        <select :id="id" v-model="form.orderId" class="field-input h-12 text-[15px]">
                            <option v-for="order in day.orders" :key="order.id" :value="order.id">{{ order.number }} · {{ order.style_code }}</option>
                        </select>
                    </AppField>
                    <AppField v-if="kind === 'endline'" v-slot="{ id }" class="col-span-2 sm:col-span-3" :label="t('factory_erp.qc.checked')">
                        <input :id="id" v-model="form.checked" inputmode="numeric" class="field-input tabular h-12 text-center text-[18px] font-semibold" placeholder="0" />
                    </AppField>
                    <AppField v-else v-slot="{ id, describedby }" class="col-span-2 sm:col-span-3" :label="t('factory_erp.qc.lot')" :hint="t('factory_erp.qc.lot_hint', { aql: day.aql })">
                        <input :id="id" v-model="form.lot" inputmode="numeric" class="field-input tabular h-12 text-center text-[18px] font-semibold" :aria-describedby="describedby" placeholder="0" />
                    </AppField>
                </div>
                <p v-if="kind === 'final' && sample" class="mt-3 rounded-xl bg-brand-soft px-3 py-2 text-[13.5px] text-brand-text">
                    {{ t('factory_erp.qc.sample', { sample: formatNumber(sample.sample), accept: formatNumber(sample.accept) }) }}
                </p>

                <h3 class="mt-4 mb-2 text-[13px] font-semibold text-fg">{{ t('factory_erp.qc.defects') }}</h3>
                <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <li v-for="type in day.types" :key="type.id" class="flex items-center gap-2 rounded-xl border p-2" :class="counts[type.id] ? 'border-bad bg-bad-soft/40' : 'border-line'">
                        <button type="button" class="min-h-12 min-w-0 flex-1 rounded-lg px-2 text-start text-[14px] font-medium text-fg" :aria-label="t('factory_erp.qc.add_one', { defect: type.name })" @click="bump(type.id, 1)">
                            {{ type.name }}
                        </button>
                        <AppButton size="icon-sm" variant="ghost" :icon="Minus" :disabled="!counts[type.id]" :aria-label="t('factory_erp.qc.remove_one', { defect: type.name })" @click="bump(type.id, -1)" />
                        <span class="tabular w-8 text-center text-[18px] font-bold" :class="counts[type.id] ? 'text-bad' : 'text-muted'">{{ formatNumber(counts[type.id] ?? 0) }}</span>
                        <AppButton size="icon-sm" :icon="Plus" :aria-label="t('factory_erp.qc.add_one', { defect: type.name })" @click="bump(type.id, 1)" />
                    </li>
                </ul>

                <div class="mt-4 flex flex-wrap items-center gap-3 rounded-xl bg-subtle px-3 py-2 text-[14px]">
                    <span>{{ t('factory_erp.qc.found', { count: formatNumber(defects) }) }}</span>
                    <span v-if="kind === 'endline'" class="tabular font-semibold">{{ t('factory_erp.qc.dhu', { dhu: formatDecimal(dhuText(liveDhu)) }) }}</span>
                    <AppBadge v-else-if="verdict" :tone="verdict === 'pass' ? 'ok' : 'bad'" dot>{{ t(`factory_erp.qc.result_${verdict}`) }}</AppBadge>
                </div>
                <AppField v-slot="{ id }" class="mt-3" :label="t('factory_erp.issues.note')">
                    <input :id="id" v-model="form.note" class="field-input" maxlength="300" />
                </AppField>
                <AppButton class="mt-4 h-12 w-full text-[15px]" variant="primary" :icon="Save" :loading="saving" :disabled="!ready" @click="save">{{ t('factory_erp.qc.save') }}</AppButton>
            </section>

            <section class="card">
                <header class="border-b border-line px-5 py-3"><h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.output.today') }}</h2></header>
                <EmptyState v-if="!day.items.length" compact :icon="ShieldCheck" :title="t('factory_erp.qc.empty_title')" :text="t('factory_erp.qc.empty_text')" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in day.items" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3" :class="item.voided_at ? 'opacity-70' : ''">
                        <AppBadge v-if="item.kind === 'final'" :tone="item.result === 'pass' ? 'ok' : 'bad'" dot>{{ t(`factory_erp.qc.result_${item.result}`) }}</AppBadge>
                        <span v-else class="tabular w-14 shrink-0 text-[13px] font-semibold text-fg">{{ item.hour === null ? '—' : hourText(item.hour) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13.5px] text-fg" :class="item.voided_at ? 'line-through' : ''">
                                <template v-if="item.kind === 'endline'">{{ lineCode(item.line_id) }} · </template>{{ orderNumber(item.order_id) }} ·
                                {{ item.kind === 'final'
                                    ? t('factory_erp.qc.final_line', { sample: formatNumber(item.checked), defects: formatNumber(item.defects), accept: formatNumber(item.accept) })
                                    : t('factory_erp.qc.endline_line', { checked: formatNumber(item.checked), defects: formatNumber(item.defects), dhu: formatDecimal(dhuText(item.dhu_bp)) }) }}
                            </p>
                            <p v-if="item.by_type.length" class="text-[12px] text-muted">{{ item.by_type.map((row) => `${typeName(row.defect_type_id)} ${formatNumber(row.count)}`).join(' · ') }}</p>
                            <p v-if="item.voided_at" class="text-[12px] text-bad">{{ t('factory_erp.cutting.voided_because', { reason: item.void_reason }) }}</p>
                        </div>
                        <AppBadge v-if="item.voided_at" tone="bad">{{ t('factory_erp.cutting.void_badge') }}</AppBadge>
                        <AppButton v-else-if="canCheck" size="sm" variant="ghost" :icon="Ban" @click="voiding = item; reason = ''">{{ t('factory_erp.cutting.void') }}</AppButton>
                    </li>
                </ul>
            </section>
        </template>

        <AppDialog :open="voiding !== null" tone="bad" confirm :icon="Ban" :title="t('factory_erp.qc.void_title')" :description="t('factory_erp.qc.void_text')" @close="voiding = null">
            <AppField v-slot="{ id }" :label="t('factory_erp.steps.reason')">
                <input :id="id" v-model="reason" class="field-input" maxlength="300" :placeholder="t('factory_erp.qc.void_hint')" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="voiding = null">{{ t('factory_erp.cancel') }}</AppButton>
                <AppButton variant="danger" :loading="saving" :disabled="reason.trim().length < 3" @click="voidCheck">{{ t('factory_erp.cutting.void') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
