<script setup>
import { computed, ref } from 'vue';
import { ArrowRightLeft, Building2, ChevronLeft, ChevronRight, Gauge, Pause, Play, Plus, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import OrgTypeIcon from '@/components/OrgTypeIcon.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import PlanChangeDialog from './PlanChangeDialog.vue';
import ClientCreateDialog from './ClientCreateDialog.vue';
import ClientLimitsDialog from './ClientLimitsDialog.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { countryName } from '@/lib/display';
import { formatDate, formatNumber } from '@/lib/format';
import { loadPlans } from '@/lib/packaging';
import { session } from '@/lib/session';
import { confirmAction } from '@/lib/dialogs';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Partner console: the partner's client accounts. Account metadata only,
 * never the clients' business data (enforced by the API).
 */
const page = ref(1);
const query = ref('');
const clients = useResource(() => api('/api/partner/organizations', { query: { page: page.value, per_page: 50 } }));

const rows = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    return (clients.data.value?.data ?? []).filter((org) => !needle || org.display_name.toLocaleLowerCase().includes(needle));
});
const meta = computed(() => clients.data.value?.meta ?? null);

function go(to) {
    page.value = to;
    clients.reload();
}

const STATUS_TONES = { active: 'ok', suspended: 'warn', archived: 'neutral' };

// Plans are changed per subscription (top organization), by partner owners and billing staff.
const canChangePlans = computed(() => ['owner', 'billing'].includes(session.me?.context?.role));
const plans = useResource(() => loadPlans().catch(() => []));
const planName = (key) => (plans.data.value ?? []).find((plan) => plan.key === key)?.name ?? key;
const changing = ref(null);

function planChanged() {
    changing.value = null;
    clients.reload();
}

// Client accounts: sales and owners create them; owners suspend; everyone sees limits.
const role = computed(() => session.me?.context?.role);
const canCreate = computed(() => ['owner', 'sales'].includes(role.value));
const canSuspend = computed(() => role.value === 'owner');
const creating = ref(false);
const limitsFor = ref(null);

function created() {
    creating.value = false;
    clients.reload();
}

async function toggleStatus(org) {
    const suspending = org.status === 'active';
    const answer = await confirmAction({
        title: t(suspending ? 'partner.clients.suspend_title' : 'partner.clients.reactivate_title', { name: org.display_name }),
        message: t(suspending ? 'partner.clients.suspend_text' : 'partner.clients.reactivate_text'),
        reason: 'required',
        danger: suspending,
        confirmLabel: t(suspending ? 'partner.clients.suspend' : 'partner.clients.reactivate'),
    });
    if (!answer) return;
    try {
        const response = await api(`/api/partner/clients/${org.id}/status`, { method: 'PATCH', body: { status: suspending ? 'suspended' : 'active', reason: answer.reason } });
        toast.success(response.message);
        clients.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('partner.clients.title')" :description="t('partner.clients.text')">
            <template #actions>
                <AppButton v-if="canCreate" variant="primary" :icon="Plus" @click="creating = true">{{ t('partner.clients.new') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card overflow-hidden">
            <header class="flex flex-col gap-3 border-b border-line px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <div class="relative w-full sm:max-w-xs">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                    <input v-model="query" type="search" class="field-input h-9 min-h-9 ps-9" :placeholder="t('partner.clients.search')" :aria-label="t('partner.clients.search')" />
                </div>
                <span v-if="meta" class="tabular text-[12.5px] text-muted">{{ t('partner.clients.count', { count: meta.total, formatted: formatNumber(meta.total) }) }}</span>
            </header>

            <SkeletonRows v-if="clients.loading.value && !clients.data.value" :rows="6" avatar />
            <ErrorState v-else-if="clients.error.value" :error="clients.error.value" @retry="clients.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="Building2" :title="t('partner.clients.empty_title')" :text="t('partner.clients.empty_text')" />

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line text-start text-[12px] text-muted">
                            <th class="px-5 py-2.5 text-start font-medium">{{ t('partner.clients.columns.name') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ t('partner.clients.columns.sector') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ t('partner.clients.columns.country') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ t('partner.clients.columns.plan') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ t('partner.clients.columns.status') }}</th>
                            <th class="px-5 py-2.5 text-end font-medium">{{ t('partner.clients.columns.since') }}</th>
                            <th class="px-5 py-2.5"><span class="sr-only">{{ t('core.actions.more') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="org in rows" :key="org.id" class="transition-colors hover:bg-subtle/60">
                            <td class="px-5 py-3">
                                <span class="flex items-center gap-3" :style="{ paddingInlineStart: `${Math.max(0, org.depth) * 1.25}rem` }">
                                    <OrgTypeIcon :type="org.type" size="sm" />
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-fg">{{ org.display_name }}</span>
                                        <span class="block text-[12px] text-muted">{{ t(`core.org_types.${org.type}`) }}</span>
                                    </span>
                                </span>
                            </td>
                            <td class="px-3 py-3 font-mono text-[12.5px] text-fg-2">{{ org.sector_key ?? '—' }}</td>
                            <td class="px-3 py-3 text-fg-2">{{ org.country_code ? countryName(org.country_code) : '—' }}</td>
                            <td class="px-3 py-3 text-fg-2">{{ org.subscription_plan ? planName(org.subscription_plan) : '—' }}</td>
                            <td class="px-3 py-3"><AppBadge :tone="STATUS_TONES[org.status]" dot>{{ t(`orgs.status.${org.status}`) }}</AppBadge></td>
                            <td class="px-5 py-3 text-end text-muted">{{ formatDate(org.created_at) }}</td>
                            <td class="px-5 py-3 text-end">
                                <span v-if="org.parent_id === null" class="inline-flex gap-1.5">
                                    <AppButton size="sm" variant="ghost" :icon="Gauge" @click="limitsFor = org">{{ t('partner.clients.limits') }}</AppButton>
                                    <AppButton v-if="canChangePlans" size="sm" :icon="ArrowRightLeft" @click="changing = org">{{ t('packaging.change.button') }}</AppButton>
                                    <AppButton
                                        v-if="canSuspend"
                                        size="sm"
                                        :variant="org.status === 'active' ? 'danger-soft' : 'secondary'"
                                        :icon="org.status === 'active' ? Pause : Play"
                                        :aria-label="t(org.status === 'active' ? 'partner.clients.suspend' : 'partner.clients.reactivate')"
                                        @click="toggleStatus(org)"
                                    />
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3">
                <span class="tabular text-[12.5px] text-muted">{{ t('partner.clients.page', { page: formatNumber(meta.current_page), pages: formatNumber(meta.last_page) }) }}</span>
                <div class="flex gap-2">
                    <AppButton size="sm" :icon="ChevronLeft" :disabled="meta.current_page <= 1" @click="go(meta.current_page - 1)">{{ t('core.actions.previous') }}</AppButton>
                    <AppButton size="sm" :icon-end="ChevronRight" :disabled="meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">{{ t('core.actions.next') }}</AppButton>
                </div>
            </footer>
        </section>

        <PlanChangeDialog :open="!!changing" :client="changing" @close="changing = null" @changed="planChanged" />
        <ClientCreateDialog :open="creating" @close="creating = false" @created="created" />
        <ClientLimitsDialog :open="!!limitsFor" :client="limitsFor" @close="limitsFor = null" />
    </div>
</template>
