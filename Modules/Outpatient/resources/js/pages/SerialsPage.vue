<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CalendarDays, MonitorPlay, Plus, Printer, RefreshCw, Stethoscope } from 'lucide-vue-next';
import { AppBadge, AppButton, AppField, DateInput, PageFrame, confirmAction, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { opdApi, statusTones } from '../api';

/**
 * Today's serials at the desk (or another day's): by doctor, each serial's
 * next step one tap away (arrived, in, done, absent; cancel with a reason),
 * the slip, and the waiting-room screen of each chamber. A doctor limited to
 * their own patients sees only theirs. Fresh every 30 seconds.
 */
const opd = opdApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const date = ref(typeof route.query.date === 'string' ? route.query.date : '');
const doctor = ref(typeof route.query.doctor === 'string' ? route.query.doctor : '');
const list = useResource(() => opd.serials({ date: date.value || undefined, doctor: doctor.value || undefined }));
watch([date, doctor], () => {
    router.replace({ query: { ...(date.value ? { date: date.value } : {}), ...(doctor.value ? { doctor: doctor.value } : {}) } });
    list.reload();
});
const timer = setInterval(() => list.reload(), 30000);
onBeforeUnmount(() => clearInterval(timer));

const rows = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? {});
const can = computed(() => meta.value.can ?? {});
const chambers = computed(() => [...new Map(rows.value.filter((row) => row.chamber).map((row) => [row.chamber.id, row.chamber])).values()]);
// The steps a person may take here: the desk all of them, a doctor calling in and finishing.
const steps = (row) => row.next.filter((step) => can.value.book || (can.value.consult && ['in', 'done'].includes(step)));
const busy = ref('');
const exported = computed(() => ({
    module: 'outpatient', page: 'outpatient.serials', rows: rows.value,
    columns: [
        { key: 'serial_no', label: t('outpatient.serials.number') },
        { key: 'patient', label: t('outpatient.serials.patient'), value: (row) => `${row.patient?.mrn ?? ''} ${row.patient?.name ?? ''}` },
        { key: 'doctor', label: t('outpatient.serials.doctor'), value: (row) => row.doctor?.name ?? '' },
        { key: 'session', label: t('outpatient.serials.session'), value: (row) => (row.session ? `${row.session.starts_at}–${row.session.ends_at}` : '') },
        { key: 'status', label: t('outpatient.serials.status'), value: (row) => t(`outpatient.status.${row.status}`) },
    ],
}));

async function step(row, to) {
    let reason = null;
    if (to === 'cancelled') {
        const confirmed = await confirmAction({
            title: t('outpatient.serials.cancel_title', { number: row.serial_no }), message: t('outpatient.serials.cancel_text'),
            confirmLabel: t('outpatient.step.cancelled'), reason: 'required', danger: true,
        });
        if (!confirmed) return;
        reason = confirmed.reason;
    }
    busy.value = `${row.id}:${to}`;
    try {
        const moved = await opd.move(row.id, { status: to, reason, base_version: row.version });
        if (to === 'cancelled' && moved.meta?.fee_withdrawn === false) toast.info(t('outpatient.serials.refund_in_billing'));
        else toast.success(t(`outpatient.serials.moved.${to}`, { number: row.serial_no }));
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = '';
        list.reload();
    }
}
</script>

<template>
    <PageFrame :title="t('outpatient.serials.title')" :description="meta.date ? t('outpatient.serials.text', { date: formatDate(meta.date) }) : ''" width="full"
               :resource="list" :export="exported" guide="outpatient.guide.serials" :guide-settings="{ name: 'module-settings', params: { module: 'outpatient' } }"
               :empty="{ when: !rows.length, icon: Stethoscope, title: t('outpatient.serials.empty'), text: can.book ? t('outpatient.serials.empty_text') : '' }">
        <template #actions>
            <AppButton variant="ghost" :icon="RefreshCw" :loading="list.loading.value" @click="list.reload()">{{ t('outpatient.common.refresh') }}</AppButton>
            <AppButton v-if="can.book" variant="primary" :icon="Plus" @click="router.push({ name: 'outpatient-book', query: date ? { date } : {} })">{{ t('outpatient.serials.new') }}</AppButton>
        </template>
        <template #top>
            <div class="mb-4 flex flex-wrap items-end gap-3 print:hidden">
                <AppField v-slot="{ id }" :label="t('outpatient.serials.date')"><DateInput :id="id" v-model="date" /></AppField>
                <AppField v-if="(meta.doctors ?? []).length > 1" v-slot="{ id }" :label="t('outpatient.serials.doctor')">
                    <select :id="id" v-model="doctor" class="field-input">
                        <option value="">{{ t('outpatient.serials.all_doctors') }}</option>
                        <option v-for="item in meta.doctors" :key="item.id" :value="item.id">{{ item.name }}</option>
                    </select>
                </AppField>
                <AppButton v-if="date && date !== meta.today" variant="ghost" size="sm" :icon="CalendarDays" @click="date = ''">{{ t('outpatient.serials.today') }}</AppButton>
                <span class="ms-auto flex flex-wrap gap-2">
                    <AppButton v-for="chamber in chambers" :key="chamber.id" size="sm" variant="ghost" :icon="MonitorPlay"
                               @click="router.push({ name: 'outpatient-queue', params: { chamber: chamber.id } })">{{ t('outpatient.serials.screen', { chamber: chamber.name }) }}</AppButton>
                </span>
            </div>
        </template>

        <section class="card overflow-x-auto">
            <table class="w-full text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-[12px] text-muted">
                        <th class="px-4 py-2 text-start font-medium">{{ t('outpatient.serials.number') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ t('outpatient.serials.patient') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ t('outpatient.serials.doctor') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ t('outpatient.serials.status') }}</th>
                        <th class="px-4 py-2"><span class="sr-only">{{ t('outpatient.serials.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="row in rows" :key="row.id" :class="['cancelled', 'absent'].includes(row.status) && 'opacity-60'">
                        <td class="px-4 py-2.5 text-[18px] font-semibold tabular">{{ row.serial_no }}</td>
                        <td class="px-4 py-2.5">
                            <span class="font-medium">{{ row.patient?.name ?? '—' }}</span>
                            <span class="ms-1 font-mono text-[12px] text-muted" dir="ltr">{{ row.patient?.mrn }}</span>
                            <span class="block text-[12px] text-muted">
                                {{ t(`outpatient.kind.${row.kind}`) }}<template v-if="row.is_free"> · {{ t('outpatient.serials.free') }}</template>
                                <template v-if="row.cancel_reason"> · {{ row.cancel_reason }}</template>
                            </span>
                        </td>
                        <td class="px-4 py-2.5">
                            {{ row.doctor?.name }}
                            <span v-if="row.session" class="block text-[12px] text-muted" dir="ltr">{{ row.session.starts_at }}–{{ row.session.ends_at }} · {{ row.chamber?.name }}</span>
                        </td>
                        <td class="px-4 py-2.5"><AppBadge :tone="statusTones[row.status]" dot>{{ t(`outpatient.status.${row.status}`) }}</AppBadge></td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap justify-end gap-1.5">
                                <AppButton v-for="to in steps(row)" :key="to" size="sm" :variant="to === 'cancelled' || to === 'absent' ? 'ghost' : 'secondary'"
                                           :loading="busy === `${row.id}:${to}`" :disabled="!!busy" @click="step(row, to)">{{ t(`outpatient.step.${to}`) }}</AppButton>
                                <AppButton size="sm" variant="ghost" :icon="Printer" :aria-label="t('outpatient.serials.slip')" @click="router.push({ name: 'outpatient-slip', params: { id: row.id } })" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </PageFrame>
</template>
