<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronRight, FlaskConical, Plus, ScanLine, Search } from 'lucide-vue-next';
import { AppButton, AppField, AppSegmented, EmptyState, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppDialog from '@/components/AppDialog.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { labApi } from '../api';

/**
 * The lab's worklist by stage (to collect, to enter, to verify, to give), a
 * scan box that opens the order of a tube or an order number, and a new test
 * order for a patient (billed on their bill).
 */
const lab = labApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useResource(() => lab.setup());
const info = computed(() => setup.data.value?.data ?? {});
const can = computed(() => info.value.can ?? {});
const stages = ['ordered', 'collected', 'resulted', 'verified', 'delivered'];
const stage = ref(stages.includes(route.query.stage) ? route.query.stage : '');
const page = ref(1);
// From a patient's page: only that patient's orders.
const patientId = typeof route.query.patient === 'string' ? route.query.patient : '';
const list = useResource(() => lab.orders({ stage: stage.value, page: page.value, patient: patientId }));
watch(stage, () => {
    page.value = 1;
    router.replace({ query: stage.value ? { stage: stage.value } : {} });
    list.reload();
});
watch(page, () => list.reload());
const rows = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? {});
const choices = computed(() => [{ value: '', label: t('diagnostics.orders.all') }, ...stages.map((value) => ({ value, label: t(`diagnostics.stage_tab.${value}`) }))]);
const tones = { ordered: 'neutral', collected: 'info', resulted: 'warn', verified: 'ok', delivered: 'ok' };
const exported = computed(() => ({
    module: 'diagnostics', page: 'diagnostics.orders', rows: rows.value,
    columns: [
        { key: 'number', label: t('diagnostics.orders.number') },
        { key: 'patient', label: t('diagnostics.orders.patient'), value: (row) => `${row.patient?.mrn ?? ''} ${row.patient?.name ?? ''}` },
        { key: 'status', label: t('diagnostics.orders.status'), value: (row) => t(`diagnostics.stage.${row.status}`) },
        { key: 'due_at', label: t('diagnostics.orders.due'), type: 'date' },
    ],
}));

// Scan a tube (or type an order number)
const scan = ref('');
async function openScanned() {
    const code = scan.value.trim().toUpperCase();
    if (!code) return;
    try {
        const found = (await lab.bySample(code)).data;
        router.push({ name: 'diagnostics-order', params: { id: found.order_id } });
    } catch (error) {
        const byNumber = (await lab.orders({ q: code }).catch(() => ({ data: [] }))).data ?? [];
        if (byNumber[0]) router.push({ name: 'diagnostics-order', params: { id: byNumber[0].id } });
        else toast.error(error.message);
    }
    scan.value = '';
}

// A new order
const ordering = ref(route.query.new === '1');
const draft = reactive({ patient: null, tests: [], referrer_id: '', notes: '' });
const term = ref('');
const found = ref([]);
let timer = null;
watch(term, (value) => {
    clearTimeout(timer);
    if (value.trim().length < 2) {
        found.value = [];
        return;
    }
    timer = setTimeout(async () => {
        found.value = (await lab.patients(value.trim()).catch((error) => {
            toast.error(error.message);
            return { data: [] };
        })).data;
    }, 300);
});
const testFilter = ref('');
const tests = computed(() => (info.value.options?.tests ?? []).filter((test) => !testFilter.value.trim()
    || `${test.code} ${test.name}`.toLowerCase().includes(testFilter.value.trim().toLowerCase())));
const total = computed(() => (info.value.options?.tests ?? []).filter((test) => draft.tests.includes(test.id)).reduce((sum, test) => sum + (test.price_minor ?? 0), 0));
const saving = ref(false);
let opId = crypto.randomUUID();
async function place() {
    saving.value = true;
    try {
        const order = (await lab.place({ patient_id: draft.patient.id, test_ids: draft.tests, referrer_id: draft.referrer_id || null, notes: draft.notes.trim() || null, op_id: opId })).data;
        opId = crypto.randomUUID();
        toast.success(t('diagnostics.orders.placed', { number: order.number }));
        router.push({ name: 'diagnostics-order', params: { id: order.id } });
    } catch (error) {
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <PageFrame :title="t('diagnostics.orders.title')" :description="t('diagnostics.orders.text')" width="full" :export="exported" guide="diagnostics.guide.orders"
               :guide-settings="{ name: 'module-settings', params: { module: 'diagnostics' } }">
        <template v-if="can.order" #actions>
            <AppButton variant="primary" :icon="Plus" @click="ordering = true">{{ t('diagnostics.orders.new') }}</AppButton>
        </template>
        <div class="mb-4 flex flex-wrap items-center gap-3 print:hidden">
            <form class="relative w-full max-w-xs" @submit.prevent="openScanned">
                <ScanLine class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <input v-model="scan" class="field-input ps-9" dir="ltr" :placeholder="t('diagnostics.orders.scan')" :aria-label="t('diagnostics.orders.scan')" />
            </form>
            <AppSegmented v-model="stage" :options="choices" :label="t('diagnostics.orders.stage_label')" />
        </div>
        <section class="card">
            <div v-if="list.loading.value && !rows.length" class="p-5 text-[13px] text-muted">{{ t('diagnostics.common.loading') }}</div>
            <EmptyState v-else-if="list.error.value" :icon="FlaskConical" :title="list.error.value.message" compact />
            <EmptyState v-else-if="!rows.length" :icon="FlaskConical" :title="t('diagnostics.orders.empty')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="row.id">
                    <button type="button" class="flex w-full items-center gap-3 px-5 py-3 text-start hover:bg-subtle" @click="router.push({ name: 'diagnostics-order', params: { id: row.id } })">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium"><span class="font-mono" dir="ltr">{{ row.number }}</span> · {{ row.patient?.name ?? '—' }}</span>
                            <span class="block text-[12.5px] text-muted">{{ formatDateTime(row.created_at) }}<template v-if="row.due_at"> · {{ t('diagnostics.orders.due_at', { time: formatDateTime(row.due_at) }) }}</template></span>
                        </span>
                        <AppBadge v-if="row.late" tone="bad" dot>{{ t('diagnostics.orders.late') }}</AppBadge>
                        <AppBadge :tone="tones[row.status]" dot>{{ t(`diagnostics.stage.${row.status}`) }}</AppBadge>
                        <ChevronRight class="size-4 shrink-0 text-muted rtl:rotate-180" aria-hidden="true" />
                    </button>
                </li>
            </ul>
            <div v-if="meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-2 text-[12.5px] print:hidden">
                <AppButton size="sm" variant="ghost" :disabled="page <= 1" @click="page--">{{ t('diagnostics.common.previous') }}</AppButton>
                <span class="text-muted">{{ t('diagnostics.common.page', { page: meta.page, last: meta.last_page }) }}</span>
                <AppButton size="sm" variant="ghost" :disabled="page >= meta.last_page" @click="page++">{{ t('diagnostics.common.next') }}</AppButton>
            </div>
        </section>

        <AppDialog :open="ordering" size="lg" :title="t('diagnostics.orders.new')" :description="t('diagnostics.orders.new_text')" :icon="FlaskConical" @close="ordering = false">
            <div class="grid gap-3">
                <AppField :label="t('diagnostics.orders.patient')">
                    <div v-if="draft.patient" class="flex items-center justify-between rounded-lg bg-subtle px-3 py-2 text-[13.5px]">
                        <span><span class="font-medium">{{ draft.patient.name }}</span> <span class="font-mono text-[12px] text-muted" dir="ltr">{{ draft.patient.mrn }}</span> · {{ t('diagnostics.common.years', { count: draft.patient.age ?? '—' }) }}</span>
                        <button type="button" class="text-[12.5px] font-medium text-accent" @click="draft.patient = null">{{ t('diagnostics.orders.change') }}</button>
                    </div>
                    <div v-else class="grid gap-2">
                        <div class="relative">
                            <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                            <input v-model="term" type="search" class="field-input ps-9" :placeholder="t('diagnostics.orders.find_patient')" :aria-label="t('diagnostics.orders.find_patient')" />
                        </div>
                        <ul v-if="found.length" class="max-h-48 divide-y divide-line overflow-auto rounded-lg ring-1 ring-line">
                            <li v-for="patient in found" :key="patient.id">
                                <button type="button" class="w-full px-3 py-2 text-start text-[13.5px] hover:bg-subtle" @click="draft.patient = patient">
                                    <span class="font-medium">{{ patient.name }}</span> <span class="font-mono text-[12px] text-muted" dir="ltr">{{ patient.mrn }}</span> · {{ t('diagnostics.common.years', { count: patient.age ?? '—' }) }}
                                </button>
                            </li>
                        </ul>
                        <p v-else-if="term.trim().length >= 2" class="text-[12.5px] text-muted">{{ t('diagnostics.orders.no_patient') }}</p>
                    </div>
                </AppField>
                <AppField :label="t('diagnostics.orders.tests')" :hint="t('diagnostics.orders.tests_hint')">
                    <div class="grid gap-2">
                        <input v-model="testFilter" type="search" class="field-input" :placeholder="t('diagnostics.orders.filter_tests')" :aria-label="t('diagnostics.orders.filter_tests')" />
                        <ul class="max-h-56 divide-y divide-line overflow-auto rounded-lg ring-1 ring-line">
                            <li v-for="test in tests" :key="test.id">
                                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-[13.5px]">
                                    <input v-model="draft.tests" type="checkbox" :value="test.id" />
                                    <span class="min-w-0 flex-1"><span class="font-mono text-[12px] text-muted" dir="ltr">{{ test.code }}</span> {{ test.name }}</span>
                                    <span class="tabular text-muted">{{ formatMoney({ amount: test.price_minor, currency: info.currency }) }}</span>
                                </label>
                            </li>
                        </ul>
                    </div>
                </AppField>
                <AppField v-if="(info.options?.referrers ?? []).length" v-slot="{ id }" :label="t('diagnostics.orders.referrer')">
                    <select :id="id" v-model="draft.referrer_id" class="field-input">
                        <option value="">{{ t('diagnostics.orders.no_referrer') }}</option>
                        <option v-for="doctor in info.options.referrers" :key="doctor.id" :value="doctor.id">{{ doctor.name }}<template v-if="doctor.registration_no"> ({{ doctor.registration_no }})</template></option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('diagnostics.orders.notes')"><input :id="id" v-model="draft.notes" class="field-input" maxlength="300" /></AppField>
            </div>
            <template #footer>
                <span class="me-auto text-[13.5px] font-medium tabular">{{ t('diagnostics.orders.total', { amount: formatMoney({ amount: total, currency: info.currency }) }) }}</span>
                <AppButton variant="ghost" @click="ordering = false">{{ t('diagnostics.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="saving" :disabled="!draft.patient || !draft.tests.length" @click="place">{{ t('diagnostics.orders.place') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
