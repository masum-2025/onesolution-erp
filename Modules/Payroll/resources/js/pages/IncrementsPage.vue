<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Plus, TrendingUp } from 'lucide-vue-next';
import { DateInput, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { textIn } from '@/lib/texts';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';
import { incrementTone } from '../lib';

/** Salary increment rounds, newest first; a new one from a day, after an appraisal cycle or with rates by hand. */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const router = useRouter();
const list = useResource(() => payroll.increments());
const rounds = computed(() => list.data.value?.data ?? []);
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });

// Export and print (payroll.export): the rounds.
const exported = computed(() => ({
    module: 'payroll',
    page: 'payroll.increments',
    columns: [
        { key: 'title', label: t('payroll.increments.name') },
        { key: 'effective_from', label: t('payroll.increments.effective_from'), type: 'date' },
        { key: 'employees', label: t('payroll.export.people'), type: 'number' },
        { key: 'current_minor', label: t('payroll.increments.columns.now'), type: 'money' },
        { key: 'new_minor', label: t('payroll.increments.columns.new'), type: 'money' },
        { key: 'status', label: t('payroll.export.status'), value: (round) => t(`payroll.increments.statuses.${round.status}`) },
    ],
    rows: rounds.value,
}));

const opening = ref(false);
const sources = useResource(() => payroll.incrementSources(), { immediate: false });
const cycles = computed(() => sources.data.value?.data?.cycles ?? []);
const form = reactive({ title: { en: '', bn: '' }, effective_from: '', cycle_id: '' });
const errors = ref({});
const saving = ref(false);

/** The first day of next month: the usual start of an increment. */
function nextMonth() {
    const now = new Date();
    const first = new Date(Date.UTC(now.getFullYear(), now.getMonth() + 1, 1));
    return first.toISOString().slice(0, 10);
}

async function open() {
    Object.assign(form, { title: { en: '', bn: '' }, effective_from: nextMonth(), cycle_id: '' });
    errors.value = {};
    opening.value = true;
    await sources.reload();
    form.cycle_id = cycles.value[0]?.id ?? '';
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await payroll.openIncrement({
            title: Object.fromEntries(Object.entries(form.title).filter(([, value]) => value?.trim())),
            effective_from: form.effective_from,
            cycle_id: form.cycle_id || null,
        });
        toast.success(t('payroll.increments.opened'));
        router.push({ name: 'payroll-increment', params: { id: data.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <PageFrame :title="t('payroll.increments.title')" :description="t('payroll.increments.text')" width="full" :export="exported">
        <template #actions>
            <AppButton v-if="can('payroll.run')" variant="primary" :icon="Plus" @click="open">{{ t('payroll.increments.open') }}</AppButton>
        </template>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!rounds.length" :icon="TrendingUp" :title="t('payroll.increments.empty')" :text="t('payroll.increments.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="round in rounds" :key="round.id">
                    <RouterLink :to="{ name: 'payroll-increment', params: { id: round.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3.5 hover:bg-surface-2">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><TrendingUp class="size-5" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14.5px] font-semibold">{{ round.title }}</span>
                            <span class="block text-[12.5px] text-muted">{{ day(round.effective_from) }} · {{ t('payroll.increments.people', { count: formatNumber(round.employees) }) }}</span>
                        </span>
                        <AppBadge :tone="incrementTone(round.status)" dot>{{ t(`payroll.increments.statuses.${round.status}`) }}</AppBadge>
                        <span class="tabular w-36 text-end text-[14px] font-semibold">+{{ formatMoney({ amount: round.new_minor - round.current_minor, currency: round.currency }) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>

        <AppDialog :open="opening" :title="t('payroll.increments.open')" :icon="TrendingUp" @close="opening = false">
            <form id="payroll-increment" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <div class="sm:col-span-2">
                    <TranslatedFields v-model="form.title" :label="t('payroll.increments.name')" :errors="errors" error-prefix="title" required :maxlength="80" />
                </div>
                <AppField v-slot="{ id }" :label="t('payroll.increments.effective_from')" :hint="t('payroll.increments.effective_from_hint')" :error="errors.effective_from?.[0]">
                    <DateInput :id="id" v-model="form.effective_from" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.increments.cycle')" :hint="t('payroll.increments.cycle_hint')" :error="errors.cycle_id?.[0]">
                    <select :id="id" v-model="form.cycle_id" class="field-input">
                        <option v-for="cycle in cycles" :key="cycle.id" :value="cycle.id">{{ textIn(cycle.name) }}</option>
                        <option value="">{{ t('payroll.increments.no_cycle') }}</option>
                    </select>
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="opening = false">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-increment" :loading="saving" :disabled="!form.title.en?.trim() || !form.effective_from">{{ t('payroll.increments.open') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
