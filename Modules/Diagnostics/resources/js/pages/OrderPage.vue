<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Ban, FileText, FlaskConical, PenLine, Printer, ReceiptText, Send, ShieldCheck, Tags, Undo2 } from 'lucide-vue-next';
import { AppButton, AppField, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppDialog from '@/components/AppDialog.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { flagTones, labApi, rangeText } from '../api';

/**
 * One order at the bench: take the samples (and print their labels), enter
 * each test's values (flags appear once saved), verify or send back someone
 * else's results, then give the report. The bill's due shows as a warning.
 */
const lab = labApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useResource(() => lab.setup());
const can = computed(() => setup.data.value?.data?.can ?? {});
const currency = computed(() => setup.data.value?.data?.currency);
const record = useResource(() => lab.order(route.params.id));
const order = computed(() => record.data.value?.data ?? null);
const tones = { ordered: 'neutral', collected: 'info', resulted: 'warn', verified: 'ok', delivered: 'ok' };
const busy = ref(false);

// The values typed per test, filled from what is saved.
const inputs = reactive({});
watch(order, (value) => {
    for (const line of value?.tests ?? []) {
        inputs[line.id] = Object.fromEntries(line.parameters.map((parameter) => [parameter.id, parameter.value ?? '']));
    }
}, { immediate: true });

async function run(call, success) {
    busy.value = true;
    try {
        const answer = await call();
        if (answer?.data?.tests) record.data.value = { data: answer.data };
        else record.reload();
        if (success) toast.success(success);
        return answer;
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'version_conflict') record.reload();
        return null;
    } finally {
        busy.value = false;
    }
}
async function collect() {
    if (await run(() => lab.collect(order.value.id), t('diagnostics.order.collected'))) router.push({ name: 'diagnostics-labels', params: { id: order.value.id } });
}
const save = (line) => run(() => lab.saveResults(line.id, { values: inputs[line.id], base_version: line.version }), t('diagnostics.order.saved'));
const verify = (line) => run(() => lab.verify(line.id), t('diagnostics.order.verified'));
const deliver = () => run(() => lab.deliver(order.value.id, { channel: 'hand' }), t('diagnostics.order.delivered'));

// Send back or reject, both with a reason
const asking = ref(null);
const reason = ref('');
async function confirmReason() {
    const { kind, target } = asking.value;
    const done = await run(() => (kind === 'reject' ? lab.rejectSample(target.id, { reason: reason.value.trim() }) : lab.sendBack(target.id, { reason: reason.value.trim() })),
        t(kind === 'reject' ? 'diagnostics.order.rejected' : 'diagnostics.order.sent_back'));
    if (done) {
        asking.value = null;
        reason.value = '';
    }
}
const editable = (line) => can.value.result && ['collected', 'resulted', 'verified'].includes(line.status);
</script>

<template>
    <PageFrame :title="order ? `${t('diagnostics.order.title')} ${order.number}` : ''" :description="order?.patient ? `${order.patient.name} · ${order.patient.mrn} · ${t(`diagnostics.sex.${order.patient.sex}`)} · ${t('diagnostics.common.years', { count: order.patient.age ?? '—' })}` : ''"
               width="xl" :resource="record" :not-found="{ icon: FlaskConical, title: t('diagnostics.order.not_found') }" guide="diagnostics.guide.bench">
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" class="print:hidden" @click="router.push({ name: 'diagnostics-orders' })">{{ t('diagnostics.order.back') }}</AppButton>
        </template>
        <template v-if="order" #actions>
            <AppBadge :tone="tones[order.status]" dot>{{ t(`diagnostics.stage.${order.status}`) }}</AppBadge>
            <AppButton v-if="can.collect && order.tests.some((line) => line.status === 'ordered')" variant="primary" :icon="FlaskConical" :loading="busy" @click="collect">{{ t('diagnostics.order.collect') }}</AppButton>
            <AppButton v-if="order.samples.length" :icon="Tags" @click="router.push({ name: 'diagnostics-labels', params: { id: order.id } })">{{ t('diagnostics.order.labels') }}</AppButton>
            <AppButton v-if="['verified', 'delivered'].includes(order.status)" :icon="Printer" @click="router.push({ name: 'diagnostics-report', params: { id: order.id } })">{{ t('diagnostics.order.report') }}</AppButton>
            <AppButton v-if="can.deliver && order.status === 'verified'" variant="primary" :icon="Send" :loading="busy" @click="deliver">{{ t('diagnostics.order.deliver') }}</AppButton>
            <AppButton v-if="order.bill_id && router.hasRoute('patient-billing-bill')" variant="ghost" :icon="ReceiptText" @click="router.push({ name: 'patient-billing-bill', params: { id: order.bill_id } })">{{ t('diagnostics.order.bill') }}</AppButton>
        </template>

        <template v-if="order">
            <p v-if="order.bill_due_minor" class="mb-4 rounded-lg bg-warn/10 px-4 py-2 text-[13px] text-warn" role="status">{{ t('diagnostics.order.bill_due', { amount: formatMoney({ amount: order.bill_due_minor, currency }) }) }}</p>
            <p v-if="order.late" class="mb-4 rounded-lg bg-bad/10 px-4 py-2 text-[13px] text-bad" role="status">{{ t('diagnostics.order.late', { time: formatDateTime(order.due_at) }) }}</p>
            <p v-if="order.referrer || order.notes" class="mb-4 text-[13px] text-muted">
                <template v-if="order.referrer">{{ t('diagnostics.order.referred_by', { name: order.referrer.name }) }}</template><template v-if="order.notes"> · {{ order.notes }}</template>
            </p>

            <section v-if="order.samples.length" class="card mb-4">
                <h2 class="border-b border-line px-5 py-2.5 text-[13.5px] font-semibold">{{ t('diagnostics.order.samples') }}</h2>
                <ul class="divide-y divide-line text-[13px]">
                    <li v-for="sample in order.samples" :key="sample.id" class="flex flex-wrap items-center gap-3 px-5 py-2">
                        <span class="font-mono font-medium" dir="ltr">{{ sample.barcode }}</span>
                        <span>{{ t(`diagnostics.sample.${sample.sample_type}`) }}</span>
                        <span class="text-muted">{{ formatDateTime(sample.collected_at) }} · {{ sample.collected_by_name }}</span>
                        <AppBadge v-if="sample.rejected_reason" tone="bad">{{ t('diagnostics.order.sample_rejected', { reason: sample.rejected_reason }) }}</AppBadge>
                        <AppButton v-else-if="can.collect" class="ms-auto" size="sm" variant="ghost" :icon="Ban" @click="asking = { kind: 'reject', target: sample }">{{ t('diagnostics.order.reject') }}</AppButton>
                    </li>
                </ul>
            </section>

            <section v-for="line in order.tests" :key="line.id" class="card mb-4">
                <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                    <h2 class="min-w-0 flex-1 text-[14.5px] font-semibold"><span class="font-mono text-[12px] text-muted" dir="ltr">{{ line.code }}</span> {{ line.name }}</h2>
                    <span v-if="line.barcode" class="font-mono text-[12px] text-muted" dir="ltr">{{ line.barcode }}</span>
                    <AppBadge v-if="line.amended" tone="info">{{ t('diagnostics.order.amendment', { number: line.amendment }) }}</AppBadge>
                    <AppBadge :tone="tones[line.status]" dot>{{ t(`diagnostics.stage.${line.status}`) }}</AppBadge>
                </header>
                <p v-if="line.send_back_note" class="mx-5 mt-3 rounded-lg bg-warn/10 px-3 py-2 text-[13px] text-warn">{{ t('diagnostics.order.sent_back_note', { note: line.send_back_note }) }}</p>
                <p v-if="line.status === 'ordered'" class="px-5 py-3 text-[13px] text-muted">{{ t('diagnostics.order.waiting_sample') }}</p>
                <form v-else class="grid gap-2 p-5" @submit.prevent="save(line)">
                    <div v-for="parameter in line.parameters" :key="parameter.id" class="grid grid-cols-1 items-center gap-2 sm:grid-cols-[minmax(0,14rem)_minmax(0,12rem)_minmax(0,1fr)]">
                        <label :for="`v-${parameter.id}`" class="text-[13.5px] font-medium">{{ parameter.name }}<span v-if="parameter.unit" class="text-muted"> ({{ parameter.unit }})</span></label>
                        <select v-if="parameter.result_type === 'choice'" :id="`v-${parameter.id}`" v-model="inputs[line.id][parameter.id]" class="field-input" :disabled="!editable(line)">
                            <option value="">—</option>
                            <option v-for="choice in parameter.choices" :key="choice" :value="choice">{{ choice }}</option>
                        </select>
                        <input v-else :id="`v-${parameter.id}`" v-model="inputs[line.id][parameter.id]" class="field-input" :class="parameter.result_type === 'number' && 'tabular'"
                               :inputmode="parameter.result_type === 'number' ? 'decimal' : 'text'" :dir="parameter.result_type === 'number' ? 'ltr' : undefined" :disabled="!editable(line)" maxlength="200" />
                        <span class="flex items-center gap-2 text-[12.5px] text-muted">
                            <span v-if="rangeText(parameter.range)">{{ t('diagnostics.order.normal', { range: rangeText(parameter.range) }) }}</span>
                            <AppBadge v-if="parameter.flag" :tone="flagTones[parameter.flag]">{{ t(`diagnostics.flag.${parameter.flag}`) }}</AppBadge>
                        </span>
                    </div>
                    <p v-if="!line.parameters.length" class="text-[13px] text-muted">{{ t('diagnostics.order.no_parameters') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2 print:hidden">
                        <span v-if="line.resulted_by_name" class="me-auto text-[12px] text-muted">{{ t('diagnostics.order.entered_by', { name: line.resulted_by_name }) }}<template v-if="line.verified_by_name"> · {{ t('diagnostics.order.verified_by', { name: line.verified_by_name }) }}</template></span>
                        <AppButton v-if="editable(line) && line.parameters.length" type="submit" :icon="PenLine" :loading="busy">{{ line.status === 'verified' ? t('diagnostics.order.amend') : t('diagnostics.order.save') }}</AppButton>
                        <template v-if="can.verify && line.status === 'resulted'">
                            <span v-if="line.resulted_by_me" class="text-[12px] text-muted">{{ t('diagnostics.order.someone_else') }}</span>
                            <template v-else>
                                <AppButton variant="ghost" :icon="Undo2" @click="asking = { kind: 'send_back', target: line }">{{ t('diagnostics.order.send_back') }}</AppButton>
                                <AppButton variant="primary" :icon="ShieldCheck" :loading="busy" @click="verify(line)">{{ t('diagnostics.order.verify') }}</AppButton>
                            </template>
                        </template>
                    </div>
                </form>
            </section>
            <p v-if="order.delivered_at" class="flex items-center gap-2 text-[13px] text-muted"><FileText class="size-4" aria-hidden="true" />{{ t('diagnostics.order.delivered_at', { time: formatDateTime(order.delivered_at) }) }}</p>
        </template>

        <AppDialog :open="!!asking" :title="asking?.kind === 'reject' ? t('diagnostics.order.reject') : t('diagnostics.order.send_back')" :description="asking?.kind === 'reject' ? t('diagnostics.order.reject_text') : t('diagnostics.order.send_back_text')" :icon="asking?.kind === 'reject' ? Ban : Undo2" @close="asking = null">
            <AppField v-slot="{ id }" :label="t('diagnostics.order.reason')"><input :id="id" v-model="reason" class="field-input" maxlength="300" /></AppField>
            <template #footer>
                <AppButton variant="ghost" @click="asking = null">{{ t('diagnostics.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="reason.trim().length < 3" @click="confirmReason">{{ t('diagnostics.common.confirm') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
