<script setup>
import { computed, ref } from 'vue';
import { CalendarOff, CalendarPlus, UserX, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import AskLeaveDialog from '../components/AskLeaveDialog.vue';
import { leaveApi } from '../api';
import { daysText, statusTone } from '../lib';

/**
 * An employee's own leave (the login HR linked to them): what is left of
 * each kind this leave year, asking for leave, and their requests (withdraw
 * one still waiting, or approved and not started).
 */
const org = currentOrganization();
const leave = leaveApi(org.id);
const me = useResource(() => leave.me());
const data = computed(() => me.data.value?.data ?? null);
const asking = ref(false);
const busy = ref(null);

const types = computed(() => (data.value?.balances ?? []).map((row) => ({ id: row.type_id, name: row.name, code: row.code, half_day: row.half_day, paid: row.paid })));
const available = computed(() => Object.fromEntries((data.value?.balances ?? []).map((row) => [row.type_id, row.available_halves])));
const paidBalances = computed(() => (data.value?.balances ?? []).filter((row) => row.paid));
const today = new Date().toISOString().slice(0, 10);

const date = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const range = (item) => (item.from_date === item.to_date ? date(item.from_date) : `${date(item.from_date)} – ${date(item.to_date)}`);
const canWithdraw = (item) => item.status === 'pending' || (item.status === 'approved' && item.from_date > today);
const share = (row) => (row.given_halves > 0 ? Math.min(100, Math.round((row.available_halves / row.given_halves) * 100)) : 0);

async function withdraw(item) {
    const confirmed = await confirmAction({
        title: t('leave.me.withdraw_title'),
        message: t('leave.me.withdraw_text', { dates: range(item) }),
        confirmLabel: t('leave.me.withdraw'),
        reason: 'required',
        danger: true,
    });
    if (!confirmed) return;
    busy.value = item.id;
    try {
        await leave.cancelMine(item.id, { base_version: item.version, note: confirmed.reason });
        toast.success(t('leave.me.withdrawn'));
        me.reload();
    } catch (error) {
        if (error.code === 'version_conflict') me.reload();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('leave.me.title')" :description="data ? t('leave.me.year', { year: formatNumber(data.year, { useGrouping: false }) }) : t('leave.me.text')">
            <template v-if="data?.can_request && types.length" #actions>
                <AppButton variant="primary" :icon="CalendarPlus" @click="asking = true">{{ t('leave.me.ask') }}</AppButton>
            </template>
        </PageHeader>

        <section v-if="me.error.value?.code === 'not_linked'" class="card">
            <EmptyState :icon="UserX" :title="t('leave.me.not_linked')" :text="t('leave.me.not_linked_text')" />
        </section>
        <ErrorState v-else-if="me.error.value" :error="me.error.value" @retry="me.reload()" />
        <SkeletonRows v-else-if="!data" :rows="4" />
        <template v-else>
            <section v-if="paidBalances.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" :aria-label="t('leave.me.balances')">
                <article v-for="row in paidBalances" :key="row.type_id" class="card p-4">
                    <p class="text-[13px] font-medium text-fg-2">{{ row.name }}</p>
                    <p class="mt-2 flex items-baseline gap-1.5">
                        <span class="tabular text-[28px] font-semibold leading-none">{{ daysText(row.available_halves) }}</span>
                        <span class="text-[13px] text-muted">{{ t('leave.me.days_left') }}</span>
                    </p>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-subtle" role="presentation">
                        <div class="h-full rounded-full bg-brand" :style="{ width: `${share(row)}%` }" />
                    </div>
                    <p class="mt-2 text-[12px] text-muted">
                        {{ t('leave.me.of', { given: daysText(row.given_halves), taken: daysText(row.taken_halves) }) }}
                        <template v-if="row.waiting_halves"> · {{ t('leave.me.waiting', { days: daysText(row.waiting_halves) }) }}</template>
                    </p>
                </article>
            </section>
            <section v-else class="card">
                <EmptyState :icon="CalendarOff" :title="t('leave.me.no_types')" :text="t('leave.me.no_types_text')" compact />
            </section>

            <section class="card" aria-labelledby="leave-mine">
                <h2 id="leave-mine" class="border-b border-line px-5 py-3 text-[15px] font-semibold">{{ t('leave.me.requests') }}</h2>
                <EmptyState v-if="!data.requests.length" :icon="CalendarOff" :title="t('leave.me.no_requests')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in data.requests" :key="item.id" class="grid gap-2 px-5 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <div class="min-w-0">
                            <p class="text-[14px] font-medium">
                                {{ item.type_name }}
                                <span class="font-normal text-muted">· {{ range(item) }}</span>
                            </p>
                            <p class="text-[12.5px] text-muted">
                                {{ t('leave.common.days', { days: daysText(item.halves) }) }}
                                <template v-if="item.unpaid_halves"> · {{ t('leave.common.unpaid_part', { days: daysText(item.unpaid_halves) }) }}</template>
                                · “{{ item.reason }}”<template v-if="item.decision_note"> · {{ item.decision_note }}</template>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <AppBadge :tone="statusTone(item.status)">{{ t(`leave.status.${item.status}`) }}</AppBadge>
                            <AppButton v-if="canWithdraw(item)" size="sm" variant="ghost" :icon="X" :loading="busy === item.id" @click="withdraw(item)">{{ t('leave.me.withdraw') }}</AppButton>
                        </div>
                    </li>
                </ul>
            </section>
        </template>

        <AskLeaveDialog :open="asking" :types="types" :available="available" :send="leave.askMine" @close="asking = false" @sent="asking = false; me.reload()" />
    </div>
</template>
