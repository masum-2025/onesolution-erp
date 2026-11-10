<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronRight, HandCoins, Plus, ReceiptText } from 'lucide-vue-next';
import { AppButton, AppField, AppSegmented, EmptyState, MoneyInput, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppDialog from '@/components/AppDialog.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { billingApi, money } from '../api';
import PatientPicker from '../components/PatientPicker.vue';

/**
 * Bills of the branch: open ones being filled, dues, final and void ones;
 * a new bill for a patient (with a corporate or panel client if any), and an
 * advance taken before any bill.
 */
const pb = billingApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useResource(() => pb.setup());
const info = computed(() => setup.data.value?.data ?? {});
const can = computed(() => info.value.can ?? {});
const currency = computed(() => info.value.currency);

const status = ref(['open', 'due', 'final', 'void'].includes(route.query.status) ? route.query.status : '');
const number = ref('');
const page = ref(1);
// From a patient's page: only that patient's bills.
const patientId = typeof route.query.patient === 'string' ? route.query.patient : '';
const list = useResource(() => pb.bills({ status: status.value, q: number.value.trim(), page: page.value, patient: patientId }));
watch([status, page], () => list.reload());
const rows = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? {});
const choices = computed(() => [{ value: '', label: t('patient_billing.bills.all') }, ...['open', 'due', 'final', 'void'].map((value) => ({ value, label: t(`patient_billing.bills.filter.${value}`) }))]);
const tones = { open: 'warn', final: 'ok', void: 'neutral' };
const exported = computed(() => ({
    module: 'patient_billing', page: 'patient_billing.bills', rows: rows.value,
    columns: [
        { key: 'number', label: t('patient_billing.bills.number') },
        { key: 'patient', label: t('patient_billing.bills.patient'), value: (row) => `${row.patient?.mrn ?? ''} ${row.patient?.name ?? ''}` },
        { key: 'status', label: t('patient_billing.bills.status'), value: (row) => t(`patient_billing.status.${row.status}`) },
        { key: 'total_minor', label: t('patient_billing.bills.total'), type: 'money', currency: currency.value },
        { key: 'due_minor', label: t('patient_billing.bills.due'), type: 'money', currency: currency.value },
        { key: 'created_at', label: t('patient_billing.bills.date'), type: 'date' },
    ],
}));

// A new bill
const opening = ref(route.query.new === '1');
const draft = reactive({ patient: null, panel_id: '' });
let opId = crypto.randomUUID();
const saving = ref(false);
async function openBill() {
    saving.value = true;
    try {
        const bill = (await pb.open({ patient_id: draft.patient.id, panel_id: draft.panel_id || null, op_id: opId })).data;
        opId = crypto.randomUUID();
        router.push({ name: 'patient-billing-bill', params: { id: bill.id } });
    } catch (error) {
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

// An advance
const advancing = ref(false);
const advance = reactive({ patient: null, amount: null, method: 'cash', reference: '' });
const methods = computed(() => ['cash', 'card', 'mobile'].map((value) => ({ value, label: t(`patient_billing.method.${value}`) })));
async function takeAdvance() {
    saving.value = true;
    try {
        const paid = (await pb.pay({ op_id: crypto.randomUUID(), kind: 'advance', patient_id: advance.patient.id, method: advance.method, amount_minor: advance.amount, reference: advance.reference.trim() || null })).data;
        toast.success(t('patient_billing.bills.advance_taken', { number: paid.number }));
        Object.assign(advance, { patient: null, amount: null, method: 'cash', reference: '' });
        advancing.value = false;
    } catch (error) {
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <PageFrame :title="t('patient_billing.bills.title')" :description="t('patient_billing.bills.text')" width="full" :export="exported" guide="patient_billing.guide.bills"
               :guide-settings="{ name: 'module-settings', params: { module: 'patient_billing' } }">
        <template v-if="can.collect" #actions>
            <AppButton :icon="HandCoins" @click="advancing = true">{{ t('patient_billing.bills.advance') }}</AppButton>
            <AppButton variant="primary" :icon="Plus" @click="opening = true">{{ t('patient_billing.bills.new') }}</AppButton>
        </template>
        <div class="mb-4 flex flex-wrap items-center gap-3 print:hidden">
            <AppSegmented v-model="status" :options="choices" :label="t('patient_billing.bills.filter_label')" />
            <form class="flex gap-2" @submit.prevent="page = 1; list.reload()">
                <input v-model="number" class="field-input w-40" dir="ltr" :placeholder="t('patient_billing.bills.find_number')" :aria-label="t('patient_billing.bills.find_number')" />
            </form>
        </div>
        <section class="card">
            <div v-if="list.loading.value && !rows.length" class="p-5 text-[13px] text-muted">{{ t('patient_billing.common.loading') }}</div>
            <EmptyState v-else-if="list.error.value" :icon="ReceiptText" :title="list.error.value.message" compact />
            <EmptyState v-else-if="!rows.length" :icon="ReceiptText" :title="t('patient_billing.bills.empty')" :text="can.collect ? t('patient_billing.bills.empty_text') : ''" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="row.id">
                    <button type="button" class="flex w-full items-center gap-3 px-5 py-3 text-start hover:bg-subtle" @click="router.push({ name: 'patient-billing-bill', params: { id: row.id } })">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium"><span class="font-mono" dir="ltr">{{ row.number }}</span> · {{ row.patient?.name ?? '—' }}</span>
                            <span class="block text-[12.5px] text-muted">{{ formatDateTime(row.created_at) }}<template v-if="row.patient"> · <span class="font-mono" dir="ltr">{{ row.patient.mrn }}</span></template></span>
                        </span>
                        <span class="text-end text-[13.5px] tabular">
                            <span class="block font-medium">{{ formatMoney(money(row.total_minor, row.currency)) }}</span>
                            <span v-if="row.due_minor && row.status !== 'void'" class="block text-[12px] text-bad">{{ t('patient_billing.bills.due_short', { amount: formatMoney(money(row.due_minor, row.currency)) }) }}</span>
                        </span>
                        <AppBadge :tone="tones[row.status]" dot>{{ t(`patient_billing.status.${row.status}`) }}</AppBadge>
                        <ChevronRight class="size-4 shrink-0 text-muted rtl:rotate-180" aria-hidden="true" />
                    </button>
                </li>
            </ul>
            <div v-if="meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-2 text-[12.5px] print:hidden">
                <AppButton size="sm" variant="ghost" :disabled="page <= 1" @click="page--">{{ t('patient_billing.common.previous') }}</AppButton>
                <span class="text-muted">{{ t('patient_billing.common.page', { page: meta.page, last: meta.last_page }) }}</span>
                <AppButton size="sm" variant="ghost" :disabled="page >= meta.last_page" @click="page++">{{ t('patient_billing.common.next') }}</AppButton>
            </div>
        </section>

        <AppDialog :open="opening" :title="t('patient_billing.bills.new')" :description="t('patient_billing.bills.new_text')" :icon="ReceiptText" @close="opening = false">
            <div class="grid gap-3">
                <AppField :label="t('patient_billing.bills.patient')"><PatientPicker v-model="draft.patient" :search="pb.patients" :currency="currency" /></AppField>
                <AppField v-if="(info.options?.panel_id ?? []).length" v-slot="{ id }" :label="t('patient_billing.bill.panel')" :hint="t('patient_billing.bill.panel_hint')">
                    <select :id="id" v-model="draft.panel_id" class="field-input">
                        <option value="">{{ t('patient_billing.bill.no_panel') }}</option>
                        <option v-for="panel in info.options.panel_id" :key="panel.id" :value="panel.id">{{ panel.name }}<template v-if="panel.discount_percent"> ({{ panel.discount_percent }}%)</template></option>
                    </select>
                </AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="opening = false">{{ t('patient_billing.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="saving" :disabled="!draft.patient" @click="openBill">{{ t('patient_billing.bills.open') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="advancing" :title="t('patient_billing.bills.advance')" :description="t('patient_billing.bills.advance_text')" :icon="HandCoins" @close="advancing = false">
            <div class="grid gap-3">
                <AppField :label="t('patient_billing.bills.patient')"><PatientPicker v-model="advance.patient" :search="pb.patients" :currency="currency" /></AppField>
                <AppField v-slot="{ id }" :label="t('patient_billing.pay.amount')"><MoneyInput :id="id" v-model="advance.amount" :currency="currency" /></AppField>
                <AppSegmented v-model="advance.method" :options="methods" :label="t('patient_billing.pay.method')" />
                <AppField v-if="advance.method !== 'cash'" v-slot="{ id }" :label="t('patient_billing.pay.reference')" :hint="t('patient_billing.pay.reference_hint')">
                    <input :id="id" v-model="advance.reference" class="field-input" dir="ltr" maxlength="100" />
                </AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="advancing = false">{{ t('patient_billing.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="saving" :disabled="!advance.patient || !advance.amount" @click="takeAdvance">{{ t('patient_billing.pay.take') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
