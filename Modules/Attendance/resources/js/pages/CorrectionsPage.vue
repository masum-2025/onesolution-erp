<script setup>
import { computed, ref, watch } from 'vue';
import { Check, ClipboardCheck, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';

/**
 * Corrections of the unit's people: forgotten or wrong punches with the
 * times asked and why. People who decide approve (the times become punches)
 * or reject; never their own or one about their own day.
 */
const org = currentOrganization();
const attendance = attendanceApi(org.id);

const status = ref('pending');
const list = useResource(() => attendance.corrections(status.value));
const items = computed(() => list.data.value?.data ?? []);
const busy = ref(null);
watch(status, () => list.reload());

const time = (iso) => (iso ? formatDate(iso, { dateStyle: 'short', timeStyle: 'short' }) : '—');

async function decide(item, step) {
    const confirmed = await confirmAction({
        title: t(`attendance.corrections.${step}_title`, { name: item.employee_name }),
        message: step === 'approve' ? t('attendance.corrections.approve_text') : '',
        confirmLabel: t(`attendance.corrections.${step}`),
        reason: step === 'reject' ? 'optional' : 'none',
        danger: step === 'reject',
    });
    if (!confirmed) return;
    busy.value = item.id;
    try {
        await attendance.decide(item.id, step, { base_version: item.version, ...(step === 'reject' && confirmed.reason ? { note: confirmed.reason } : {}) });
        toast.success(t(`attendance.corrections.${step}_done`));
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict' || error.code === 'correction_decided') list.reload();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('attendance.corrections.title')" :description="t('attendance.corrections.text')">
            <template #actions>
                <AppSegmented
                    v-model="status"
                    size="sm"
                    :label="t('attendance.corrections.title')"
                    :options="['pending', 'approved', 'rejected'].map((value) => ({ value, label: t(`attendance.correction.status.${value}`) }))"
                />
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!items.length" :icon="ClipboardCheck" :title="t(`attendance.corrections.empty_${status}`)" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="item in items" :key="item.id" class="grid gap-2 px-5 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                    <div class="min-w-0">
                        <p class="text-[14px] font-medium">
                            {{ item.employee_name }}
                            <span class="font-normal text-muted">· {{ formatDate(`${item.work_date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) }}</span>
                        </p>
                        <p class="text-[13px]">{{ t('attendance.corrections.times', { in: time(item.in_at), out: time(item.out_at) }) }}</p>
                        <p class="text-[12.5px] text-muted">“{{ item.reason }}”<template v-if="item.decision_note"> · {{ item.decision_note }}</template></p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <AppBadge v-if="item.status !== 'pending'" :tone="item.status === 'approved' ? 'ok' : 'bad'">{{ t(`attendance.correction.status.${item.status}`) }}</AppBadge>
                        <template v-else-if="item.can_decide">
                            <AppButton size="sm" variant="primary" :icon="Check" :loading="busy === item.id" @click="decide(item, 'approve')">{{ t('attendance.corrections.approve') }}</AppButton>
                            <AppButton size="sm" variant="ghost" :icon="X" :loading="busy === item.id" @click="decide(item, 'reject')">{{ t('attendance.corrections.reject') }}</AppButton>
                        </template>
                        <span v-else class="text-[12.5px] text-muted">{{ item.mine ? t('attendance.corrections.yours') : t('attendance.corrections.waiting') }}</span>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
