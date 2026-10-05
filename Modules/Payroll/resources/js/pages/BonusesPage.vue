<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Gift, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';
import { percentToBp, runTone } from '../lib';

/** Festival bonuses, newest first; a new one for a day at a share of the basic (empty = the company's rule). */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const router = useRouter();
const list = useResource(() => payroll.bonuses());
const bonuses = computed(() => list.data.value?.data ?? []);

const opening = ref(false);
const form = reactive({ title: { en: '', bn: '' }, bonus_on: '', percent: '' });
const errors = ref({});
const saving = ref(false);

function open() {
    Object.assign(form, { title: { en: '', bn: '' }, bonus_on: new Date().toISOString().slice(0, 10), percent: '' });
    errors.value = {};
    opening.value = true;
}

async function save() {
    const rate = form.percent.trim() === '' ? null : percentToBp(form.percent);
    if (form.percent.trim() !== '' && rate === null) {
        errors.value = { rate_bp: [t('payroll.structures.bad_value')] };
        return;
    }
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await payroll.openBonus({ title: Object.fromEntries(Object.entries(form.title).filter(([, value]) => value?.trim())), bonus_on: form.bonus_on, ...(rate === null ? {} : { rate_bp: rate }) });
        toast.success(t('payroll.bonuses.opened'));
        router.push({ name: 'payroll-bonus', params: { id: data.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('payroll.bonuses.title')" :description="t('payroll.bonuses.text')">
            <template #actions>
                <AppButton v-if="can('payroll.run')" variant="primary" :icon="Plus" @click="open">{{ t('payroll.bonuses.open') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!bonuses.length" :icon="Gift" :title="t('payroll.bonuses.empty')" :text="t('payroll.bonuses.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="bonus in bonuses" :key="bonus.id">
                    <RouterLink :to="{ name: 'payroll-bonus', params: { id: bonus.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3.5 hover:bg-surface-2">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><Gift class="size-5" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14.5px] font-semibold">{{ bonus.title }}</span>
                            <span class="block text-[12.5px] text-muted">{{ formatDate(`${bonus.bonus_on}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) }} · {{ t('payroll.runs.people', { count: formatNumber(bonus.employees) }) }}</span>
                        </span>
                        <AppBadge :tone="runTone(bonus.status)" dot>{{ t(`payroll.status.${bonus.status}`) }}</AppBadge>
                        <span class="tabular w-36 text-end text-[14px] font-semibold">{{ formatMoney({ amount: bonus.net_minor, currency: bonus.currency }) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>

        <AppDialog :open="opening" :title="t('payroll.bonuses.open')" :icon="Gift" @close="opening = false">
            <form id="payroll-bonus" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <div class="sm:col-span-2">
                    <TranslatedFields v-model="form.title" :label="t('payroll.bonuses.name')" :errors="errors" error-prefix="title" required :maxlength="80" />
                </div>
                <AppField v-slot="{ id }" :label="t('payroll.bonuses.bonus_on')" :hint="t('payroll.bonuses.bonus_on_hint')" :error="errors.bonus_on?.[0]">
                    <input :id="id" v-model="form.bonus_on" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.bonuses.percent')" :hint="t('payroll.bonuses.percent_hint')" :error="errors.rate_bp?.[0]" optional>
                    <input :id="id" v-model="form.percent" inputmode="decimal" class="field-input tabular text-end" dir="ltr" placeholder="%" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="opening = false">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-bonus" :loading="saving" :disabled="!form.title.en?.trim() || !form.bonus_on">{{ t('payroll.bonuses.open') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
