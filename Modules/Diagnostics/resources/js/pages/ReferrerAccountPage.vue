<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, HandCoins, Undo2, UserRound } from 'lucide-vue-next';
import { AppButton, AppField, DateInput, MoneyInput, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppDialog from '@/components/AppDialog.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { labApi } from '../api';

/**
 * One referring doctor's account for a period: owed at the start, each
 * order's commission (and lines taken back), payouts, owed at the end. Pay
 * up to what is owed; take an order's commission back with a reason. Nothing
 * is changed or removed: a mistake is put right by a new line.
 */
const lab = labApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const from = ref(typeof route.query.from === 'string' ? route.query.from : '');
const to = ref(typeof route.query.to === 'string' ? route.query.to : '');
const view = useResource(() => lab.account(route.params.id, { from: from.value || undefined, to: to.value || undefined }));
const account = computed(() => view.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: account.value?.currency });
const busy = ref(false);
// Today on this device (YYYY-MM-DD); the server checks it again in the company's time zone.
const today = new Date().toLocaleDateString('en-CA');

const exported = computed(() => ({
    module: 'diagnostics', page: 'diagnostics.referrer_account', rows: account.value?.commissions ?? [],
    columns: [
        { key: 'earned_on', label: t('diagnostics.account.date'), type: 'date' },
        { key: 'order_number', label: t('diagnostics.account.order') },
        { key: 'base_minor', label: t('diagnostics.account.base'), type: 'money', currency: account.value?.currency },
        { key: 'percent', label: t('diagnostics.account.percent') },
        { key: 'amount_minor', label: t('diagnostics.account.amount'), type: 'money', currency: account.value?.currency },
    ],
}));

// Paying the doctor
const paying = ref(false);
const payment = reactive({ amount: null, method: 'cash', paid_on: today, note: '' });
const methods = ['cash', 'bank', 'mobile'];
function startPay() {
    Object.assign(payment, { amount: account.value?.owed_minor || null, method: 'cash', paid_on: today, note: '', op: crypto.randomUUID() });
    paying.value = true;
}
async function pay() {
    busy.value = true;
    try {
        await lab.pay(route.params.id, { amount_minor: payment.amount, method: payment.method, paid_on: payment.paid_on, note: payment.note.trim() || null, op_id: payment.op });
        toast.success(t('diagnostics.account.paid_ok'));
        paying.value = false;
        view.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}

// Taking one order's commission back
const reversing = ref(null);
const reason = ref('');
async function reverse() {
    busy.value = true;
    try {
        await lab.reverse(reversing.value.id, { reason: reason.value.trim() });
        toast.success(t('diagnostics.account.reversed'));
        reversing.value = null;
        reason.value = '';
        view.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageFrame :title="account ? account.referrer.name : ''" :description="account ? t('diagnostics.account.text', { from: formatDate(account.from), to: formatDate(account.to) }) : ''"
               width="lg" :resource="view" :export="exported" :not-found="{ icon: UserRound, title: t('diagnostics.account.not_found') }" guide="diagnostics.guide.commission">
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" @click="router.push({ name: 'diagnostics-commissions' })">{{ t('diagnostics.account.back') }}</AppButton>
        </template>
        <template v-if="account" #actions>
            <AppButton variant="primary" :icon="HandCoins" :disabled="account.owed_minor <= 0" @click="startPay">{{ t('diagnostics.account.pay') }}</AppButton>
        </template>

        <template v-if="account">
            <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="view.reload()">
                <AppField v-slot="{ id }" :label="t('diagnostics.commissions.from')"><DateInput :id="id" v-model="from" /></AppField>
                <AppField v-slot="{ id }" :label="t('diagnostics.commissions.to')"><DateInput :id="id" v-model="to" :min="from" /></AppField>
                <AppButton type="submit">{{ t('diagnostics.commissions.show') }}</AppButton>
            </form>
            <dl class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div v-for="item in [['opening', account.opening_minor], ['earned', account.earned_minor], ['paid', account.paid_minor], ['closing', account.closing_minor]]" :key="item[0]" class="card px-4 py-3">
                    <dt class="text-[12px] text-muted">{{ t(`diagnostics.account.${item[0]}`) }}</dt>
                    <dd class="tabular text-[16px] font-semibold">{{ money(item[1]) }}</dd>
                </div>
            </dl>
            <p class="mb-4 text-[13px] text-muted">{{ t('diagnostics.account.owed_now', { amount: money(account.owed_minor) }) }}</p>

            <section class="card mb-4 overflow-x-auto">
                <h2 class="border-b border-line px-4 py-2.5 text-[13.5px] font-semibold">{{ t('diagnostics.account.commissions') }}</h2>
                <p v-if="!account.commissions.length" class="px-4 py-3 text-[13px] text-muted">{{ t('diagnostics.account.no_commissions') }}</p>
                <table v-else class="w-full text-[13px]">
                    <thead>
                        <tr class="border-b border-line text-[12px] text-muted">
                            <th class="px-4 py-2 text-start font-medium">{{ t('diagnostics.account.date') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ t('diagnostics.account.order') }}</th>
                            <th class="px-4 py-2 text-end font-medium">{{ t('diagnostics.account.base') }}</th>
                            <th class="px-4 py-2 text-end font-medium">{{ t('diagnostics.account.amount') }}</th>
                            <th class="px-4 py-2"><span class="sr-only">{{ t('diagnostics.account.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="line in account.commissions" :key="line.id">
                            <td class="px-4 py-2">{{ formatDate(line.earned_on) }}</td>
                            <td class="px-4 py-2">
                                <span class="font-mono" dir="ltr">{{ line.order_number }}</span>
                                <AppBadge v-if="line.kind === 'reversal'" tone="warn" class="ms-2">{{ t('diagnostics.account.taken_back') }}</AppBadge>
                                <p v-if="line.reason" class="text-[12px] text-muted">{{ line.reason }}</p>
                            </td>
                            <td class="px-4 py-2 text-end tabular">{{ money(line.base_minor) }} · {{ line.percent }}%</td>
                            <td class="px-4 py-2 text-end tabular font-medium" :class="line.amount_minor < 0 && 'text-warn'">{{ money(line.amount_minor) }}</td>
                            <td class="px-4 py-2 text-end">
                                <AppButton v-if="line.kind === 'due' && !line.reversed" size="sm" variant="ghost" :icon="Undo2" @click="reversing = line">{{ t('diagnostics.account.reverse') }}</AppButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="card overflow-x-auto">
                <h2 class="border-b border-line px-4 py-2.5 text-[13.5px] font-semibold">{{ t('diagnostics.account.payouts') }}</h2>
                <p v-if="!account.payouts.length" class="px-4 py-3 text-[13px] text-muted">{{ t('diagnostics.account.no_payouts') }}</p>
                <ul v-else class="divide-y divide-line text-[13px]">
                    <li v-for="payout in account.payouts" :key="payout.id" class="flex flex-wrap items-center gap-3 px-4 py-2">
                        <span>{{ formatDate(payout.paid_on) }}</span>
                        <span class="text-muted">{{ t(`diagnostics.account.methods.${payout.method}`) }}</span>
                        <span v-if="payout.note" class="text-muted">· {{ payout.note }}</span>
                        <span class="ms-auto tabular font-medium">{{ money(payout.amount_minor) }}</span>
                    </li>
                </ul>
            </section>
        </template>

        <AppDialog :open="paying" :title="t('diagnostics.account.pay')" :description="t('diagnostics.account.pay_text', { amount: money(account?.owed_minor ?? 0) })" :icon="HandCoins" @close="paying = false">
            <div class="grid gap-3">
                <AppField v-slot="{ id }" :label="t('diagnostics.account.amount')"><MoneyInput :id="id" v-model="payment.amount" :currency="account?.currency" /></AppField>
                <AppField v-slot="{ id }" :label="t('diagnostics.account.method')">
                    <select :id="id" v-model="payment.method" class="field-input"><option v-for="method in methods" :key="method" :value="method">{{ t(`diagnostics.account.methods.${method}`) }}</option></select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('diagnostics.account.paid_on')"><DateInput :id="id" v-model="payment.paid_on" not-future /></AppField>
                <AppField v-slot="{ id }" :label="t('diagnostics.account.note')"><input :id="id" v-model="payment.note" class="field-input" maxlength="300" /></AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="paying = false">{{ t('diagnostics.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!payment.amount || payment.amount > (account?.owed_minor ?? 0) || !payment.paid_on" @click="pay">{{ t('diagnostics.account.pay') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="!!reversing" :title="t('diagnostics.account.reverse')" :description="t('diagnostics.account.reverse_text', { number: reversing?.order_number ?? '', amount: money(reversing?.amount_minor ?? 0) })" :icon="Undo2" @close="reversing = null">
            <AppField v-slot="{ id }" :label="t('diagnostics.order.reason')"><input :id="id" v-model="reason" class="field-input" maxlength="300" /></AppField>
            <template #footer>
                <AppButton variant="ghost" @click="reversing = null">{{ t('diagnostics.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="reason.trim().length < 3" @click="reverse">{{ t('diagnostics.common.confirm') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
