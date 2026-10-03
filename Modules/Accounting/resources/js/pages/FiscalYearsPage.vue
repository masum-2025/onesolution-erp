<script setup>
import { computed, ref } from 'vue';
import { ArrowLeft, CalendarPlus, CalendarRange, Lock, LockOpen } from 'lucide-vue-next';
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
 * dated in it; reopening asks why (audit log). Years follow each other.
 */
const org = currentOrganization();
const books = accountingApi(org.id);

const list = useResource(() => books.fiscalYears());
const years = computed(() => list.data.value?.data ?? []);
const busy = ref(null);

const periodName = (period) => `${formatDate(period.starts_on)} – ${formatDate(period.ends_on)}`;

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

    busy.value = period.id;
    try {
        await books.periodStep(period.id, name, name === 'reopen' ? { reason: confirmed.reason } : {});
        toast.success(t(name === 'close' ? 'accounting.fiscal.closed_done' : 'accounting.fiscal.reopened_done'));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
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
                    <header class="flex items-center justify-between border-b border-line px-5 py-3">
                        <h2 class="text-[15px] font-semibold text-fg">{{ year.name }}</h2>
                        <span class="text-[12.5px] text-muted">{{ formatDate(year.starts_on) }} – {{ formatDate(year.ends_on) }}</span>
                    </header>
                    <ul class="divide-y divide-line">
                        <li v-for="period in year.periods" :key="period.id" class="flex items-center gap-3 px-5 py-2.5">
                            <span class="min-w-0 flex-1 text-[13.5px]">{{ periodName(period) }}</span>
                            <AppBadge :tone="period.status === 'open' ? 'ok' : 'neutral'" dot>{{ t(`accounting.fiscal.${period.status}`) }}</AppBadge>
                            <AppButton
                                v-if="can('accounting.close')"
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
