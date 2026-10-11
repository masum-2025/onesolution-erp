<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, BadgePercent, FileText, HandCoins, ReceiptText, SearchX, Undo2, CircleX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import { amountText, billState, parseAmount, percentText, requestTone } from '../lib';
import { billTitle, day } from '../labels';
import DiscountDialog from '../components/DiscountDialog.vue';
import ReasonDialog from '../components/ReasonDialog.vue';

/**
 * Everything about one student's fees: what they owe, what is overdue,
 * their advance; their bills (cancel one with a reason), the money taken
 * (each receipt opens to print), and their discounts (give one, end one).
 * Paying an advance back is asked here and decided by someone else.
 */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();
const route = useRoute();

const statement = useResource(() => api.student(route.params.id));
const data = computed(() => statement.data.value?.data ?? null);
const receipts = useResource(() => api.receipts({ student_id: route.params.id }));
const concessions = useResource(() => api.concessions({ student_id: route.params.id }));
watch(() => route.params.id, () => reloadAll());
function reloadAll() {
    statement.reload();
    receipts.reload();
    concessions.reload();
}

const tab = ref('bills');
const tabs = computed(() => [
    { key: 'bills', label: t('education_fees.student.tabs.bills'), count: data.value?.bills.length ?? 0 },
    { key: 'receipts', label: t('education_fees.student.tabs.receipts'), count: receipts.data.value?.data.length ?? 0 },
    { key: 'discounts', label: t('education_fees.student.tabs.discounts'), count: concessions.data.value?.data.length ?? 0 },
]);
const people = computed(() => receipts.data.value?.people ?? {});

function failed(error) {
    if (error.code === 'version_conflict') {
        toast.error(t('education_fees.conflict'));
        reloadAll();
        return;
    }
    toast.error(error.message);
}

// Cancelling a bill.
const cancelling = ref(null);
const busy = ref(false);
async function cancelBill(reason) {
    busy.value = true;
    try {
        await api.cancelBill(cancelling.value.id, { reason, base_version: cancelling.value.version });
        toast.success(t('education_fees.student.cancelled'));
        cancelling.value = null;
        reloadAll();
    } catch (error) {
        failed(error);
    } finally {
        busy.value = false;
    }
}

// Discounts.
const giving = ref(false);
const ending = ref(null);
const endOn = ref('');
async function endConcession() {
    busy.value = true;
    try {
        await api.endConcession(ending.value.id, { ends_on: endOn.value, base_version: ending.value.version });
        toast.success(t('education_fees.student.ended'));
        ending.value = null;
        concessions.reload();
    } catch (error) {
        failed(error);
    } finally {
        busy.value = false;
    }
}
function describe(concession) {
    const off = concession.mode === 'percent'
        ? t('education_fees.discount.off_percent', { percent: percentText(concession.percent_bp) })
        : t('education_fees.discount.off_fixed', { amount: setup.money(concession.amount_minor) });
    const on = concession.head_id ? t('education_fees.discount.on_head', { head: setup.headName(concession.head_id) }) : t('education_fees.discount.on_all');
    return `${off} ${on}`;
}

// Paying the advance back.
const refunding = ref(false);
const refund = reactive({ amount: '', method: 'cash', reference: '', reason: '' });
const refundErrors = ref({});
const refundAmount = computed(() => parseAmount(refund.amount, setup.currency.value));
function openRefund() {
    Object.assign(refund, { amount: amountText(data.value?.advance_minor ?? 0, setup.currency.value), method: 'cash', reference: '', reason: '' });
    refundErrors.value = {};
    refunding.value = true;
}
async function askRefund() {
    busy.value = true;
    refundErrors.value = {};
    try {
        const { data: saved } = await api.requestRefund({ student_id: route.params.id, amount_minor: refundAmount.value, method: refund.method, reference: refund.reference || null, reason: refund.reason.trim() });
        toast.success(t(saved.status === 'approved' ? 'education_fees.refund.paid' : 'education_fees.refund.asked'));
        refunding.value = false;
        statement.reload();
    } catch (error) {
        refundErrors.value = error.errors ?? {};
        if (!Object.keys(refundErrors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'fees-counter' }" :icon="ArrowLeft" class="mb-3">{{ t('education_fees.student.back') }}</AppButton>

        <SkeletonRows v-if="statement.loading.value && !data" :rows="8" />
        <section v-else-if="statement.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('education_fees.student.not_found')" /></section>
        <ErrorState v-else-if="statement.error.value" :error="statement.error.value" @retry="statement.reload()" />

        <template v-else-if="data">
            <PageHeader :title="data.student.name" :description="data.student.code">
                <template #actions>
                    <AppButton v-if="setup.can('concede')" :icon="BadgePercent" @click="giving = true">{{ t('education_fees.student.give_discount') }}</AppButton>
                    <AppButton v-if="setup.can('collect') && data.advance_minor > 0" :icon="Undo2" @click="openRefund">{{ t('education_fees.student.refund') }}</AppButton>
                    <AppButton v-if="setup.can('collect')" variant="primary" :icon="HandCoins" :to="{ name: 'fees-counter', query: { student: data.student.id } }">{{ t('education_fees.student.take_fee') }}</AppButton>
                </template>
            </PageHeader>

            <dl class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="card p-4">
                    <dt class="text-[12px] text-muted">{{ t('education_fees.student.owed') }}</dt>
                    <dd class="tabular text-[22px] font-semibold text-fg">{{ setup.money(data.owed_minor) }}</dd>
                </div>
                <div class="card p-4">
                    <dt class="text-[12px] text-muted">{{ t('education_fees.student.overdue') }}</dt>
                    <dd class="tabular text-[22px] font-semibold" :class="data.overdue_minor ? 'text-bad' : 'text-fg'">{{ setup.money(data.overdue_minor) }}</dd>
                </div>
                <div class="card p-4">
                    <dt class="text-[12px] text-muted">{{ t('education_fees.student.advance') }}</dt>
                    <dd class="tabular text-[22px] font-semibold text-fg">{{ setup.money(data.advance_minor) }}</dd>
                </div>
            </dl>

            <div class="mb-4 overflow-x-auto"><AppTabs v-model="tab" :tabs="tabs" :label="data.student.name" /></div>

            <!-- Bills. -->
            <section v-if="tab === 'bills'" class="card">
                <EmptyState v-if="!data.bills.length" :icon="FileText" :title="t('education_fees.student.no_bills')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="bill in data.bills" :key="bill.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3">
                        <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                            <span class="block text-[14px] font-medium text-fg">{{ billTitle(bill) }}</span>
                            <span class="block text-[12.5px] text-muted">
                                <span class="whitespace-nowrap font-mono" dir="ltr">{{ bill.number }}</span> · {{ t('education_fees.counter.due', { date: day(bill.due_date) }) }}
                                <template v-if="bill.paid_minor && bill.status !== 'cancelled'"> · {{ t('education_fees.student.paid_of', { paid: setup.money(bill.paid_minor), total: setup.money(bill.total_minor) }) }}</template>
                                <template v-if="bill.fine_minor"> · {{ t('education_fees.student.fine', { amount: setup.money(bill.fine_minor) }) }}</template>
                            </span>
                        </span>
                        <AppBadge :tone="billState(bill).tone" dot>{{ t(`education_fees.bill_states.${billState(bill).key}`) }}</AppBadge>
                        <span class="tabular ms-auto w-28 text-end text-[14px] font-semibold" :class="bill.status === 'cancelled' ? 'text-faint line-through' : 'text-fg'">{{ setup.money(bill.total_minor) }}</span>
                        <AppButton v-if="setup.can('bill') && bill.status !== 'cancelled'" size="icon-sm" variant="ghost" :icon="CircleX" :aria-label="t('education_fees.student.cancel_bill')" @click="cancelling = bill" />
                    </li>
                </ul>
            </section>

            <!-- Money taken. -->
            <section v-else-if="tab === 'receipts'" class="card">
                <SkeletonRows v-if="receipts.loading.value && !receipts.data.value" :rows="4" />
                <EmptyState v-else-if="!receipts.data.value?.data.length" :icon="ReceiptText" :title="t('education_fees.student.no_receipts')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="receipt in receipts.data.value.data" :key="receipt.id">
                        <RouterLink :to="{ name: 'fees-receipt', params: { id: receipt.id } }" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 transition hover:bg-subtle/60">
                            <span class="min-w-0 flex-1">
                                <span class="block font-mono text-[13.5px] font-medium text-fg" dir="ltr">{{ receipt.number }}</span>
                                <span class="block text-[12.5px] text-muted">{{ day(receipt.received_on) }} · {{ t(`education_fees.methods.${receipt.method}`) }} · {{ people[receipt.collected_by] ?? t('education_fees.receipt.online') }}</span>
                            </span>
                            <AppBadge v-if="receipt.status === 'voided'" tone="bad">{{ t('education_fees.receipt.voided') }}</AppBadge>
                            <span class="tabular text-[14px] font-semibold" :class="receipt.status === 'voided' ? 'text-faint line-through' : 'text-fg'">{{ setup.money(receipt.amount_minor) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>

            <!-- Discounts. -->
            <section v-else class="card">
                <SkeletonRows v-if="concessions.loading.value && !concessions.data.value" :rows="3" />
                <EmptyState v-else-if="!concessions.data.value?.data.length" :icon="BadgePercent" :title="t('education_fees.student.no_discounts')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="concession in concessions.data.value.data" :key="concession.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium text-fg">{{ describe(concession) }}</span>
                            <span class="block text-[12.5px] text-muted">
                                {{ concession.reason }} ·
                                {{ concession.ends_on ? t('education_fees.discount.from_to', { from: day(concession.starts_on), to: day(concession.ends_on) }) : t('education_fees.discount.from', { from: day(concession.starts_on) }) }}
                            </span>
                        </span>
                        <AppBadge :tone="requestTone(concession.status)" dot>{{ t(`education_fees.request_statuses.${concession.status}`) }}</AppBadge>
                        <AppButton v-if="setup.can('concede') && ['active', 'pending'].includes(concession.status)" size="sm" variant="ghost" @click="ending = concession; endOn = setup.today.value">{{ t('education_fees.student.end_discount') }}</AppButton>
                    </li>
                </ul>
            </section>
        </template>

        <ReasonDialog :open="cancelling !== null" :title="cancelling ? t('education_fees.student.cancel_title', { number: cancelling.number }) : ''" :text="t('education_fees.student.cancel_text')"
            :confirm-label="t('education_fees.student.cancel_bill')" :busy="busy" @close="cancelling = null" @submit="cancelBill" />

        <DiscountDialog :open="giving" :student="data?.student ?? null" @close="giving = false" @saved="giving = false; concessions.reload()" />

        <AppDialog :open="ending !== null" :title="t('education_fees.student.end_title')" :description="t('education_fees.student.end_text')" @close="ending = null">
            <AppField v-slot="{ id }" :label="t('education_fees.student.end_on')">
                <input :id="id" v-model="endOn" type="date" class="field-input sm:w-56" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="ending = null">{{ t('education_fees.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!endOn" @click="endConcession">{{ t('education_fees.student.end_discount') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="refunding" :title="t('education_fees.refund.title')" :description="t('education_fees.refund.text', { amount: setup.money(data?.advance_minor) })" :icon="Undo2" @close="refunding = false">
            <div class="grid gap-4">
                <AppField v-slot="{ id }" :label="t('education_fees.refund.amount')" :error="(refund.amount && !refundAmount ? t('education_fees.counter.amount_invalid') : null) ?? refundErrors.amount_minor?.[0] ?? null">
                    <input :id="id" v-model="refund.amount" inputmode="decimal" class="field-input tabular sm:w-48" />
                </AppField>
                <AppField :label="t('education_fees.counter.method')">
                    <AppSegmented v-model="refund.method" :options="['cash', 'bank', 'mobile'].map((value) => ({ value, label: t(`education_fees.methods.${value}`) }))" />
                </AppField>
                <AppField v-if="refund.method !== 'cash'" v-slot="{ id }" :label="t('education_fees.counter.reference')">
                    <input :id="id" v-model="refund.reference" class="field-input font-mono" dir="ltr" maxlength="100" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education_fees.refund.reason')" :error="refundErrors.reason?.[0] ?? null">
                    <input :id="id" v-model="refund.reason" class="field-input" maxlength="300" />
                </AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="refunding = false">{{ t('education_fees.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!refundAmount || refund.reason.trim().length < 3" @click="askRefund">{{ t('education_fees.refund.ask') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
