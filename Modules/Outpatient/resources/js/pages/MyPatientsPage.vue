<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { useRouter } from 'vue-router';
import { FilePenLine, RefreshCw, Stethoscope } from 'lucide-vue-next';
import { AppBadge, AppButton, PageFrame, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { opdApi, statusTones } from '../api';

/**
 * A doctor's own patients today (the doctor records signed in as them): call
 * the next one in, finish, open the patient's record. Fresh every 30 seconds.
 * Without a doctor record linked to this sign-in the page says who links it.
 */
const opd = opdApi(currentOrganization().id);
const router = useRouter();
const list = useResource(() => opd.mine());
const timer = setInterval(() => list.reload(), 30000);
onBeforeUnmount(() => clearInterval(timer));
const rows = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? {});
const waiting = computed(() => rows.value.filter((row) => row.status === 'arrived').length);
const busy = ref('');

async function step(row, to) {
    busy.value = row.id;
    try {
        await opd.move(row.id, { status: to, base_version: row.version });
        toast.success(t(`outpatient.serials.moved.${to}`, { number: row.serial_no }));
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = '';
        list.reload();
    }
}
</script>

<template>
    <PageFrame :title="t('outpatient.mine.title')" :description="meta.date ? t('outpatient.mine.text', { date: formatDate(meta.date), count: waiting }) : ''" width="lg"
               :resource="list" guide="outpatient.guide.mine"
               :empty="{ when: !rows.length, icon: Stethoscope, title: meta.linked === false ? t('outpatient.mine.not_linked') : t('outpatient.mine.empty'), text: meta.linked === false ? t('outpatient.mine.not_linked_text') : '' }">
        <template #actions>
            <AppButton variant="ghost" :icon="RefreshCw" :loading="list.loading.value" @click="list.reload()">{{ t('outpatient.common.refresh') }}</AppButton>
        </template>
        <ul class="card divide-y divide-line">
            <li v-for="row in rows" :key="row.id" class="flex flex-wrap items-center gap-3 px-4 py-3" :class="row.status === 'in' && 'bg-accent/5'">
                <span class="w-10 text-[20px] font-semibold tabular">{{ row.serial_no }}</span>
                <span class="min-w-0 flex-1">
                    <button v-if="row.patient" type="button" class="text-start text-[14px] font-medium hover:underline" @click="router.push({ name: 'patients-patient', params: { id: row.patient.id } })">{{ row.patient.name }}</button>
                    <span class="block text-[12.5px] text-muted">
                        <span class="font-mono" dir="ltr">{{ row.patient?.mrn }}</span> · {{ t('outpatient.common.years', { count: row.patient?.age ?? '—' }) }} · {{ t(`outpatient.kind.${row.kind}`) }}
                    </span>
                </span>
                <AppBadge :tone="statusTones[row.status]" dot>{{ t(`outpatient.status.${row.status}`) }}</AppBadge>
                <AppButton v-if="row.next.includes('in')" size="sm" variant="primary" :loading="busy === row.id" :disabled="!!busy" @click="step(row, 'in')">{{ t('outpatient.step.in') }}</AppButton>
                <AppButton v-if="['in', 'done'].includes(row.status)" size="sm" :variant="row.status === 'in' ? 'primary' : 'ghost'" :icon="FilePenLine"
                           @click="router.push({ name: 'outpatient-consult', params: { id: row.id } })">{{ row.status === 'done' ? t('outpatient.mine.open') : t('outpatient.mine.write') }}</AppButton>
            </li>
        </ul>
    </PageFrame>
</template>
