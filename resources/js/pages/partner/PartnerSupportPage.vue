<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { LifeBuoy, LogIn, Plus, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import SupportRequestDialog from './SupportRequestDialog.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDateTime, formatNumber } from '@/lib/format';
import { enterContext, session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Support staff ask a client for time-limited, read-only access; once the
 * client approves, they enter from here. Owners see every request.
 */
const router = useRouter();
const grants = useResource(() => api('/api/partner/support-grants').then((response) => response.data));
const list = computed(() => grants.data.value ?? []);
const canRequest = computed(() => ['owner', 'support'].includes(session.me?.context?.role));
const asking = ref(false);
const busy = ref(null);

const STATUS_TONES = { pending: 'warn', approved: 'ok', rejected: 'bad', revoked: 'neutral', expired: 'outline' };
const isMine = (grant) => grant.requested_by.id === session.me?.user?.id;

async function enter(grant) {
    busy.value = grant.id;
    try {
        await enterContext({ support_grant_id: grant.id });
        await router.push('/');
    } catch (error) {
        toast.error(error.message);
        grants.reload();
    } finally {
        busy.value = null;
    }
}

async function cancel(grant) {
    const answer = await confirmAction({
        title: t('trust.partner.cancel_title'),
        message: t('trust.partner.cancel_text', { org: grant.organization.name }),
        reason: 'required',
        danger: true,
        confirmLabel: t('trust.partner.cancel'),
    });
    if (!answer) return;
    busy.value = grant.id;
    try {
        const response = await api(`/api/partner/support-grants/${grant.id}/cancel`, { method: 'POST', body: { reason: answer.reason } });
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
        <PageHeader :title="t('trust.partner.title')" :description="t('trust.partner.text')">
            <template #actions>
                <AppButton v-if="canRequest" variant="primary" :icon="Plus" @click="asking = true">{{ t('trust.partner.request') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="grants.loading.value && !grants.data.value" :rows="4" />
            <ErrorState v-else-if="grants.error.value" compact :error="grants.error.value" @retry="grants.reload()" />
            <EmptyState v-else-if="!list.length" :icon="LifeBuoy" :title="t('trust.partner.empty_title')" :text="t(canRequest ? 'trust.partner.empty_text' : 'trust.partner.empty_text_other')" compact>
                <AppButton v-if="canRequest" variant="primary" :icon="Plus" @click="asking = true">{{ t('trust.partner.request') }}</AppButton>
            </EmptyState>

            <ul v-else class="divide-y divide-line">
                <li v-for="grant in list" :key="grant.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                            {{ grant.organization.name }}
                            <AppBadge :tone="STATUS_TONES[grant.status]">{{ t(`trust.support.status.${grant.status}`) }}</AppBadge>
                            <AppBadge tone="outline">{{ t(`trust.support.severity.${grant.severity}`) }}</AppBadge>
                        </p>
                        <p class="mt-1 line-clamp-2 text-[13px] text-fg-2">{{ grant.reason }}</p>
                        <p class="mt-1 text-[12px] text-muted">
                            {{ grant.requested_by.name }} · {{ formatDateTime(grant.requested_at) }} ·
                            <template v-if="grant.status === 'approved'">{{ t('trust.partner.until', { date: formatDateTime(grant.expires_at) }) }}</template>
                            <template v-else>{{ t('trust.support.duration', { minutes: formatNumber(grant.duration_minutes) }) }}</template>
                        </p>
                        <p v-if="grant.decision_reason" class="mt-1 text-[12px] text-faint">{{ grant.decided_by?.name }}: {{ grant.decision_reason }}</p>
                    </div>
                    <div v-if="isMine(grant) && ['pending', 'approved'].includes(grant.status)" class="flex shrink-0 gap-2">
                        <AppButton v-if="grant.status === 'approved'" size="sm" variant="primary" :icon="LogIn" :loading="busy === grant.id" @click="enter(grant)">{{ t('trust.partner.enter') }}</AppButton>
                        <AppButton size="sm" variant="danger-soft" :icon="X" :disabled="busy === grant.id" @click="cancel(grant)">{{ t('trust.partner.cancel') }}</AppButton>
                    </div>
                </li>
            </ul>
        </section>

        <SupportRequestDialog :open="asking" @close="asking = false" @requested="grants.reload()" />
    </div>
</template>
