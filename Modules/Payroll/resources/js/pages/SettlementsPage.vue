<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { DoorOpen, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';
import { runTone } from '../lib';

/** Final settlements: people who left without one first (start it), then every settlement. */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const router = useRouter();
const list = useResource(() => payroll.settlements());
const settlements = computed(() => list.data.value?.data ?? []);
const due = computed(() => list.data.value?.meta?.due ?? []);
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const starting = ref(null);

async function start(person) {
    starting.value = person.id;
    try {
        const { data } = await payroll.openSettlement(person.id);
        toast.success(t('payroll.settlements.started', { name: person.name }));
        router.push({ name: 'payroll-settlement', params: { id: data.id } });
    } catch (error) {
        toast.error(error.message);
    } finally {
        starting.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('payroll.settlements.title')" :description="t('payroll.settlements.text')" />

        <section v-if="due.length" class="card mb-5">
            <header class="border-b border-line px-5 py-3">
                <h2 class="text-[14.5px] font-semibold">{{ t('payroll.settlements.due') }}</h2>
                <p class="text-[12.5px] text-muted">{{ t('payroll.settlements.due_text') }}</p>
            </header>
            <ul class="divide-y divide-line">
                <li v-for="person in due" :key="person.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ person.name }} <span class="whitespace-nowrap font-mono text-[12px] text-muted" dir="ltr">{{ person.code }}</span></span>
                        <span class="block text-[12.5px] text-muted">{{ t('payroll.settlements.left_on', { date: day(person.left_on) }) }}</span>
                    </span>
                    <AppButton v-if="can('payroll.run')" size="sm" variant="secondary" :icon="Plus" :loading="starting === person.id" @click="start(person)">{{ t('payroll.settlements.start') }}</AppButton>
                </li>
            </ul>
        </section>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!settlements.length" :icon="DoorOpen" :title="t('payroll.settlements.empty')" :text="t('payroll.settlements.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="item in settlements" :key="item.id">
                    <RouterLink :to="{ name: 'payroll-settlement', params: { id: item.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3.5 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ item.employee_name }} <span class="whitespace-nowrap font-mono text-[12px] text-muted" dir="ltr">{{ item.employee_code }}</span></span>
                            <span class="block text-[12.5px] text-muted">{{ t('payroll.settlements.left_on', { date: day(item.left_on) }) }}</span>
                        </span>
                        <AppBadge :tone="runTone(item.status)" dot>{{ t(`payroll.status.${item.status}`) }}</AppBadge>
                        <span class="tabular w-36 text-end text-[14px] font-semibold">{{ formatMoney({ amount: item.net_minor, currency: item.currency }) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </div>
</template>
