<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Banknote, CalendarPlus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';
import { nextPeriod, runTone } from '../lib';

/** The company's payroll month by month: open the next one, open any to work on it. */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const router = useRouter();
const list = useResource(() => payroll.runs());
const runs = computed(() => list.data.value?.data ?? []);

const opening = ref(false);
const period = ref('');
const saving = ref(false);
const error = ref(null);

function open() {
    period.value = nextPeriod(runs.value);
    error.value = null;
    opening.value = true;
}

async function save() {
    saving.value = true;
    error.value = null;
    try {
        const { data } = await payroll.openRun(period.value);
        toast.success(t('payroll.runs.opened', { month: monthName(data.period) }));
        router.push({ name: 'payroll-run', params: { id: data.id } });
    } catch (failure) {
        error.value = failure.errors?.period?.[0] ?? failure.message;
    } finally {
        saving.value = false;
    }
}

const monthName = (value) => formatDate(`${value}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' });
</script>

<template>
    <div>
        <PageHeader :title="t('payroll.runs.title')" :description="t('payroll.runs.text')">
            <template #actions>
                <AppButton v-if="can('payroll.run')" variant="primary" :icon="CalendarPlus" @click="open">{{ t('payroll.runs.open') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!runs.length" :icon="Banknote" :title="t('payroll.runs.empty')" :text="t('payroll.runs.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="run in runs" :key="run.id">
                    <RouterLink :to="{ name: 'payroll-run', params: { id: run.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3.5 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14.5px] font-semibold">{{ monthName(run.period) }}</span>
                            <span class="block text-[12.5px] text-muted">{{ t('payroll.runs.people', { count: formatNumber(run.employees) }) }}</span>
                        </span>
                        <AppBadge :tone="runTone(run.status)" dot>{{ t(`payroll.status.${run.status}`) }}</AppBadge>
                        <span class="tabular w-36 text-end text-[14px] font-semibold">{{ formatMoney({ amount: run.net_minor, currency: run.currency }) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>

        <AppDialog :open="opening" :title="t('payroll.runs.open')" :icon="CalendarPlus" @close="opening = false">
            <form id="payroll-open" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('payroll.runs.month')" :error="error">
                    <input :id="id" v-model="period" type="month" class="field-input" required />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="opening = false">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-open" :loading="saving" :disabled="!period">{{ t('payroll.runs.open') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
