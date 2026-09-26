<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Check, LifeBuoy, ShieldOff, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDateTime, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * The client decides who from its service provider may look inside, why
 * and for how long; it can end access at any time. Everything they open is
 * in the audit log.
 */
const org = currentOrganization();
const grants = useResource(() => api(`/api/organizations/${org.id}/support-grants`));
const list = computed(() => grants.data.value?.data ?? []);
const pending = computed(() => list.value.filter((grant) => grant.status === 'pending'));
const active = computed(() => list.value.filter((grant) => grant.status === 'approved'));
const past = computed(() => list.value.filter((grant) => !['pending', 'approved'].includes(grant.status)));

const now = ref(Date.now());
let timer;
onMounted(() => (timer = setInterval(() => (now.value = Date.now()), 15000)));
onBeforeUnmount(() => clearInterval(timer));
const minutesLeft = (grant) => Math.max(0, Math.ceil((new Date(grant.expires_at).getTime() - now.value) / 60000));

const SEVERITY_TONES = { critical: 'bad', high: 'warn', normal: 'neutral', low: 'outline' };
const STATUS_TONES = { approved: 'ok', rejected: 'neutral', revoked: 'neutral', expired: 'outline' };
const busy = ref(null);

async function act(grant, action) {
    const needsReason = action !== 'approve';
    const answer = await confirmAction({
        title: t(`trust.support.${action}_title`, { name: grant.requested_by.name ?? '' }),
        message: t(`trust.support.${action}_text`, { minutes: formatNumber(grant.duration_minutes) }),
        reason: needsReason ? 'required' : 'optional',
        danger: action !== 'approve',
        confirmLabel: t(`trust.support.${action}`),
    });
    if (!answer) return;

    busy.value = grant.id;
    try {
        const response = await api(`/api/organizations/${org.id}/support-grants/${grant.id}/${action}`, { method: 'POST', body: answer.reason ? { reason: answer.reason } : {} });
        toast.success(response.message);
        grants.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('trust.support.title')" :description="t('trust.support.text', { minutes: formatNumber(grants.data.value?.max_minutes ?? 120) })">
            <template #actions>
                <AppButton to="/audit-log">{{ t('trust.support.see_log') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="grants.loading.value && !grants.data.value" :rows="3" />
        <ErrorState v-else-if="grants.error.value" :error="grants.error.value" @retry="grants.reload()" />
        <EmptyState v-else-if="!list.length" :icon="LifeBuoy" :title="t('trust.support.empty_title')" :text="t('trust.support.empty_text')" />

        <div v-else class="space-y-6">
            <section v-for="[key, items] in [['pending', pending], ['active', active], ['past', past]]" v-show="items.length" :key="key" class="card">
                <h2 class="border-b border-line px-5 py-3.5 text-[14px] font-semibold text-fg">{{ t(`trust.support.sections.${key}`) }}</h2>
                <ul class="divide-y divide-line">
                    <li v-for="grant in items" :key="grant.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                                {{ grant.requested_by.name }} · {{ grant.partner.name }}
                                <AppBadge :tone="SEVERITY_TONES[grant.severity]">{{ t(`trust.support.severity.${grant.severity}`) }}</AppBadge>
                                <AppBadge v-if="key === 'past'" :tone="STATUS_TONES[grant.status]">{{ t(`trust.support.status.${grant.status}`) }}</AppBadge>
                                <AppBadge v-if="grant.auto_approved" tone="outline">{{ t('trust.support.auto') }}</AppBadge>
                            </p>
                            <p class="mt-1 text-[13px] text-fg-2">{{ grant.reason }}</p>
                            <p class="mt-1 text-[12px] text-muted">
                                {{ grant.organization.name }} · {{ t('trust.support.read_only') }} ·
                                <template v-if="key === 'active'">{{ t('trust.support.left', { count: minutesLeft(grant), minutes: formatNumber(minutesLeft(grant)) }) }}</template>
                                <template v-else>{{ t('trust.support.duration', { minutes: formatNumber(grant.duration_minutes) }) }} · {{ formatDateTime(grant.requested_at) }}</template>
                            </p>
                            <p v-if="grant.decision_reason && key === 'past'" class="mt-1 text-[12px] text-faint">{{ grant.decision_reason }}</p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <template v-if="key === 'pending'">
                                <AppButton size="sm" variant="primary" :icon="Check" :loading="busy === grant.id" @click="act(grant, 'approve')">{{ t('trust.support.approve') }}</AppButton>
                                <AppButton size="sm" :icon="X" :disabled="busy === grant.id" @click="act(grant, 'reject')">{{ t('trust.support.reject') }}</AppButton>
                            </template>
                            <AppButton v-else-if="key === 'active'" size="sm" variant="danger-soft" :icon="ShieldOff" :loading="busy === grant.id" @click="act(grant, 'revoke')">
                                {{ t('trust.support.revoke') }}
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
