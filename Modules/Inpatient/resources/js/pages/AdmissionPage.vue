<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, ArrowRightLeft, BedDouble, ReceiptText, XCircle } from 'lucide-vue-next';
import { AppBadge, AppButton, AppDialog, AppField, AppTabs, PageFrame, confirmAction, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { ipdApi } from '../api';
import CarePanel from '../components/CarePanel.vue';
import DischargePanel from '../components/DischargePanel.vue';

/**
 * One stay: the patient, bed and doctor, what is due on the bill, the bed
 * moves and the bed days charged. The desk moves the patient to a free bed or
 * cancels a mistaken admission (with a reason, while the bill is open).
 * Those who give care also see tabs for round notes, nursing notes, tests and
 * the discharge; the server decides who may read and write each.
 */
const ipd = ipdApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const view = useResource(() => ipd.admission(route.params.id));
const stay = computed(() => view.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: stay.value?.currency });
const statusTone = { admitted: 'info', discharged: 'ok', cancelled: 'neutral' };

const caring = computed(() => !!(stay.value?.can.round || stay.value?.can.nurse || stay.value?.can.discharge));
const tabs = computed(() => ['overview', ...(caring.value ? ['rounds', 'nursing', 'tests', 'discharge'] : [])].map((key) => ({ key, label: t(`inpatient.tabs.${key}`) })));
const tab = ref(['rounds', 'nursing', 'tests', 'discharge'].includes(route.query.tab) ? route.query.tab : 'overview');
// The tab shown: one this person has, else the overview.
const shown = computed(() => (tabs.value.some((item) => item.key === tab.value) ? tab.value : 'overview'));
watch(tab, (value) => router.replace({ query: value === 'overview' ? {} : { tab: value } }));
const care = ref(null);
async function loadCare() {
    care.value = (await ipd.care(route.params.id).catch((error) => {
        toast.error(error.message);
        return { data: null };
    })).data;
}
watch([tab, caring], ([value, can]) => {
    if (can && ['rounds', 'nursing', 'tests'].includes(value) && !care.value) loadCare();
}, { immediate: true });

const moving = ref(false);
const board = ref([]);
const target = ref('');
const reason = ref('');
const busy = ref(false);
async function startMove() {
    board.value = ((await ipd.board().catch(() => ({ data: [] }))).data ?? []).flatMap((ward) => ward.beds.filter((bed) => bed.status === 'free').map((bed) => ({ ...bed, ward: ward.name })));
    target.value = '';
    reason.value = '';
    moving.value = true;
}
async function move() {
    busy.value = true;
    try {
        await ipd.move(stay.value.id, { bed_id: target.value, reason: reason.value.trim() || null, base_version: stay.value.version });
        toast.success(t('inpatient.admission.moved'));
        moving.value = false;
        view.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
async function cancel() {
    const confirmed = await confirmAction({ title: t('inpatient.admission.cancel_title', { number: stay.value.number }), message: t('inpatient.admission.cancel_text'), confirmLabel: t('inpatient.admission.cancel'), reason: 'required', danger: true });
    if (!confirmed) return;
    try {
        await ipd.cancel(stay.value.id, { reason: confirmed.reason, base_version: stay.value.version });
        toast.success(t('inpatient.admission.cancelled'));
        view.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <PageFrame :title="stay ? `${stay.number} · ${stay.patient?.name ?? ''}` : ''" width="lg" :resource="view" :guide="shown === 'discharge' ? 'inpatient.guide.discharge' : 'inpatient.guide.admissions'" :not-found="{ icon: BedDouble, title: t('inpatient.admission.not_found') }">
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" @click="router.push({ name: 'inpatient-admissions' })">{{ t('inpatient.admission.back') }}</AppButton>
        </template>
        <template v-if="stay" #eyebrow>
            <AppBadge :tone="statusTone[stay.status]" dot>{{ t(`inpatient.status.${stay.status}`) }}</AppBadge>
        </template>
        <template v-if="stay?.status === 'admitted' && stay.can.admit" #actions>
            <AppButton variant="ghost" :icon="XCircle" @click="cancel">{{ t('inpatient.admission.cancel') }}</AppButton>
            <AppButton variant="primary" :icon="ArrowRightLeft" @click="startMove">{{ t('inpatient.admission.move') }}</AppButton>
        </template>

        <AppTabs v-if="stay && tabs.length > 1" :model-value="shown" @update:model-value="tab = $event" :tabs="tabs" :label="t('inpatient.tabs.label')" class="mb-4" />
        <CarePanel v-if="stay && care && ['rounds', 'nursing', 'tests'].includes(shown)" :kind="shown" :care="care" :admitted="stay.status === 'admitted'" :api="ipd" :admission-id="stay.id" @changed="loadCare" />
        <DischargePanel v-else-if="stay && shown === 'discharge'" :api="ipd" :admission-id="stay.id" @changed="view.reload()" />
        <template v-else-if="stay && shown === 'overview'">
            <dl class="mb-4 grid gap-3 sm:grid-cols-4">
                <div class="card px-4 py-3"><dt class="text-[12px] text-muted">{{ t('inpatient.admissions.bed') }}</dt><dd class="text-[15px] font-semibold">{{ stay.bed?.ward }} · <span dir="ltr">{{ stay.bed?.code }}</span></dd></div>
                <div class="card px-4 py-3"><dt class="text-[12px] text-muted">{{ t('inpatient.admissions.doctor') }}</dt><dd class="text-[15px] font-semibold">{{ stay.doctor?.name }}</dd></div>
                <div class="card px-4 py-3"><dt class="text-[12px] text-muted">{{ t('inpatient.admissions.admitted_at') }}</dt><dd class="text-[15px] font-semibold">{{ formatDateTime(stay.admitted_at) }}</dd></div>
                <div class="card px-4 py-3">
                    <dt class="text-[12px] text-muted">{{ t('inpatient.admission.due') }}</dt>
                    <dd class="text-[15px] font-semibold tabular">{{ stay.bill_due_minor === null ? '—' : money(stay.bill_due_minor) }}</dd>
                    <AppButton v-if="stay.bill_id" size="sm" variant="ghost" :icon="ReceiptText" class="mt-1" @click="router.push({ name: 'patient-billing-bill', params: { id: stay.bill_id } })">{{ t('inpatient.admission.open_bill') }}</AppButton>
                </div>
            </dl>
            <section class="card mb-4 p-4 text-[13.5px]">
                <p><span class="text-muted">{{ t('inpatient.admissions.patient') }}:</span> {{ stay.patient?.name }} · <span class="font-mono" dir="ltr">{{ stay.patient?.mrn }}</span></p>
                <p v-if="stay.reason"><span class="text-muted">{{ t('inpatient.admit.reason') }}:</span> {{ stay.reason }}</p>
                <p v-if="stay.guardian_name || stay.guardian_mobile"><span class="text-muted">{{ t('inpatient.admit.guardian') }}:</span> {{ stay.guardian_name }} <span dir="ltr">{{ stay.guardian_mobile }}</span></p>
                <p v-if="stay.cancel_reason"><span class="text-muted">{{ t('inpatient.admission.cancel_reason') }}:</span> {{ stay.cancel_reason }}</p>
            </section>
            <div class="grid gap-4 md:grid-cols-2">
                <section class="card">
                    <h2 class="border-b border-line px-4 py-2.5 text-[13.5px] font-semibold">{{ t('inpatient.admission.moves') }}</h2>
                    <ul class="divide-y divide-line text-[13px]">
                        <li v-for="(item, index) in stay.moves" :key="index" class="px-4 py-2">
                            <p>{{ formatDateTime(item.moved_at) }} · <template v-if="item.from">{{ item.from.ward }} {{ item.from.code }} → </template>{{ item.to?.ward }} {{ item.to?.code }}</p>
                            <p v-if="item.reason" class="text-muted">{{ item.reason }}</p>
                        </li>
                    </ul>
                </section>
                <section class="card">
                    <h2 class="border-b border-line px-4 py-2.5 text-[13.5px] font-semibold">{{ t('inpatient.admission.bed_days') }}</h2>
                    <ul class="divide-y divide-line text-[13px]">
                        <li v-for="day in stay.bed_days" :key="day.day_no" class="flex justify-between px-4 py-2">
                            <span>{{ t('inpatient.admission.day', { n: day.day_no }) }} · {{ formatDate(day.day_on) }}</span>
                            <span class="text-muted">{{ day.bed?.ward }} {{ day.bed?.code }}</span>
                        </li>
                    </ul>
                </section>
            </div>
        </template>

        <AppDialog :open="moving" :title="t('inpatient.admission.move')" :description="t('inpatient.admission.move_text')" :icon="ArrowRightLeft" @close="moving = false">
            <div class="grid gap-3">
                <AppField v-slot="{ id }" :label="t('inpatient.admit.bed')">
                    <select :id="id" v-model="target" class="field-input">
                        <option value="">{{ board.length ? t('inpatient.admit.pick_bed') : t('inpatient.admit.no_free_bed') }}</option>
                        <option v-for="bed in board" :key="bed.id" :value="bed.id">{{ bed.ward }} · {{ bed.code }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('inpatient.admission.move_reason')"><input :id="id" v-model="reason" class="field-input" maxlength="300" /></AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="moving = false">{{ t('inpatient.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!target" @click="move">{{ t('inpatient.admission.move') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
