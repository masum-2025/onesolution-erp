<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Ban, Lock, Save, Ticket, UsersRound } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization } from '@/lib/session';
import { newOpId } from '@/lib/billing';
import { formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { piecesOf, ticketAmount } from '../lib';

/**
 * Operators' piece tickets for a day, made for a phone: find the operator
 * (HRM's people at work that day), choose the order and one of its style's
 * operations, type the pieces; the rate and the pay show as typed. Below,
 * the day's tickets (a wrong one voided with a reason) and each operator's
 * pay this month so far. Payroll adds it to the month's slip; once the
 * month's payroll is approved its tickets are locked.
 */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const canWrite = computed(() => setup.can('produce'));

const day = reactive({ loading: true, error: null, items: [], employees: [], orders: [], month: {}, hrm: true, locked: false, ratePer: 'piece', currency: null, date: '' });
async function load() {
    day.loading = true;
    day.error = null;
    try {
        const { data, meta } = await api.pieceTickets(day.date ? { date: day.date } : {});
        Object.assign(day, { items: data, employees: meta.employees, orders: meta.orders, month: meta.month, hrm: meta.hrm, locked: meta.locked, ratePer: meta.rate_per, currency: meta.currency, date: meta.date });
    } catch (error) {
        day.error = error;
    } finally {
        day.loading = false;
    }
}
load();
const today = computed(() => setup.data.value?.today ?? '');
const per = computed(() => (day.ratePer === 'dozen' ? 12 : 1));
const money = (minor) => formatMoney({ amount: minor, currency: day.currency });

const form = reactive({ search: '', employeeId: '', orderId: '', operationId: '', pieces: '' });
const people = computed(() => {
    const needle = form.search.trim().toLowerCase();
    return needle ? day.employees.filter((person) => `${person.code} ${person.name}`.toLowerCase().includes(needle)) : day.employees;
});
watch(() => day.orders, (orders) => { if (!orders.some((order) => order.id === form.orderId)) form.orderId = orders[0]?.id ?? ''; });
const order = computed(() => day.orders.find((item) => item.id === form.orderId) ?? null);
const operations = computed(() => order.value?.operations ?? []);
watch(operations, (list) => { if (!list.some((operation) => operation.id === form.operationId)) form.operationId = list.find((operation) => operation.piece_rate_minor !== null)?.id ?? ''; });
const operation = computed(() => operations.value.find((item) => item.id === form.operationId) ?? null);
const pieces = computed(() => piecesOf(form.pieces));
const pay = computed(() => (operation.value?.piece_rate_minor != null && pieces.value > 0 ? ticketAmount(pieces.value, operation.value.piece_rate_minor, per.value) : null));
const ready = computed(() => canWrite.value && !day.locked && form.employeeId && form.orderId && operation.value?.piece_rate_minor != null && pieces.value > 0);

const saving = ref(false);
let opId = newOpId();
async function save() {
    saving.value = true;
    try {
        await api.createPieceTicket({ employee_id: form.employeeId, order_id: form.orderId, operation_id: form.operationId, pieces: pieces.value, worked_on: day.date, op_id: opId });
        toast.success(t('factory_erp.tickets.saved', { pay: money(pay.value) }));
        form.pieces = '';
        opId = newOpId();
        await load();
    } catch (error) {
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const person = (id) => day.employees.find((item) => item.id === id);
const orderNumber = (id) => day.orders.find((item) => item.id === id)?.number ?? '—';
const monthRows = computed(() => Object.entries(day.month).map(([id, total]) => ({ id, ...total })).sort((a, b) => b.amount - a.amount));
const voiding = ref(null);
const reason = ref('');
async function voidTicket() {
    saving.value = true;
    try {
        await api.voidPieceTicket(voiding.value.id, { base_version: voiding.value.version, reason: reason.value.trim() });
        toast.success(t('factory_erp.tickets.voided'));
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
        <PageHeader :title="t('factory_erp.tickets.title')" :description="t('factory_erp.tickets.text')">
            <template #actions>
                <input v-model="day.date" type="date" class="field-input w-40" :max="today" :aria-label="t('factory_erp.issues.day')" @change="load()" />
            </template>
        </PageHeader>
        <SkeletonRows v-if="day.loading && !day.orders.length && !day.items.length" :rows="6" />
        <ErrorState v-else-if="day.error" :error="day.error" @retry="load()" />
        <section v-else-if="!day.hrm" class="card"><EmptyState :icon="UsersRound" :title="t('factory_erp.tickets.no_hrm_title')" :text="t('factory_erp.tickets.no_hrm_text')" /></section>
        <template v-else>
            <p v-if="day.locked" class="mb-4 flex items-center gap-2 rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted"><Lock class="size-4 shrink-0" aria-hidden="true" />{{ t('factory_erp.tickets.locked') }}</p>
            <section v-if="canWrite && !day.locked" class="card mb-5 p-4">
                <EmptyState v-if="!day.orders.length" compact :icon="Ticket" :title="t('factory_erp.output.no_orders_title')" :text="t('factory_erp.output.no_orders_text')" />
                <template v-else>
                    <AppField v-slot="{ id }" :label="t('factory_erp.tickets.operator')">
                        <input :id="id" v-model="form.search" class="field-input h-12 text-[15px]" :placeholder="t('factory_erp.tickets.search')" />
                    </AppField>
                    <ul class="mt-2 max-h-48 divide-y divide-line overflow-y-auto rounded-xl border border-line" role="listbox" :aria-label="t('factory_erp.tickets.operator')">
                        <li v-for="item in people" :key="item.id">
                            <button type="button" role="option" :aria-selected="form.employeeId === item.id" class="flex min-h-11 w-full items-center gap-3 px-3 text-start text-[14px]"
                                :class="form.employeeId === item.id ? 'bg-brand-soft font-semibold text-brand-text' : 'text-fg'" @click="form.employeeId = item.id">
                                <span class="font-mono text-[12px] text-muted" dir="ltr">{{ item.code }}</span>{{ item.name }}
                            </button>
                        </li>
                        <li v-if="!people.length" class="px-3 py-3 text-center text-[13px] text-muted">{{ t('factory_erp.tickets.nobody') }}</li>
                    </ul>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <AppField v-slot="{ id }" :label="t('factory_erp.output.order')">
                            <select :id="id" v-model="form.orderId" class="field-input h-12 text-[15px]">
                                <option v-for="item in day.orders" :key="item.id" :value="item.id">{{ item.number }} · {{ item.style_code }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('factory_erp.operations.name')">
                            <select :id="id" v-model="form.operationId" class="field-input h-12 text-[15px]">
                                <option v-for="item in operations" :key="item.id" :value="item.id" :disabled="item.piece_rate_minor === null">
                                    {{ item.name }} · {{ item.piece_rate_minor === null ? t('factory_erp.tickets.no_rate') : money(item.piece_rate_minor) }}
                                </option>
                            </select>
                        </AppField>
                    </div>
                    <AppField v-slot="{ id }" class="mt-3" :label="t('factory_erp.tickets.pieces')">
                        <input :id="id" v-model="form.pieces" inputmode="numeric" class="field-input tabular h-12 text-center text-[18px] font-semibold" placeholder="0" />
                    </AppField>
                    <p v-if="pay !== null" class="mt-3 rounded-xl bg-brand-soft px-3 py-2 text-[14px] text-brand-text">
                        {{ t(per === 12 ? 'factory_erp.tickets.pay_dozen' : 'factory_erp.tickets.pay', { pieces: formatNumber(pieces), rate: money(operation.piece_rate_minor), pay: money(pay) }) }}
                    </p>
                    <AppButton class="mt-4 h-12 w-full text-[15px]" variant="primary" :icon="Save" :loading="saving" :disabled="!ready" @click="save">{{ t('factory_erp.tickets.save') }}</AppButton>
                </template>
            </section>

            <section class="card mb-5">
                <header class="border-b border-line px-5 py-3"><h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.tickets.day') }}</h2></header>
                <EmptyState v-if="!day.items.length" compact :icon="Ticket" :title="t('factory_erp.tickets.empty_title')" :text="t('factory_erp.tickets.empty_text')" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in day.items" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3" :class="item.voided_at ? 'opacity-70' : ''">
                        <div class="min-w-0 flex-1">
                            <p class="text-[13.5px] text-fg" :class="item.voided_at ? 'line-through' : ''">
                                <span v-if="person(item.employee_id)" class="font-medium">{{ person(item.employee_id).name }} · </span>{{ item.operation_name }} · {{ formatNumber(item.pieces) }}
                            </p>
                            <p class="text-[12px] text-muted">{{ orderNumber(item.order_id) }} · {{ money(item.rate_minor) }}{{ item.rate_per === 12 ? t('factory_erp.tickets.per_dozen') : '' }}</p>
                            <p v-if="item.voided_at" class="text-[12px] text-bad">{{ t('factory_erp.cutting.voided_because', { reason: item.void_reason }) }}</p>
                        </div>
                        <span class="tabular text-[14px] font-semibold">{{ money(item.amount_minor) }}</span>
                        <AppBadge v-if="item.voided_at" tone="bad">{{ t('factory_erp.cutting.void_badge') }}</AppBadge>
                        <AppButton v-else-if="canWrite && !day.locked" size="sm" variant="ghost" :icon="Ban" @click="voiding = item; reason = ''">{{ t('factory_erp.cutting.void') }}</AppButton>
                    </li>
                </ul>
            </section>

            <section v-if="monthRows.length" class="card">
                <header class="border-b border-line px-5 py-3"><h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.tickets.month') }}</h2></header>
                <ul class="divide-y divide-line">
                    <li v-for="row in monthRows" :key="row.id" class="flex items-center gap-3 px-5 py-2.5 text-[13.5px]">
                        <span class="min-w-0 flex-1 truncate">{{ person(row.id)?.name ?? '—' }}</span>
                        <span class="tabular text-muted">{{ t('factory_erp.tickets.pieces_count', { count: formatNumber(row.pieces) }) }}</span>
                        <span class="tabular w-28 text-end font-semibold">{{ money(row.amount) }}</span>
                    </li>
                </ul>
            </section>
        </template>

        <AppDialog :open="voiding !== null" tone="bad" confirm :icon="Ban" :title="t('factory_erp.tickets.void_title')" :description="t('factory_erp.tickets.void_text')" @close="voiding = null">
            <AppField v-slot="{ id }" :label="t('factory_erp.steps.reason')">
                <input :id="id" v-model="reason" class="field-input" maxlength="300" :placeholder="t('factory_erp.tickets.void_hint')" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="voiding = null">{{ t('factory_erp.cancel') }}</AppButton>
                <AppButton variant="danger" :loading="saving" :disabled="reason.trim().length < 3" @click="voidTicket">{{ t('factory_erp.cutting.void') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
