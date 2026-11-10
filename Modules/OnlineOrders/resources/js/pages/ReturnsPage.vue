<script setup>
import { computed, reactive, ref } from 'vue';
import { PackageOpen } from 'lucide-vue-next';
import { AppButton, AppSegmented, confirmAction, PageFrame, StatusBadge, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { onlineOrdersApi } from '../api';

/**
 * SC-7: parcels the courier could not deliver and is bringing back, oldest
 * first. When one arrives, staff take it back: its goods go back into stock
 * and the order is cancelled (money paid ahead goes back, less the delivery
 * charge when the company keeps it).
 */
const orders = onlineOrdersApi(currentOrganization().id);
const list = useResource(() => orders.returns());
const rows = computed(() => list.data.value?.data ?? []);
const view = ref('all');
const late = computed(() => rows.value.filter((row) => row.overdue));
const shown = computed(() => (view.value === 'late' ? late.value : rows.value));
const views = computed(() => [
    { value: 'all', label: `${t('online_orders.returns.all')} (${formatNumber(rows.value.length)})` },
    { value: 'late', label: `${t('online_orders.returns.late')} (${formatNumber(late.value.length)})` },
]);

const held = (row) => row.paid_minor - row.refunded_minor;
const refundTo = reactive({});
const busy = ref(null);
const money = (amount, currency) => formatMoney({ amount, currency });
const quantity = (milli) => formatNumber(milli / 1000, { maximumFractionDigits: 3 });

async function receive(row) {
    const answer = await confirmAction({
        title: t('online_orders.returns.confirm_title', { number: row.number }),
        message: t('online_orders.returns.confirm_text'),
        details: row.items.map((item) => `${quantity(item.quantity_milli)} × ${item.name}`),
        confirmLabel: t('online_orders.returns.receive'),
        reason: 'optional',
    });
    if (!answer) return;
    busy.value = row.id;
    try {
        await orders.receiveBack(row.id, {
            base_version: row.version,
            note: answer.reason,
            refund_method: held(row) > 0 ? (refundTo[row.id] ?? 'mobile') : null,
        });
        toast.success(t('online_orders.returns.received', { number: row.number }));
        list.reload();
    } catch (error) {
        toast.error(error.message);
        list.reload();
    } finally {
        busy.value = null;
    }
}

const exported = computed(() => ({
    module: 'online_orders',
    page: 'online_orders.returns',
    columns: [
        { key: 'number', label: t('online_orders.returns.order') },
        { key: 'customer', label: t('online_orders.returns.customer') },
        { key: 'courier', label: t('online_orders.returns.courier') },
        { key: 'since', label: t('online_orders.returns.since'), type: 'date' },
        { key: 'days', label: t('online_orders.returns.days'), type: 'number' },
        { key: 'items', label: t('online_orders.returns.items') },
    ],
    rows: shown.value.map((row) => ({
        number: row.number, customer: `${row.customer_name ?? ''} ${row.customer_phone ?? ''}`.trim(), courier: row.courier ?? '',
        since: row.courier_returning_at.slice(0, 10), days: row.days_on_way,
        items: row.items.map((item) => `${quantity(item.quantity_milli)} × ${item.name}`).join(', '),
    })),
}));
</script>

<template>
    <PageFrame
        :title="t('online_orders.returns.title')"
        :description="t('online_orders.returns.text')"
        :resource="list"
        :empty="{ when: !rows.length, icon: PackageOpen, title: t('online_orders.returns.none'), text: t('online_orders.returns.none_text') }"
        :export="exported"
    >
        <template #top>
            <AppSegmented v-model="view" :options="views" :label="t('online_orders.returns.title')" />
        </template>

        <p v-if="!shown.length" class="card p-5 text-[13px] text-muted">{{ t('online_orders.returns.none_late') }}</p>
        <ul v-else class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <li v-for="row in shown" :key="row.id" class="card p-4" :class="row.overdue ? 'border-s-4 border-s-bad' : ''">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <RouterLink :to="`/online-orders/${row.id}`" class="text-[14px] font-semibold hover:underline">{{ row.number }}</RouterLink>
                        <p class="text-[13px]">{{ row.customer_name }} <span class="text-muted" dir="ltr">{{ row.customer_phone }}</span></p>
                        <p class="mt-0.5 text-[12px] text-muted">
                            {{ row.courier }}<template v-if="row.courier_ref"> · <span class="font-mono" dir="ltr">{{ row.courier_ref }}</span></template>
                        </p>
                    </div>
                    <StatusBadge :status="row.overdue ? 'late' : 'on_way'" :tones="{ late: 'bad', on_way: 'warn' }">
                        {{ row.overdue ? t('online_orders.returns.late_badge', { days: formatNumber(row.days_on_way) }) : t('online_orders.returns.on_way', { days: formatNumber(row.days_on_way) }) }}
                    </StatusBadge>
                </div>
                <ul class="mt-3 space-y-0.5 text-[13px]">
                    <li v-for="(item, index) in row.items" :key="index"><span class="tabular">{{ quantity(item.quantity_milli) }}</span> × {{ item.name }}</li>
                </ul>
                <p class="mt-2 text-[12px] text-muted">
                    {{ t('online_orders.returns.cancelled_on', { day: formatDate(row.courier_returning_at, { day: 'numeric', month: 'short' }) }) }}
                </p>
                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <label v-if="held(row) > 0" class="grid gap-1 text-[12px]">
                        <span class="text-muted">{{ t('online_orders.returns.paid_ahead', { amount: money(held(row), row.currency) }) }}</span>
                        <select v-model="refundTo[row.id]" class="field-input">
                            <option v-for="method in ['mobile', 'bank', 'cash']" :key="method" :value="method">{{ t(`online_orders.method.${method}`) }}</option>
                        </select>
                    </label>
                    <AppButton class="ms-auto" :loading="busy === row.id" :disabled="busy !== null" @click="receive(row)">{{ t('online_orders.returns.receive') }}</AppButton>
                </div>
            </li>
        </ul>
    </PageFrame>
</template>
