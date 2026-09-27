<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Building2, Check, FileSignature, Package, Users } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { cleanTexts, textsFor } from '@/lib/texts';
import AppSegmented from '@/components/AppSegmented.vue';
import ErrorState from '@/components/ErrorState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { loadSectors } from '@/lib/packaging';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { confirmAction } from '@/lib/dialogs';
import { currentOrganization, loadMe } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * A personal workspace becomes a company (Phase 5C-3). Same workspace, all
 * data kept; the page says plainly what changes and when billing starts.
 */
const router = useRouter();
const org = currentOrganization();
const preview = useResource(() => api(`/api/organizations/${org.id}/upgrade`).then((response) => response.data));
const sectors = useResource(() => loadSectors());
const data = computed(() => preview.data.value);

const form = reactive({ names: textsFor(), sector: null, plan: null, period: 'monthly' });
const errors = reactive({});
const saving = ref(false);

watch(data, (value) => {
    if (!value) return;
    form.plan ??= value.plans.some((plan) => plan.key === value.suggested_plan) ? value.suggested_plan : value.plans[0]?.key ?? null;
    form.sector ??= value.sector_key;
    form.period = value.period ?? 'monthly';
});

const plan = computed(() => data.value?.plans.find((candidate) => candidate.key === form.plan) ?? null);
const startsNow = computed(() => data.value && data.value.billing_starts_on <= new Date().toISOString().slice(0, 10));

async function submit() {
    for (const key of Object.keys(errors)) delete errors[key];
    if ((form.names.en ?? '').trim().length < 2) errors['name.en'] = t('billing.upgrade.name_required');
    if (!form.plan) errors.plan = t('billing.upgrade.plan_required');
    if (Object.keys(errors).length) return;

    const confirmed = await confirmAction({
        title: t('billing.upgrade.confirm_title', { name: form.names.en.trim() }),
        message: t('billing.upgrade.confirm_text'),
        confirmLabel: t('billing.upgrade.submit'),
    });
    if (!confirmed) return;

    saving.value = true;
    try {
        const response = await api(`/api/organizations/${org.id}/upgrade`, {
            method: 'POST',
            body: {
                name: cleanTexts(form.names),
                sector_key: form.sector,
                plan_key: form.plan,
                period: form.period,
            },
        });
        toast.success(response.message);
        await loadMe();
        await router.push('/billing');
    } catch (error) {
        if (error.field?.('name.en')) errors['name.en'] = error.field('name.en');
        else toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <PageHeader :title="t('billing.upgrade.title')" :description="t('billing.upgrade.text')" />

        <SkeletonRows v-if="preview.loading.value && !data" :rows="4" />
        <ErrorState v-else-if="preview.error.value" :error="preview.error.value" @retry="preview.reload()" />

        <form v-else-if="data" class="space-y-6" novalidate @submit.prevent="submit">
            <!-- What changes -->
            <section class="card p-5">
                <h2 class="text-[15px] font-semibold text-fg">{{ t('billing.upgrade.what_changes') }}</h2>
                <ul class="mt-3 space-y-2.5 text-[13.5px] text-fg-2">
                    <li class="flex gap-2.5"><Check class="mt-0.5 size-4 shrink-0 text-ok" aria-hidden="true" />{{ t('billing.upgrade.keeps') }}</li>
                    <li class="flex gap-2.5"><Users class="mt-0.5 size-4 shrink-0 text-brand-text" aria-hidden="true" />{{ t('billing.upgrade.team') }}</li>
                    <li class="flex gap-2.5"><Package class="mt-0.5 size-4 shrink-0 text-brand-text" aria-hidden="true" />{{ t('billing.upgrade.plan') }}</li>
                    <li class="flex gap-2.5"><FileSignature class="mt-0.5 size-4 shrink-0 text-brand-text" aria-hidden="true" />{{ t('billing.upgrade.dpa') }}</li>
                </ul>
            </section>

            <!-- The company -->
            <section class="card space-y-4 p-5">
                <h2 class="flex items-center gap-2 text-[15px] font-semibold text-fg"><Building2 class="size-4 text-muted" aria-hidden="true" />{{ t('billing.upgrade.company') }}</h2>
                <TranslatedFields v-model="form.names" :label="t('billing.upgrade.name')" required :errors="errors" />
                <AppField :label="t('billing.upgrade.sector')" :hint="t('billing.upgrade.sector_hint')">
                    <template #default="{ id }">
                        <select :id="id" v-model="form.sector" class="field-input">
                            <option :value="null">{{ t('billing.upgrade.no_sector') }}</option>
                            <option v-for="sector in sectors.data.value ?? []" :key="sector.key" :value="sector.key">{{ sector.name }}</option>
                        </select>
                    </template>
                </AppField>
            </section>

            <!-- The plan -->
            <section>
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-[15px] font-semibold text-fg">{{ t('billing.upgrade.choose_plan') }}</h2>
                    <AppSegmented
                        v-model="form.period"
                        size="sm"
                        :label="t('billing.self.period_label')"
                        :options="[
                            { value: 'monthly', label: t('billing.periods.monthly') },
                            { value: 'yearly', label: t('billing.periods.yearly') },
                        ]"
                    />
                </div>
                <p v-if="errors.plan" class="mb-2 text-[12.5px] text-bad">{{ errors.plan }}</p>
                <div class="grid gap-3 sm:grid-cols-3" role="radiogroup" :aria-label="t('billing.upgrade.choose_plan')">
                    <button
                        v-for="option in data.plans"
                        :key="option.key"
                        type="button"
                        role="radio"
                        :aria-checked="form.plan === option.key"
                        :disabled="option.prices[form.period] === undefined"
                        class="card flex flex-col items-start p-4 text-start transition disabled:opacity-50"
                        :class="form.plan === option.key ? 'ring-2 ring-brand' : 'hover:bg-subtle/60'"
                        @click="form.plan = option.key"
                    >
                        <span class="text-[14.5px] font-semibold text-fg">{{ option.name }}</span>
                        <span class="mt-1 flex-1 text-[12.5px] leading-relaxed text-muted">{{ option.description }}</span>
                        <span v-if="option.prices[form.period] !== undefined" class="tabular mt-3 text-[16px] font-semibold text-fg">
                            {{ formatMoney({ amount: option.prices[form.period], currency: data.currency }) }}
                            <span class="text-[12px] font-normal text-muted">{{ t(`billing.per.${form.period}`) }}</span>
                        </span>
                    </button>
                </div>
            </section>

            <!-- When billing starts -->
            <p class="rounded-xl border border-line bg-subtle px-4 py-3 text-[13px] leading-relaxed text-fg-2" role="note">
                <template v-if="startsNow">{{ t('billing.upgrade.bill_now', { days: formatNumber(data.payment_terms_days), plan: plan?.name ?? '' }) }}</template>
                <template v-else>{{ t('billing.upgrade.bill_later', { date: formatDate(data.billing_starts_on), plan: plan?.name ?? '' }) }}</template>
            </p>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <AppButton to="/billing">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" variant="primary" :loading="saving">{{ t('billing.upgrade.submit') }}</AppButton>
            </div>
        </form>
    </div>
</template>
