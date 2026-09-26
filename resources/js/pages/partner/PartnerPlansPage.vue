<script setup>
import { computed, ref } from 'vue';
import { Archive, Info, Package, Pencil, Plus, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import PartnerPlanDialog from './PartnerPlanDialog.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { monthlyMargin } from '@/lib/billing';
import { confirmAction } from '@/lib/dialogs';
import { formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Partner console: the partner's own plans, built on ours. Shows what each
 * client costs the partner (wholesale) or what it earns (revenue share), so
 * the margin is visible while pricing.
 */
const plans = useResource(() => api('/api/partner/plans'));
const list = computed(() => plans.data.value?.data ?? []);
const canEdit = computed(() => plans.data.value?.can_edit === true);
const mode = computed(() => plans.data.value?.billing_mode);
const editing = ref(undefined); // undefined = closed, null = new, object = edit

async function archive(plan) {
    const answer = await confirmAction({
        title: t('billing.plans.archive_title', { name: plan.name }),
        message: t('billing.plans.archive_text', { count: plan.clients }),
        reason: 'required',
        danger: true,
        confirmLabel: t('billing.plans.archive'),
    });
    if (!answer) return;
    try {
        const response = await api(`/api/partner/plans/${plan.id}/archive`, { method: 'POST', body: { reason: answer.reason } });
        toast.success(response.message);
        plans.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

function saved() {
    editing.value = undefined;
    plans.reload();
}

const money = (amount, currency) => formatMoney({ amount, currency });
</script>

<template>
    <div>
        <PageHeader :title="t('billing.plans.title')" :description="t('billing.plans.text')">
            <template #actions>
                <AppButton v-if="canEdit" variant="primary" :icon="Plus" @click="editing = null">{{ t('billing.plans.new') }}</AppButton>
            </template>
        </PageHeader>

        <p v-if="mode" class="mb-5 flex items-start gap-2.5 rounded-xl border border-line bg-surface p-3.5 text-[13px] text-fg-2">
            <Info class="mt-0.5 size-4 shrink-0 text-brand-text" aria-hidden="true" />
            <span v-if="mode === 'revenue_share'">{{ t('billing.plans.mode_revenue_share', { share: formatNumber((list[0]?.cost?.share_bp ?? plans.data.value?.base_plans?.[0]?.cost?.share_bp ?? 0) / 100) }) }}</span>
            <span v-else>{{ t(`billing.plans.mode_${mode}`) }}</span>
        </p>

        <SkeletonRows v-if="plans.loading.value && !plans.data.value" :rows="3" />
        <ErrorState v-else-if="plans.error.value" :error="plans.error.value" @retry="plans.reload()" />
        <section v-else-if="!list.length" class="card">
            <EmptyState :icon="Package" :title="t('billing.plans.empty_title')" :text="t('billing.plans.empty_text')">
                <AppButton v-if="canEdit" variant="primary" :icon="Plus" @click="editing = null">{{ t('billing.plans.new') }}</AppButton>
            </EmptyState>
        </section>

        <div v-else class="grid gap-4 md:grid-cols-2">
            <article v-for="plan in list" :key="plan.id" class="card flex flex-col p-5" :class="plan.status === 'archived' ? 'opacity-75' : ''">
                <header class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-[15px] font-semibold text-fg">{{ plan.name }}</h2>
                        <p class="mt-0.5 text-[12.5px] text-muted">{{ t('billing.plans.based_on', { plan: plan.base_plan.name }) }}</p>
                    </div>
                    <div class="flex shrink-0 gap-1.5">
                        <AppBadge v-if="plan.status === 'archived'" tone="outline">{{ t('billing.plans.archived') }}</AppBadge>
                        <AppBadge tone="neutral" :icon="Users">{{ formatNumber(plan.clients) }}</AppBadge>
                    </div>
                </header>
                <p v-if="plan.description" class="mt-2 text-[13px] text-fg-2">{{ plan.description }}</p>

                <dl class="mt-4 space-y-1.5 text-[13px]">
                    <div v-for="price in plan.prices" :key="`${price.currency}-${price.period}`" class="flex items-baseline justify-between gap-3">
                        <dt class="text-muted">{{ t(`billing.periods.${price.period}`) }} · {{ price.currency }}</dt>
                        <dd class="tabular font-medium text-fg">{{ money(price.amount_minor, price.currency) }}</dd>
                    </div>
                </dl>

                <p v-if="monthlyMargin(plan.prices, plan.cost)" class="mt-3 rounded-lg px-2.5 py-1.5 text-[12.5px]" :class="monthlyMargin(plan.prices, plan.cost).amount < 0 ? 'bg-bad-soft text-bad' : 'bg-ok-soft text-ok'">
                    {{ t(monthlyMargin(plan.prices, plan.cost).amount < 0 ? 'billing.plans.loss' : 'billing.plans.margin', { amount: money(Math.abs(monthlyMargin(plan.prices, plan.cost).amount), plan.cost.currency), cost: money(plan.cost.amount_minor, plan.cost.currency) }) }}
                </p>
                <p v-else-if="plan.cost?.kind === 'wholesale' && plan.cost.amount_minor !== null" class="mt-3 text-[12.5px] text-muted">
                    {{ t(`billing.plans.cost_${plan.cost.unit}`, { amount: money(plan.cost.amount_minor, plan.cost.currency) }) }}
                </p>

                <p class="mt-3 text-[12.5px] text-muted">
                    {{ plan.modules ? t('billing.plans.some_modules', { count: plan.included_modules.length }) : t('billing.plans.all_modules') }}:
                    {{ plan.included_modules.slice(0, 6).map((module) => module.name).join(', ') }}<template v-if="plan.included_modules.length > 6">, …</template>
                </p>

                <footer v-if="canEdit" class="mt-auto flex gap-2 pt-4">
                    <AppButton size="sm" :icon="Pencil" @click="editing = plan">{{ t('core.actions.edit') }}</AppButton>
                    <AppButton v-if="plan.status === 'active'" size="sm" variant="danger-soft" :icon="Archive" @click="archive(plan)">{{ t('billing.plans.archive') }}</AppButton>
                </footer>
            </article>
        </div>

        <PartnerPlanDialog
            :open="editing !== undefined"
            :plan="editing ?? null"
            :base-plans="plans.data.value?.base_plans ?? []"
            :default-currency="plans.data.value?.partner_currency ?? 'USD'"
            @close="editing = undefined"
            @saved="saved"
        />
    </div>
</template>
