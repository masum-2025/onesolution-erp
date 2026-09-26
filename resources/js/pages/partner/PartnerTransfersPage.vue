<script setup>
import { computed, ref, watch } from 'vue';
import { Check, Copy, KeyRound, Plus, Trash2, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Partner console: clients moving in (codes to hand out, requests to
 * accept) and out (for information; the client decides).
 */
const direction = ref('incoming');
const list = useResource(() => api('/api/partner/transfers', { query: { direction: direction.value } }));
watch(direction, () => list.reload());
const rows = computed(() => list.data.value?.data ?? []);
const codes = computed(() => list.data.value?.codes ?? []);
const tabs = computed(() => [
    { key: 'incoming', label: t('provider.partner.incoming') },
    { key: 'outgoing', label: t('provider.partner.outgoing') },
]);

const STATUS_TONES = { awaiting_partner: 'warn', completed: 'ok', rejected: 'bad', cancelled: 'neutral', active: 'ok', used: 'neutral', expired: 'outline', revoked: 'outline' };

// New code: shown once.
const creating = ref(false);
const label = ref('');
const created = ref(null);
const saving = ref(false);

async function createCode() {
    saving.value = true;
    try {
        created.value = (await api('/api/partner/transfer-codes', { method: 'POST', body: { label: label.value.trim() || null } })).data;
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

function closeCode() {
    creating.value = false;
    created.value = null;
    label.value = '';
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(t('provider.partner.copied'));
    } catch {
        // Clipboard can be blocked; the code stays selectable on screen.
    }
}

async function revoke(code) {
    const answer = await confirmAction({ title: t('provider.partner.revoke_title', { hint: code.hint }), message: t('provider.partner.revoke_text'), danger: true, confirmLabel: t('provider.partner.revoke') });
    if (!answer) return;
    try {
        toast.success((await api(`/api/partner/transfer-codes/${code.id}`, { method: 'DELETE' })).message);
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

async function decide(transfer, accept) {
    const answer = await confirmAction({
        title: t(accept ? 'provider.partner.accept_title' : 'provider.partner.reject_title', { client: transfer.client.name }),
        message: t(accept ? 'provider.partner.accept_text' : 'provider.partner.reject_text'),
        reason: accept ? 'optional' : 'required',
        danger: !accept,
        confirmLabel: t(accept ? 'provider.partner.accept' : 'provider.partner.reject'),
    });
    if (!answer) return;
    try {
        const response = await api(`/api/partner/transfers/${transfer.id}/${accept ? 'accept' : 'reject'}`, { method: 'POST', body: answer.reason ? { note: answer.reason } : {} });
        toast.success(response.message);
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('provider.partner.title')" :description="t('provider.partner.text')">
            <template #actions>
                <AppButton v-if="list.data.value?.can_create_codes" variant="primary" :icon="Plus" @click="creating = true">{{ t('provider.partner.new_code') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card mb-6">
            <div class="px-5 pt-2"><AppTabs v-model="direction" :tabs="tabs" :label="t('provider.partner.title')" /></div>
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="3" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="KeyRound" :title="t(`provider.partner.empty_${direction}`)" :text="t(`provider.partner.empty_${direction}_text`)" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="transfer in rows" :key="transfer.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                            {{ transfer.client.name }}
                            <AppBadge :tone="STATUS_TONES[transfer.status]" dot>{{ t(`provider.move.status.${transfer.status}`) }}</AppBadge>
                            <AppBadge v-if="transfer.by_platform" tone="outline">{{ t('provider.partner.by_platform') }}</AppBadge>
                        </p>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            <template v-if="direction === 'incoming'">{{ t('provider.partner.from', { partner: transfer.from.name }) }} · </template>
                            {{ formatDateTime(transfer.created_at) }} · {{ transfer.reason }}
                        </p>
                        <p v-if="direction === 'incoming' && transfer.summary?.modules_off?.length" class="mt-0.5 text-[12px] text-warn">
                            {{ t('provider.partner.modules_off', { list: transfer.summary.modules_off.map((module) => module.name).join(', ') }) }}
                        </p>
                    </div>
                    <div v-if="direction === 'incoming' && transfer.status === 'awaiting_partner' && list.data.value?.can_decide" class="flex shrink-0 gap-2">
                        <AppButton size="sm" variant="primary" :icon="Check" @click="decide(transfer, true)">{{ t('provider.partner.accept') }}</AppButton>
                        <AppButton size="sm" :icon="X" @click="decide(transfer, false)">{{ t('provider.partner.reject') }}</AppButton>
                    </div>
                </li>
            </ul>
        </section>

        <section class="card">
            <header class="border-b border-line px-5 py-3.5">
                <h2 class="text-[15px] font-semibold text-fg">{{ t('provider.partner.codes') }}</h2>
                <p class="mt-0.5 text-[13px] text-muted">{{ t('provider.partner.codes_text') }}</p>
            </header>
            <p v-if="!codes.length" class="px-5 py-4 text-[13px] text-muted">{{ t('provider.partner.no_codes') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="code in codes" :key="code.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <span class="font-mono text-[13px] text-fg" dir="ltr">••••-••••-{{ code.hint }}</span>
                    <span class="min-w-0 flex-1 truncate text-[13px] text-fg-2">{{ code.label ?? '—' }}</span>
                    <AppBadge :tone="STATUS_TONES[code.status]">{{ t(`provider.partner.code_status.${code.status}`) }}</AppBadge>
                    <span class="text-[12px] text-muted">{{ t('provider.partner.until', { date: formatDate(code.expires_at) }) }}</span>
                    <AppButton v-if="code.status === 'active' && list.data.value?.can_create_codes" size="icon-sm" variant="danger-soft" :icon="Trash2" :aria-label="t('provider.partner.revoke')" @click="revoke(code)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="creating" :title="t('provider.partner.new_code')" :description="t('provider.partner.new_code_text')" :icon="KeyRound" size="sm" @close="closeCode">
            <div v-if="created" class="space-y-3">
                <p class="text-[13px] text-fg-2">{{ t('provider.partner.code_once') }}</p>
                <div class="flex items-center gap-2">
                    <code class="flex-1 rounded-xl bg-subtle px-4 py-3 text-center font-mono text-[18px] font-semibold tracking-wider text-fg" dir="ltr">{{ created.code }}</code>
                    <AppButton size="icon" :icon="Copy" :aria-label="t('provider.partner.copy')" @click="copy(created.code)" />
                </div>
                <p class="text-[12.5px] text-muted">{{ t('provider.partner.until', { date: formatDate(created.expires_at) }) }}</p>
            </div>
            <form v-else id="code-form" @submit.prevent="createCode">
                <AppField :label="t('provider.partner.label')" :hint="t('provider.partner.label_hint')" optional>
                    <template #default="{ id, describedby }">
                        <input :id="id" v-model="label" class="field-input" maxlength="100" :aria-describedby="describedby" />
                    </template>
                </AppField>
            </form>
            <template #footer>
                <AppButton @click="closeCode">{{ created ? t('core.actions.close') : t('core.actions.cancel') }}</AppButton>
                <AppButton v-if="!created" type="submit" form="code-form" variant="primary" :loading="saving">{{ t('provider.partner.create') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
