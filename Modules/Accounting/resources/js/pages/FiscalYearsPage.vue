<script setup>
import { computed, ref } from 'vue';
import { ArrowLeft, CalendarCheck, CalendarPlus, CalendarRange, Check, Lock, LockOpen, Undo2, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import BooksGate from '../components/BooksGate.vue';

/**
 * Fiscal years and their monthly periods. Closing a period stops entries
 * dated in it; reopening asks why (audit log). Closing a year moves its
 * income and expenses into retained earnings; reopening it needs a reason
 * and, by the company's rule, a second person.
 */
const org = currentOrganization();
const books = accountingApi(org.id);

const list = useResource(() => books.fiscalYears());
const years = computed(() => list.data.value?.data ?? []);
const busy = ref(null);
// Months of closed years are folded away until someone opens them.
const unfolded = ref(new Set());

const periodName = (period) => `${formatDate(period.starts_on)} – ${formatDate(period.ends_on)}`;
const closedMonths = (year) => year.periods.filter((period) => period.status === 'closed').length;

function toggle(year) {
    const next = new Set(unfolded.value);
    if (next.has(year.id)) next.delete(year.id);
    else next.add(year.id);
    unfolded.value = next;
}

async function run(key, action, done) {
    busy.value = key;
    try {
        await action();
        toast.success(done);
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') list.reload();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function addYear() {
    busy.value = 'year';
    try {
        const { data } = await books.addFiscalYear();
        toast.success(t('accounting.fiscal.added', { name: data.name }));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function step(period, name) {
    const confirmed = await confirmAction({
        title: t(name === 'close' ? 'accounting.fiscal.close_title' : 'accounting.fiscal.reopen_title', { period: periodName(period) }),
        message: t(name === 'close' ? 'accounting.fiscal.close_text' : 'accounting.fiscal.reopen_text'),
        confirmLabel: t(`accounting.fiscal.${name}`),
        reason: name === 'reopen' ? 'required' : 'none',
        danger: name === 'reopen',
    });
    if (!confirmed) return;

    await run(period.id, () => books.periodStep(period.id, name, name === 'reopen' ? { reason: confirmed.reason } : {}),
        t(name === 'close' ? 'accounting.fiscal.closed_done' : 'accounting.fiscal.reopened_done'));
}

async function closeYear(year) {
    const confirmed = await confirmAction({
        title: t('accounting.year_end.close_title', { name: year.name }),
        message: t('accounting.year_end.close_text'),
        confirmLabel: t('accounting.year_end.close'),
    });
    if (!confirmed) return;

    await run(year.id, () => books.yearStep(year.id, 'close', { base_version: year.version }), t('accounting.year_end.closed_done', { name: year.name }));
}

async function askReopen(year) {
    const confirmed = await confirmAction({
        title: t('accounting.year_end.reopen_title', { name: year.name }),
        message: t('accounting.year_end.reopen_text'),
        confirmLabel: t('accounting.year_end.ask_reopen'),
        reason: 'required',
        danger: true,
    });
    if (!confirmed) return;

    busy.value = year.id;
    try {
        const { data } = await books.yearStep(year.id, 'reopen', { reason: confirmed.reason });
        // Without the second-person rule the year opens at once.
        toast.success(t(data.status === 'open' ? 'accounting.year_end.reopened_done' : 'accounting.year_end.asked_done', { name: year.name }));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function decide(year, name) {
    const mine = year.reopen_request.mine;
    const confirmed = await confirmAction({
        title: t(name === 'approve' ? 'accounting.year_end.approve_title' : (mine ? 'accounting.year_end.take_back_title' : 'accounting.year_end.reject_title'), { name: year.name }),
        message: name === 'approve' ? t('accounting.year_end.approve_text') : '',
        confirmLabel: t(name === 'approve' ? 'accounting.year_end.approve' : (mine ? 'accounting.year_end.take_back' : 'accounting.year_end.reject')),
        reason: name === 'reject' && !mine ? 'optional' : 'none',
        danger: name === 'approve',
    });
    if (!confirmed) return;

    await run(year.id, () => books.reopenStep(year.reopen_request.id, name, name === 'reject' && confirmed.reason ? { note: confirmed.reason } : {}),
        t(name === 'approve' ? 'accounting.year_end.reopened_done' : 'accounting.year_end.rejected_done', { name: year.name }));
}
</script>

<template>
    <div>
        <PageHeader :title="t('accounting.fiscal.title')" :description="t('accounting.fiscal.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'accounting' }" :icon="ArrowLeft">{{ t('accounting.journal.back') }}</AppButton>
                <AppButton v-if="can('accounting.manage')" variant="primary" :icon="CalendarPlus" :loading="busy === 'year'" @click="addYear">{{ t('accounting.fiscal.add') }}</AppButton>
            </template>
        </PageHeader>

        <BooksGate>
            <section v-if="list.loading.value && !list.data.value" class="card"><SkeletonRows :rows="6" /></section>
            <section v-else-if="list.error.value" class="card"><ErrorState compact :error="list.error.value" @retry="list.reload()" /></section>
            <section v-else-if="!years.length" class="card"><EmptyState :icon="CalendarRange" :title="t('accounting.fiscal.empty')" compact /></section>

            <div v-else class="grid gap-5">
                <section v-for="year in years" :key="year.id" class="card">
                    <header class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <h2 class="flex items-center gap-2 text-[15px] font-semibold text-fg">
                                {{ year.name }}
                                <AppBadge :tone="year.status === 'closed' ? 'neutral' : 'ok'" dot>{{ t(`accounting.year_end.status.${year.status}`) }}</AppBadge>
                            </h2>
                            <p class="text-[12.5px] text-muted">
                                {{ formatDate(year.starts_on) }} – {{ formatDate(year.ends_on) }}
                                <template v-if="year.closed_at"> · {{ t('accounting.year_end.closed_on', { date: formatDate(year.closed_at) }) }}</template>
                            </p>
                        </div>
                        <AppButton v-if="year.closing_journal_id" size="sm" variant="ghost" :to="{ name: 'accounting-journal', params: { id: year.closing_journal_id } }">{{ t('accounting.year_end.closing_entry') }}</AppButton>
                        <AppButton v-if="year.can.close" size="sm" variant="secondary" :icon="CalendarCheck" :loading="busy === year.id" @click="closeYear(year)">{{ t('accounting.year_end.close') }}</AppButton>
                        <AppButton v-if="year.can.reopen" size="sm" variant="ghost" :icon="LockOpen" :loading="busy === year.id" @click="askReopen(year)">{{ t('accounting.year_end.ask_reopen') }}</AppButton>
                    </header>

                    <!-- Someone asked to reopen: another person decides. -->
                    <div v-if="year.reopen_request" class="flex flex-wrap items-center gap-3 border-b border-line bg-warn-soft px-5 py-3 text-[13px]" role="status">
                        <p class="min-w-0 flex-1">
                            <span class="font-medium">{{ year.reopen_request.mine ? t('accounting.year_end.you_asked') : t('accounting.year_end.someone_asked', { name: year.reopen_request.requested_by_name ?? '—' }) }}</span>
                            <span class="text-muted"> · “{{ year.reopen_request.reason }}”</span>
                            <span v-if="year.reopen_request.mine" class="block text-[12px] text-muted">{{ t('accounting.year_end.waiting_other') }}</span>
                        </p>
                        <AppButton v-if="year.can.approve_reopen" size="sm" variant="primary" :icon="Check" :loading="busy === year.id" @click="decide(year, 'approve')">{{ t('accounting.year_end.approve') }}</AppButton>
                        <AppButton v-if="year.can.reject_reopen" size="sm" variant="ghost" :icon="year.reopen_request.mine ? Undo2 : X" :loading="busy === year.id" @click="decide(year, 'reject')">
                            {{ t(year.reopen_request.mine ? 'accounting.year_end.take_back' : 'accounting.year_end.reject') }}
                        </AppButton>
                    </div>

                    <button
                        v-if="year.status === 'closed'"
                        type="button"
                        class="flex w-full items-center justify-between px-5 py-2.5 text-start text-[13px] text-muted hover:bg-surface-2"
                        :aria-expanded="unfolded.has(year.id)"
                        @click="toggle(year)"
                    >
                        <span>{{ t('accounting.year_end.months_closed', { count: closedMonths(year) }) }}</span>
                        <span>{{ unfolded.has(year.id) ? t('accounting.year_end.hide_months') : t('accounting.year_end.show_months') }}</span>
                    </button>
                    <ul v-if="year.status !== 'closed' || unfolded.has(year.id)" class="divide-y divide-line">
                        <li v-for="period in year.periods" :key="period.id" class="flex items-center gap-3 px-5 py-2.5">
                            <span class="min-w-0 flex-1 text-[13.5px]">{{ periodName(period) }}</span>
                            <AppBadge :tone="period.status === 'open' ? 'ok' : 'neutral'" dot>{{ t(`accounting.fiscal.${period.status}`) }}</AppBadge>
                            <!-- A closed year's months open again only after the year is reopened. -->
                            <AppButton
                                v-if="can('accounting.close') && year.status !== 'closed'"
                                size="sm"
                                variant="ghost"
                                :icon="period.status === 'open' ? Lock : LockOpen"
                                :loading="busy === period.id"
                                @click="step(period, period.status === 'open' ? 'close' : 'reopen')"
                            >
                                {{ t(period.status === 'open' ? 'accounting.fiscal.close' : 'accounting.fiscal.reopen') }}
                            </AppButton>
                        </li>
                    </ul>
                </section>
            </div>
        </BooksGate>
    </div>
</template>
