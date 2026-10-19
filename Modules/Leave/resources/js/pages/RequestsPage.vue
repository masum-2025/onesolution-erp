<script setup>
import { computed, ref, watch } from 'vue';
import { Ban, CalendarCheck, CalendarPlus, Check, X } from 'lucide-vue-next';
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
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import AskLeaveDialog from '../components/AskLeaveDialog.vue';
import { leaveApi } from '../api';
import { daysText, statusTone } from '../lib';

/**
 * Leave of the unit's people. Waiting requests first; people who may
 * decide the next step approve (optional note) or reject (optional note),
 * never their own. HR (leave.manage) cancels and asks on someone's behalf.
 */
const org = currentOrganization();
const leave = leaveApi(org.id);

const status = ref('pending');
const list = useResource(() => leave.requests({ status: status.value === 'all' ? undefined : status.value }));
const items = computed(() => list.data.value?.data ?? []);
const typesList = useResource(() => leave.types({ active: 1 }));
const types = computed(() => typesList.data.value?.data ?? []);
const busy = ref(null);
const asking = ref(false);
watch(status, () => list.reload());

const date = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const range = (item) => (item.from_date === item.to_date ? date(item.from_date) : `${date(item.from_date)} – ${date(item.to_date)}`);

async function step(item, name) {
    const confirmed = await confirmAction({
        title: t(`leave.requests.${name}_title`, { name: item.employee_name }),
        message: t('leave.requests.what', { type: item.type_name, dates: range(item), days: daysText(item.halves) }),
        confirmLabel: t(`leave.requests.${name}`),
        reason: name === 'cancel' ? 'required' : 'optional',
        danger: name !== 'approve',
    });
    if (!confirmed) return;
    busy.value = item.id;
    try {
        await leave.decide(item.id, name, { base_version: item.version, ...(confirmed.reason ? { note: confirmed.reason } : {}) });
        toast.success(t(`leave.requests.${name}_done`));
        list.reload();
    } catch (error) {
        if (['version_conflict', 'decided'].includes(error.code)) list.reload();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('leave.requests.title')" :description="t('leave.requests.text')">
            <template #actions>
                <AppSegmented
                    v-model="status"
                    size="sm"
                    :label="t('leave.requests.title')"
                    :options="['pending', 'approved', 'rejected', 'cancelled', 'all'].map((value) => ({ value, label: value === 'all' ? t('leave.common.all') : t(`leave.status.${value}`) }))"
                />
                <AppButton v-if="can('leave.manage') && types.length" variant="primary" :icon="CalendarPlus" @click="asking = true">{{ t('leave.requests.ask_for') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!items.length" :icon="CalendarCheck" :title="t(status === 'pending' ? 'leave.requests.empty_pending' : 'leave.requests.empty')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="item in items" :key="item.id" class="grid gap-2 px-5 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                    <div class="min-w-0">
                        <p class="text-[14px] font-medium">
                            {{ item.employee_name }} <span class="font-mono text-[12px] font-normal text-muted" dir="ltr">{{ item.employee_code }}</span>
                        </p>
                        <p class="text-[13px]">
                            {{ item.type_name }} · {{ range(item) }} · {{ t('leave.common.days', { days: daysText(item.halves) }) }}
                            <template v-if="item.unpaid_halves"> · <span class="text-warn">{{ t('leave.common.unpaid_part', { days: daysText(item.unpaid_halves) }) }}</span></template>
                        </p>
                        <p class="text-[12.5px] text-muted">
                            “{{ item.reason }}”
                            <template v-if="item.status === 'pending' && item.steps_needed > 1"> · {{ t('leave.requests.steps', { done: item.steps_done, needed: item.steps_needed }) }}</template>
                            <template v-if="item.decision_note"> · {{ item.decision_note }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <AppBadge :tone="statusTone(item.status)">{{ t(`leave.status.${item.status}`) }}</AppBadge>
                        <template v-if="item.can_decide">
                            <AppButton size="sm" variant="primary" :icon="Check" :loading="busy === item.id" @click="step(item, 'approve')">{{ t('leave.requests.approve') }}</AppButton>
                            <AppButton size="sm" variant="ghost" :icon="X" :loading="busy === item.id" @click="step(item, 'reject')">{{ t('leave.requests.reject') }}</AppButton>
                        </template>
                        <span v-else-if="item.status === 'pending'" class="text-[12.5px] text-muted">{{ item.mine ? t('leave.requests.yours') : t('leave.requests.waiting_other') }}</span>
                        <AppButton
                            v-if="can('leave.manage') && ['pending', 'approved'].includes(item.status)"
                            size="icon-sm"
                            variant="ghost"
                            :icon="Ban"
                            :aria-label="t('leave.requests.cancel')"
                            :loading="busy === item.id"
                            @click="step(item, 'cancel')"
                        />
                    </div>
                </li>
            </ul>
        </section>

        <AskLeaveDialog :open="asking" :types="types" :send="leave.askFor" :people="leave.people" @close="asking = false" @sent="asking = false; list.reload()" />
    </div>
</template>
