<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Banknote, Truck } from 'lucide-vue-next';
import { AppButton, AppField, DateInput, localToday, MoneyInput, newOpId, PageFrame, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { onlineOrdersApi } from '../api';

/**
 * SC-6: what each courier owes (cash it collected at the door) and the
 * payouts it sends. Pick a courier, tick the orders its payout covers, write
 * what arrived, where, and its charge; a difference needs a reason. A payout
 * is written once and cannot be changed.
 */
const orders = onlineOrdersApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const list = useResource(() => orders.courierPayouts());
const couriers = computed(() => list.data.value?.data ?? []);
const courierId = ref(route.query.courier ?? '');
const detail = useResource(() => (courierId.value ? orders.courierPayout(courierId.value) : Promise.resolve(null)));
watch(couriers, (rows) => {
    if (!courierId.value && rows.length) courierId.value = rows[0].id;
});
watch(courierId, (id) => {
    router.replace({ query: id ? { courier: id } : {} });
    picked.value = new Set();
    detail.reload();
});

const account = computed(() => detail.data.value?.data ?? null);
const owed = computed(() => account.value?.owed ?? []);
const picked = ref(new Set());
const chosen = computed(() => owed.value.filter((row) => picked.value.has(row.id)));
const currency = computed(() => chosen.value[0]?.currency ?? owed.value[0]?.currency ?? null);
const collected = computed(() => chosen.value.reduce((sum, row) => sum + row.amount_minor, 0));
const money = (amount, code = currency.value) => formatMoney({ amount, currency: code });
const mixed = computed(() => new Set(chosen.value.map((row) => row.currency)).size > 1);
const allPicked = computed(() => owed.value.length > 0 && chosen.value.length === owed.value.length);

function toggle(id) {
    const next = new Set(picked.value);
    next.has(id) ? next.delete(id) : next.add(id);
    picked.value = next;
}
function toggleAll() {
    picked.value = allPicked.value ? new Set() : new Set(owed.value.map((row) => row.id));
}

// The payout form: what arrived (defaults to what was collected less the charge).
const form = reactive({ method: 'bank', received: null, fee: 0, paid_on: localToday(), reference: '', reason: '', op_id: newOpId() });
const difference = computed(() => collected.value - (form.received ?? 0) - (form.fee ?? 0));
watch([collected, () => form.fee], () => {
    form.received = Math.max(0, collected.value - (form.fee ?? 0));
});
const busy = ref(false);
const canSave = computed(() => chosen.value.length > 0 && !mixed.value && form.received !== null && form.paid_on && (difference.value === 0 || form.reason.trim().length > 0));

async function save() {
    busy.value = true;
    try {
        await orders.recordPayout(courierId.value, {
            op_id: form.op_id, payment_ids: chosen.value.map((row) => row.id), method: form.method, received_minor: form.received,
            fee_minor: form.fee ?? 0, paid_on: form.paid_on, reference: form.reference || null, reason: form.reason || null,
        });
        toast.success(t('online_orders.payouts.recorded'));
        Object.assign(form, { received: null, fee: 0, reference: '', reason: '', op_id: newOpId() });
        picked.value = new Set();
        detail.reload();
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}

const exported = computed(() => (account.value ? {
    module: 'online_orders',
    page: 'online_orders.courier_payouts',
    columns: [
        { key: 'paid_on', label: t('online_orders.payouts.paid_on'), type: 'date' },
        { key: 'count', label: t('online_orders.payouts.orders'), type: 'number' },
        { key: 'collected', label: t('online_orders.payouts.collected'), type: 'money', currency: currency.value },
        { key: 'received', label: t('online_orders.payouts.received'), type: 'money', currency: currency.value },
        { key: 'fee', label: t('online_orders.payouts.fee'), type: 'money', currency: currency.value },
        { key: 'difference', label: t('online_orders.payouts.difference'), type: 'money', currency: currency.value },
        { key: 'reason', label: t('online_orders.payouts.reason') },
    ],
    rows: account.value.payouts.map((row) => ({ paid_on: row.paid_on, count: row.payments_count, collected: row.collected_minor, received: row.received_minor, fee: row.fee_minor, difference: row.difference_minor, reason: row.reason ?? '' })),
} : null));
</script>

<template>
    <PageFrame
        :title="t('online_orders.payouts.title')"
        :description="t('online_orders.payouts.text')"
        width="full"
        :resource="list"
        :empty="{ when: !couriers.length, icon: Truck, title: t('online_orders.payouts.no_couriers'), text: t('online_orders.payouts.no_couriers_text') }"
        :export="exported"
    >
        <!-- One card per courier account: what it owes now -->
        <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <button
                v-for="row in couriers"
                :key="row.id"
                type="button"
                class="card p-4 text-start transition"
                :class="row.id === courierId ? 'ring-2 ring-brand' : 'hover:bg-subtle'"
                :aria-pressed="row.id === courierId"
                @click="courierId = row.id"
            >
                <p class="text-[13px] font-semibold">{{ row.name }}</p>
                <p v-for="due in row.owed" :key="due.currency" class="tabular mt-1 text-[18px] font-semibold">{{ money(due.amount_minor, due.currency) }}</p>
                <p v-if="!row.owed.length" class="mt-1 text-[13px] text-muted">{{ t('online_orders.payouts.nothing_owed') }}</p>
                <p class="mt-0.5 text-[12px] text-muted">
                    {{ t('online_orders.payouts.owed_count', { count: formatNumber(row.owed.reduce((sum, due) => sum + due.count, 0)) }) }}
                    <template v-if="row.last_payout"> · {{ t('online_orders.payouts.last', { day: formatDate(`${row.last_payout.paid_on}T00:00:00Z`, { day: 'numeric', month: 'short', timeZone: 'UTC' }) }) }}</template>
                </p>
            </button>
        </div>

        <p v-if="detail.loading.value && !account" class="text-[13px] text-muted">{{ t('online_orders.payouts.loading') }}</p>
        <p v-else-if="detail.error.value" class="card p-4 text-[13px] text-bad">{{ detail.error.value.message }}</p>
        <div v-else-if="account" class="grid grid-cols-1 gap-5 xl:grid-cols-[1fr_24rem]">
            <!-- What it owes: tick what the payout covers -->
            <section class="card">
                <header class="flex items-center gap-3 border-b border-line px-5 py-3">
                    <h2 class="flex-1 text-[14px] font-semibold">{{ t('online_orders.payouts.owed_title', { courier: account.name }) }}</h2>
                    <AppButton v-if="owed.length" size="sm" variant="ghost" @click="toggleAll">{{ allPicked ? t('online_orders.payouts.pick_none') : t('online_orders.payouts.pick_all') }}</AppButton>
                </header>
                <p v-if="!owed.length" class="px-5 py-6 text-[13px] text-muted">{{ t('online_orders.payouts.nothing_owed_text') }}</p>
                <ul v-else class="max-h-[32rem] divide-y divide-line overflow-y-auto">
                    <li v-for="row in owed" :key="row.id">
                        <label class="flex cursor-pointer items-center gap-3 px-5 py-2.5 text-[13px] hover:bg-subtle">
                            <input type="checkbox" class="size-4" :checked="picked.has(row.id)" @change="toggle(row.id)">
                            <span class="min-w-0 flex-1">
                                <RouterLink :to="`/online-orders/${row.order_id}`" class="font-medium hover:underline" @click.stop>{{ row.number }}</RouterLink>
                                <span v-if="row.courier_ref" class="ms-2 font-mono text-[12px] text-muted" dir="ltr">{{ row.courier_ref }}</span>
                                <span class="block text-[12px] text-muted">{{ formatDate(row.collected_at, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) }}</span>
                            </span>
                            <span class="tabular font-semibold">{{ money(row.amount_minor, row.currency) }}</span>
                        </label>
                    </li>
                </ul>
            </section>

            <!-- The payout -->
            <section class="card p-5">
                <h2 class="mb-4 flex items-center gap-2 text-[14px] font-semibold"><Banknote class="size-4" aria-hidden="true" />{{ t('online_orders.payouts.record') }}</h2>
                <dl class="mb-4 grid grid-cols-2 gap-2 text-[13px]">
                    <dt class="text-muted">{{ t('online_orders.payouts.orders') }}</dt><dd class="tabular text-end">{{ formatNumber(chosen.length) }}</dd>
                    <dt class="text-muted">{{ t('online_orders.payouts.collected') }}</dt><dd class="tabular text-end font-semibold">{{ money(collected) }}</dd>
                </dl>
                <p v-if="mixed" class="mb-3 text-[13px] text-bad">{{ t('online_orders.payouts.mixed') }}</p>
                <div class="grid gap-3">
                    <AppField v-slot="{ id }" :label="t('online_orders.payouts.fee')"><MoneyInput :id="id" v-model="form.fee" :currency="currency" /></AppField>
                    <AppField v-slot="{ id }" :label="t('online_orders.payouts.received')"><MoneyInput :id="id" v-model="form.received" :currency="currency" /></AppField>
                    <AppField v-slot="{ id }" :label="t('online_orders.payouts.method')">
                        <select :id="id" v-model="form.method" class="field-input">
                            <option v-for="method in ['bank', 'mobile', 'cash']" :key="method" :value="method">{{ t(`online_orders.method.${method}`) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('online_orders.payouts.paid_on')"><DateInput :id="id" v-model="form.paid_on" :max="localToday()" /></AppField>
                    <AppField v-slot="{ id }" :label="t('online_orders.payouts.reference')"><input :id="id" v-model="form.reference" class="field-input" maxlength="80"></AppField>
                    <p v-if="chosen.length && difference !== 0" class="rounded-md bg-subtle p-3 text-[13px]" :class="difference > 0 ? 'text-bad' : ''">
                        {{ t(difference > 0 ? 'online_orders.payouts.short' : 'online_orders.payouts.over', { amount: money(Math.abs(difference)) }) }}
                    </p>
                    <AppField v-if="chosen.length && difference !== 0" v-slot="{ id }" :label="t('online_orders.payouts.reason')">
                        <textarea :id="id" v-model="form.reason" class="field-input" rows="2" maxlength="300" />
                    </AppField>
                    <AppButton :disabled="!canSave || busy" :loading="busy" @click="save">{{ t('online_orders.payouts.save') }}</AppButton>
                    <p class="text-[12px] text-muted">{{ t('online_orders.payouts.final') }}</p>
                </div>
            </section>

            <!-- Earlier payouts -->
            <section class="card xl:col-span-2">
                <h2 class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('online_orders.payouts.history') }}</h2>
                <p v-if="!account.payouts.length" class="px-5 py-4 text-[13px] text-muted">{{ t('online_orders.payouts.no_history') }}</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-[13px]">
                        <thead class="text-[12px] text-muted">
                            <tr class="border-b border-line">
                                <th class="px-5 py-2 text-start font-medium">{{ t('online_orders.payouts.paid_on') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('online_orders.payouts.orders') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('online_orders.payouts.collected') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('online_orders.payouts.received') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('online_orders.payouts.fee') }}</th>
                                <th class="px-5 py-2 text-start font-medium">{{ t('online_orders.payouts.difference') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in account.payouts" :key="row.id">
                                <td class="px-5 py-2">
                                    {{ formatDate(`${row.paid_on}T00:00:00Z`, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }) }}
                                    <span class="block text-[12px] text-muted">{{ t(`online_orders.method.${row.method}`) }}<template v-if="row.reference"> · {{ row.reference }}</template><template v-if="row.recorded_by"> · {{ row.recorded_by }}</template></span>
                                </td>
                                <td class="tabular px-3 py-2 text-end">{{ formatNumber(row.payments_count) }}</td>
                                <td class="tabular px-3 py-2 text-end">{{ money(row.collected_minor, row.currency) }}</td>
                                <td class="tabular px-3 py-2 text-end font-medium">{{ money(row.received_minor, row.currency) }}</td>
                                <td class="tabular px-3 py-2 text-end">{{ money(row.fee_minor, row.currency) }}</td>
                                <td class="px-5 py-2">
                                    <span v-if="row.difference_minor === 0" class="text-muted">—</span>
                                    <template v-else>
                                        <span class="tabular font-medium" :class="row.difference_minor > 0 ? 'text-bad' : ''">{{ money(row.difference_minor, row.currency) }}</span>
                                        <span class="block text-[12px] text-muted">{{ row.reason }}</span>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </PageFrame>
</template>
