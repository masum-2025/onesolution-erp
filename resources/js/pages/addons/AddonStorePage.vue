<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { PackagePlus, Search } from 'lucide-vue-next';
import { PageFrame, AppButton, AppBadge, AppSegmented, StatusBadge, EmptyState, confirmAction, newOpId, toast } from '@/ui';
import { api } from '@/lib/http';
import { invalidate } from '@/lib/cache';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatDate, formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * The add-on store of the account (its top organization): modules beyond
 * the plan, to try free or buy month by month, and those already added.
 * The server decides prices and who may buy; this page only shows and asks.
 */
const router = useRouter();
const context = currentOrganization();
const store = useResource(() => api(`/api/organizations/${context.id}/addons`));
const filter = ref('all');
const query = ref('');
const busy = ref(null);

const data = computed(() => store.data.value?.data ?? null);
const canBuy = computed(() => !!store.data.value?.can_buy && !!data.value?.open && !!data.value?.self_purchase);
const items = computed(() => data.value?.items ?? []);
const added = (item) => ['trial', 'active'].includes(item.status);

const shown = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    return items.value
        .filter((item) => item.status !== 'unavailable')
        .filter((item) => filter.value === 'all' || (filter.value === 'added' ? added(item) : filter.value === item.status))
        .filter((item) => !needle || item.name.toLocaleLowerCase().includes(needle) || item.description.toLocaleLowerCase().includes(needle))
        .sort((a, b) => Number(added(b)) - Number(added(a)) || Number(b.status === 'for_sale') - Number(a.status === 'for_sale') || a.name.localeCompare(b.name));
});

const filters = computed(() => {
    const count = (test) => items.value.filter(test).length;
    return [
        { value: 'all', label: t('addons.filter.all', { count: count((item) => item.status !== 'unavailable') }) },
        { value: 'for_sale', label: t('addons.filter.for_sale', { count: count((item) => item.status === 'for_sale') }) },
        { value: 'added', label: t('addons.filter.added', { count: count(added) }) },
        { value: 'in_plan', label: t('addons.filter.in_plan', { count: count((item) => item.status === 'in_plan') }) },
    ];
});

const tones = { trial: 'brand', active: 'ok', in_plan: 'neutral', for_sale: 'outline' };

function priceText(item) {
    if (item.billed_by_provider) return item.price ? t('addons.price_provider_set', { price: formatMoney({ amount: item.price.amount_minor, currency: item.price.currency }) }) : t('addons.price_provider');
    return item.price ? t('addons.per_month', { price: formatMoney({ amount: item.price.amount_minor, currency: item.price.currency }) }) : '';
}

function missing(item) {
    return item.requires.filter((required) => !required.available);
}

async function act(item, action, confirm) {
    const answer = await confirmAction(confirm);
    if (!answer) return;
    busy.value = item.key;
    try {
        const body = ['trial', 'buy'].includes(action) ? { op_id: newOpId() } : {};
        const response = await api(`/api/organizations/${context.id}/addons/${item.key}/${action}`, { method: 'POST', body });
        store.data.value = { ...store.data.value, data: response.data };
        // The menu and module pages change with the add-on.
        invalidate('menu');
        toast.success(response.message);
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

const trial = (item) => act(item, 'trial', {
    title: t('addons.confirm.trial_title', { name: item.name }),
    message: t('addons.confirm.trial_text', { days: data.value.trial_days }),
    confirmLabel: t('addons.actions.trial'),
});

const buy = (item) => act(item, 'buy', {
    title: t('addons.confirm.buy_title', { name: item.name }),
    message: item.billed_by_provider ? t('addons.confirm.buy_provider_text', { provider: data.value.provider ?? '' }) : t('addons.confirm.buy_text', { price: priceText(item) }),
    confirmLabel: t('addons.actions.buy'),
});

const cancel = (item) => act(item, 'cancel', {
    title: t('addons.confirm.cancel_title', { name: item.name }),
    message: item.status === 'trial' ? t('addons.confirm.cancel_trial_text') : t('addons.confirm.cancel_text', { date: formatDate(item.period_end) }),
    danger: true,
    confirmLabel: t('addons.actions.cancel'),
});

const resume = (item) => act(item, 'resume', {
    title: t('addons.confirm.resume_title', { name: item.name }),
    message: t('addons.confirm.resume_text'),
    confirmLabel: t('addons.actions.resume'),
});
</script>

<template>
    <PageFrame :title="t('addons.title')" :description="t('addons.text')" width="xl" :resource="store">
        <div v-if="data" class="space-y-4">
            <p v-if="!data.open" class="rounded-xl border border-line bg-surface-2 p-4 text-[14px]">{{ t('addons.closed') }}</p>
            <p v-else-if="!data.self_purchase" class="rounded-xl border border-line bg-surface-2 p-4 text-[14px]">
                {{ t('addons.ask_provider', { provider: data.provider ?? t('addons.your_provider') }) }}
            </p>
            <p v-else-if="!store.data.value.can_buy" class="rounded-xl border border-line bg-surface-2 p-4 text-[14px]">{{ t('addons.view_only') }}</p>

            <div class="flex flex-wrap items-center gap-3">
                <AppSegmented v-model="filter" :options="filters" />
                <label class="relative ms-auto w-full sm:w-64">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                    <span class="sr-only">{{ t('addons.search') }}</span>
                    <input v-model="query" type="search" class="w-full rounded-lg border border-line bg-surface py-2 pe-3 ps-9 text-[14px]" :placeholder="t('addons.search')" />
                </label>
            </div>

            <EmptyState v-if="!shown.length" :icon="PackagePlus" :title="t('addons.empty_title')" :text="t('addons.empty_text')" />

            <ul v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="item in shown" :key="item.key" class="flex flex-col rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-[15px] font-semibold">{{ item.name }}</h2>
                        <StatusBadge :status="item.status" :tones="tones">{{ t(`addons.status.${item.status}`) }}</StatusBadge>
                    </div>
                    <p class="mt-1 flex-1 text-[13px] text-muted">{{ item.description }}</p>

                    <p v-if="item.status === 'for_sale' && priceText(item)" class="mt-3 text-[14px] font-medium">{{ priceText(item) }}</p>
                    <p v-if="item.status === 'trial' && item.trial_ends_at" class="mt-3 text-[13px]">{{ t('addons.trial_until', { date: formatDate(item.trial_ends_at) }) }}</p>
                    <p v-if="item.status === 'active' && item.period_end" class="mt-3 text-[13px]">
                        {{ item.cancel_at_period_end ? t('addons.ends_on', { date: formatDate(item.period_end) }) : t('addons.renews_on', { date: formatDate(item.period_end) }) }}
                    </p>
                    <p v-if="item.status === 'in_plan'" class="mt-3 text-[13px] text-muted">
                        {{ item.enabled ? t('addons.in_plan_on') : t('addons.in_plan_off') }}
                    </p>
                    <p v-if="missing(item).length && item.status === 'for_sale'" class="mt-2 text-[13px] text-warn">
                        {{ t('addons.needs', { names: missing(item).map((required) => required.name).join(', ') }) }}
                    </p>

                    <div v-if="canBuy" class="mt-3 flex flex-wrap gap-2">
                        <template v-if="item.status === 'for_sale'">
                            <AppButton size="sm" :loading="busy === item.key" :disabled="missing(item).length > 0" @click="buy(item)">{{ t('addons.actions.buy') }}</AppButton>
                            <AppButton v-if="item.can_trial" size="sm" variant="secondary" :disabled="busy === item.key || missing(item).length > 0" @click="trial(item)">
                                {{ t('addons.actions.trial_days', { days: data.trial_days }) }}
                            </AppButton>
                        </template>
                        <template v-else-if="item.status === 'trial'">
                            <AppButton size="sm" :loading="busy === item.key" @click="buy(item)">{{ t('addons.actions.buy') }}</AppButton>
                            <AppButton size="sm" variant="ghost" :disabled="busy === item.key" @click="cancel(item)">{{ t('addons.actions.end_trial') }}</AppButton>
                        </template>
                        <template v-else-if="item.status === 'active'">
                            <AppButton v-if="item.cancel_at_period_end" size="sm" variant="secondary" :loading="busy === item.key" @click="resume(item)">{{ t('addons.actions.resume') }}</AppButton>
                            <AppButton v-else size="sm" variant="ghost" :loading="busy === item.key" @click="cancel(item)">{{ t('addons.actions.cancel') }}</AppButton>
                        </template>
                        <AppButton v-else-if="item.status === 'in_plan' && !item.enabled" size="sm" variant="ghost" @click="router.push('/modules')">{{ t('addons.actions.open_modules') }}</AppButton>
                    </div>
                    <AppBadge v-if="item.billed_by_provider && item.status === 'active'" tone="neutral" class="mt-2 self-start">{{ t('addons.billed_by_provider') }}</AppBadge>
                </li>
            </ul>
        </div>
    </PageFrame>
</template>
