<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Ban, BadgeMinus, Printer, SearchX } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { brand } from '@/lib/brand';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import { billState, percentText } from '../lib';
import { billTitle, day } from '../labels';
import ReasonDialog from '../components/ReasonDialog.vue';

/**
 * One bill, printable: each head with its fee, discount (and where it came
 * from), tax and what is owed; late fines; what paid it. Waive its late
 * fine (approvers) or cancel it (billing), each with a reason.
 */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();
const route = useRoute();

const record = useResource(() => api.bill(route.params.id));
const bill = computed(() => record.data.value?.data ?? null);
const head = (id) => bill.value?.heads.find((item) => item.id === id);
const state = computed(() => (bill.value ? billState(bill.value) : null));
function basis(line) {
    const parts = [];
    if (line.basis?.percent_bp) parts.push(t('education_fees.bill.basis_percent', { percent: percentText(line.basis.percent_bp) }));
    if (line.basis?.sibling_bp) parts.push(t('education_fees.bill.basis_sibling', { percent: percentText(line.basis.sibling_bp) }));
    if (line.basis?.fixed_minor) parts.push(t('education_fees.bill.basis_fixed', { amount: setup.money(line.basis.fixed_minor) }));
    return parts.join(' · ');
}
const printPage = () => window.print();

const acting = ref(null);
const busy = ref(false);
async function submit(reason) {
    busy.value = true;
    try {
        if (acting.value === 'waive') await api.waiveFine(bill.value.id, { reason, base_version: bill.value.version });
        else await api.cancelBill(bill.value.id, { reason, base_version: bill.value.version });
        toast.success(t(acting.value === 'waive' ? 'education_fees.bill.waived' : 'education_fees.student.cancelled'));
        acting.value = null;
        record.reload();
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('education_fees.conflict') : error.message);
        record.reload();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2 print:hidden">
            <AppButton variant="ghost" size="sm" :to="{ name: 'fees-bills' }" :icon="ArrowLeft">{{ t('education_fees.bill.back') }}</AppButton>
            <div v-if="bill" class="flex flex-wrap gap-2">
                <AppButton v-if="setup.can('approve') && bill.fine_minor > 0 && !bill.fines_stopped" size="sm" :icon="BadgeMinus" @click="acting = 'waive'">{{ t('education_fees.bill.waive') }}</AppButton>
                <AppButton v-if="setup.can('bill') && bill.status !== 'cancelled'" size="sm" variant="danger-soft" :icon="Ban" @click="acting = 'cancel'">{{ t('education_fees.student.cancel_bill') }}</AppButton>
                <AppButton size="sm" variant="primary" :icon="Printer" @click="printPage">{{ t('education_fees.print') }}</AppButton>
            </div>
        </div>

        <SkeletonRows v-if="record.loading.value && !bill" :rows="8" />
        <section v-else-if="record.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('education_fees.bill.not_found')" /></section>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <article v-else-if="bill" class="card mx-auto max-w-3xl p-5 sm:p-8 print:border-0 print:p-0 print:shadow-none">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="h-10 w-auto" />
                    <div>
                        <p class="text-[12px] font-semibold uppercase tracking-wide text-brand-text">{{ t('education_fees.bill.title') }}</p>
                        <h1 class="text-[18px] font-semibold text-fg">{{ billTitle(bill) }}</h1>
                        <p class="font-mono text-[12.5px] text-muted" dir="ltr">{{ bill.number }}</p>
                    </div>
                </div>
                <AppBadge :tone="state.tone" dot>{{ t(`education_fees.bill_states.${state.key}`) }}</AppBadge>
            </header>

            <dl class="mt-5 grid grid-cols-1 gap-3 border-y border-line py-4 text-[13px] sm:grid-cols-3">
                <div>
                    <dt class="text-muted">{{ t('education_fees.bill.student') }}</dt>
                    <dd><RouterLink :to="{ name: 'fees-student', params: { id: bill.student_id } }" class="font-medium text-fg hover:underline">{{ bill.student?.name }}</RouterLink> <span class="font-mono text-muted" dir="ltr">{{ bill.student?.code }}</span></dd>
                </div>
                <div><dt class="text-muted">{{ t('education_fees.bill.issued') }}</dt><dd>{{ day(bill.issue_date) }}</dd></div>
                <div><dt class="text-muted">{{ t('education_fees.bill.due') }}</dt><dd :class="bill.overdue ? 'font-medium text-bad' : ''">{{ day(bill.due_date) }}</dd></div>
            </dl>
            <p v-if="bill.status === 'cancelled' && bill.cancel_reason" class="mt-3 rounded-xl bg-subtle px-4 py-2 text-[13px] text-muted">{{ t('education_fees.bill.cancelled_reason', { reason: bill.cancel_reason }) }}</p>

            <!-- Phones: each head stacked, then the totals. -->
            <div class="mt-4 sm:hidden print:hidden">
                <ul class="divide-y divide-line text-[13px]">
                    <li v-for="line in bill.lines" :key="line.id" class="py-2">
                        <div class="flex justify-between gap-3">
                            <span class="font-medium text-fg">{{ textIn(head(line.head_id)?.name) || head(line.head_id)?.code }}</span>
                            <span class="tabular font-medium">{{ setup.money(line.due_minor) }}</span>
                        </div>
                        <p class="text-[12px] text-muted">
                            {{ t('education_fees.bill.amount') }} {{ setup.money(line.amount_minor) }}
                            <template v-if="line.discount_minor"> · {{ t('education_fees.bill.discount') }} −{{ setup.money(line.discount_minor) }}</template>
                            <template v-if="line.tax_minor"> · {{ t('education_fees.bill.tax') }} {{ setup.money(line.tax_minor) }}</template>
                            <template v-if="basis(line)"> · {{ basis(line) }}</template>
                        </p>
                    </li>
                    <li v-for="fine in bill.fines" :key="fine.id" class="flex justify-between gap-3 py-2 text-muted">
                        <span>{{ fine.kind === 'fine' ? t('education_fees.bill.fines') : t('education_fees.bill.fine_waived') }} · {{ day(fine.applied_on) }}</span>
                        <span class="tabular">{{ setup.money(fine.amount_minor) }}</span>
                    </li>
                </ul>
                <dl class="mt-2 space-y-1 border-t border-line-strong pt-3 text-[13.5px]">
                    <div class="flex justify-between gap-3 font-semibold"><dt>{{ t('education_fees.bill.total') }}</dt><dd class="tabular">{{ setup.money(bill.total_minor) }}</dd></div>
                    <div v-for="payment in bill.payments" :key="`m-${payment.kind}-${payment.receipt_id}-${payment.day}`" class="flex justify-between gap-3 text-[12.5px] text-muted">
                        <dt>{{ payment.receipt_id ? `${t('education_fees.bill.paid')} · ${payment.number}` : t('education_fees.bill.from_advance') }} · {{ day(payment.day) }}</dt>
                        <dd class="tabular">−{{ setup.money(payment.amount_minor) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 font-semibold"><dt>{{ t('education_fees.bill.balance') }}</dt><dd class="tabular text-[17px]" :class="bill.balance_minor ? 'text-fg' : 'text-ok'">{{ setup.money(bill.balance_minor) }}</dd></div>
                </dl>
            </div>

            <div class="mt-4 hidden sm:block print:block">
                <table class="w-full text-[13px]">
                    <thead class="text-[12px] text-muted">
                        <tr class="border-b border-line">
                            <th scope="col" class="py-2 text-start font-medium">{{ t('education_fees.bill.head') }}</th>
                            <th scope="col" class="py-2 text-end font-medium">{{ t('education_fees.bill.amount') }}</th>
                            <th scope="col" class="py-2 text-end font-medium">{{ t('education_fees.bill.discount') }}</th>
                            <th v-if="bill.tax_minor" scope="col" class="py-2 text-end font-medium">{{ t('education_fees.bill.tax') }}</th>
                            <th scope="col" class="py-2 text-end font-medium">{{ t('education_fees.bill.owed') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="line in bill.lines" :key="line.id">
                            <td class="py-2">
                                <span class="block font-medium text-fg">{{ textIn(head(line.head_id)?.name) || head(line.head_id)?.code }}</span>
                                <span v-if="basis(line)" class="block text-[12px] text-muted">{{ basis(line) }}</span>
                            </td>
                            <td class="tabular py-2 text-end">{{ setup.money(line.amount_minor) }}</td>
                            <td class="tabular py-2 text-end text-muted">{{ line.discount_minor ? `−${setup.money(line.discount_minor)}` : '—' }}</td>
                            <td v-if="bill.tax_minor" class="tabular py-2 text-end">{{ setup.money(line.tax_minor) }}</td>
                            <td class="tabular py-2 text-end font-medium">{{ setup.money(line.due_minor) }}</td>
                        </tr>
                        <tr v-for="fine in bill.fines" :key="fine.id" class="text-muted">
                            <td class="py-2" :colspan="bill.tax_minor ? 4 : 3">{{ fine.kind === 'fine' ? t('education_fees.bill.fines') : t('education_fees.bill.fine_waived') }} · {{ day(fine.applied_on) }}<template v-if="fine.reason"> · {{ fine.reason }}</template></td>
                            <td class="tabular py-2 text-end">{{ setup.money(fine.amount_minor) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="text-[13.5px]">
                        <tr class="border-t border-line-strong">
                            <th scope="row" class="pt-3 text-start font-semibold" :colspan="bill.tax_minor ? 4 : 3">{{ t('education_fees.bill.total') }}</th>
                            <td class="tabular pt-3 text-end font-semibold">{{ setup.money(bill.total_minor) }}</td>
                        </tr>
                        <tr v-for="payment in bill.payments" :key="`${payment.kind}-${payment.receipt_id}-${payment.day}`" class="text-muted">
                            <td class="pt-1" :colspan="bill.tax_minor ? 4 : 3">
                                <RouterLink v-if="payment.receipt_id" :to="{ name: 'fees-receipt', params: { id: payment.receipt_id } }" class="hover:underline">
                                    {{ t('education_fees.bill.paid') }} · <span class="font-mono" dir="ltr">{{ payment.number }}</span> · {{ t(`education_fees.methods.${payment.method}`) }} · {{ day(payment.day) }}
                                </RouterLink>
                                <span v-else>{{ t('education_fees.bill.from_advance') }} · {{ day(payment.day) }}</span>
                            </td>
                            <td class="tabular pt-1 text-end">−{{ setup.money(payment.amount_minor) }}</td>
                        </tr>
                        <tr>
                            <th scope="row" class="pt-2 text-start font-semibold" :colspan="bill.tax_minor ? 4 : 3">{{ t('education_fees.bill.balance') }}</th>
                            <td class="tabular pt-2 text-end text-[17px] font-semibold" :class="bill.balance_minor ? 'text-fg' : 'text-ok'">{{ setup.money(bill.balance_minor) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </article>

        <ReasonDialog :open="acting !== null" :busy="busy"
            :title="acting === 'waive' ? t('education_fees.bill.waive_title', { number: bill?.number }) : t('education_fees.student.cancel_title', { number: bill?.number })"
            :text="acting === 'waive' ? t('education_fees.bill.waive_text') : t('education_fees.student.cancel_text')"
            :confirm-label="acting === 'waive' ? t('education_fees.bill.waive') : t('education_fees.student.cancel_bill')"
            @close="acting = null" @submit="submit" />
    </div>
</template>
