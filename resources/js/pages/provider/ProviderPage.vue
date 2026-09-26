<script setup>
import { computed, reactive, ref } from 'vue';
import { ArrowRightLeft, CircleAlert, CircleCheck, FileText, Mail, Phone, PowerOff, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import LegalDocumentDialog from './LegalDocumentDialog.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDateTime } from '@/lib/format';
import { currentOrganization, loadMe } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * The client's provider: who it is, the legal documents in force (account
 * owners accept them), and moving the account to another provider, which
 * is the account owner's decision.
 */
const org = currentOrganization();
const provider = useResource(() => api(`/api/organizations/${org.id}/provider`).then((response) => response.data));
const data = computed(() => provider.data.value);
const reading = ref(null);

// Moving
const move = reactive({ target: 'code', code: '', reason: '', consent: false });
const preview = ref(null);
const errors = ref({});
const busy = ref('');
const targets = computed(() => [
    { value: 'code', label: t('provider.move.to_partner') },
    ...(data.value?.provider.house ? [] : [{ value: 'house', label: t('provider.move.to_house') }]),
]);
const destination = () => (move.target === 'house' ? { to_house: true } : { code: move.code.trim() });

async function showPreview() {
    errors.value = {};
    preview.value = null;
    busy.value = 'preview';
    try {
        preview.value = (await api(`/api/organizations/${org.id}/transfer/preview`, { method: 'POST', body: destination() })).data;
    } catch (error) {
        errors.value = { code: error.field('code') ?? error.message };
    } finally {
        busy.value = '';
    }
}

async function requestMove() {
    errors.value = {};
    if (move.reason.trim().length < 5) errors.value.reason = t('core.confirm.reason_short');
    if (!move.consent) errors.value.consent = t('provider.move.consent_required');
    if (Object.keys(errors.value).length) return;

    busy.value = 'request';
    try {
        const response = await api(`/api/organizations/${org.id}/transfer`, { method: 'POST', body: { ...destination(), reason: move.reason.trim(), consent: true } });
        toast.success(response.message);
        preview.value = null;
        Object.assign(move, { code: '', reason: '', consent: false });
        await Promise.all([provider.reload(), loadMe()]);
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([field, messages]) => [field, messages[0]]));
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = '';
    }
}

async function cancelMove() {
    const answer = await confirmAction({ title: t('provider.move.cancel_title'), message: t('provider.move.cancel_text'), danger: true, confirmLabel: t('provider.move.cancel') });
    if (!answer) return;
    try {
        const response = await api(`/api/organizations/${org.id}/transfer/${data.value.transfer.id}/cancel`, { method: 'POST' });
        toast.success(response.message);
        provider.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

async function accepted() {
    reading.value = null;
    await Promise.all([provider.reload(), loadMe()]);
}

const STATUS_TONES = { awaiting_partner: 'warn', completed: 'ok', rejected: 'bad', cancelled: 'neutral' };
</script>

<template>
    <div>
        <PageHeader :title="t('provider.title')" :description="t('provider.text')" />

        <SkeletonRows v-if="provider.loading.value && !data" :rows="5" />
        <ErrorState v-else-if="provider.error.value" :error="provider.error.value" @retry="provider.reload()" />

        <div v-else-if="data" class="space-y-6">
            <!-- Provider -->
            <section class="card p-5">
                <p class="text-[12px] font-semibold tracking-wider text-faint uppercase">{{ t('provider.current') }}</p>
                <p class="mt-1 text-[18px] font-semibold text-fg">{{ data.provider.name }}</p>
                <p v-if="data.provider.product !== data.provider.name" class="text-[13px] text-muted">{{ t('provider.product', { product: data.provider.product }) }}</p>
                <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[13px] text-fg-2">
                    <span v-if="data.provider.support_email" class="flex items-center gap-1.5" dir="ltr"><Mail class="size-4 text-muted" aria-hidden="true" />{{ data.provider.support_email }}</span>
                    <span v-if="data.provider.support_phone" class="flex items-center gap-1.5" dir="ltr"><Phone class="size-4 text-muted" aria-hidden="true" />{{ data.provider.support_phone }}</span>
                </div>
            </section>

            <!-- Documents -->
            <section class="card">
                <header class="border-b border-line px-5 py-3.5">
                    <h2 class="text-[15px] font-semibold text-fg">{{ t('provider.documents.title') }}</h2>
                    <p class="mt-0.5 text-[13px] text-muted">{{ t(data.can_accept ? 'provider.documents.text_owner' : 'provider.documents.text') }}</p>
                </header>
                <ul class="divide-y divide-line">
                    <li v-for="document in data.documents" :key="document.kind" class="flex flex-wrap items-center gap-3 px-5 py-4">
                        <FileText class="size-5 shrink-0 text-muted" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                                {{ document.title }}
                                <AppBadge v-if="document.needs_acceptance && document.accepted_at" tone="ok" :icon="CircleCheck">{{ t('provider.documents.accepted') }}</AppBadge>
                                <AppBadge v-else-if="document.needs_acceptance" tone="warn" :icon="CircleAlert">{{ t('provider.documents.to_accept') }}</AppBadge>
                            </p>
                            <p class="mt-0.5 text-[12.5px] text-muted">
                                {{ t('provider.documents.version_line', { version: document.version, date: formatDate(document.published_at) }) }}
                                <template v-if="document.accepted_at"> · {{ t('provider.documents.accepted_on', { date: formatDate(document.accepted_at) }) }}</template>
                            </p>
                        </div>
                        <AppButton size="sm" :variant="document.needs_acceptance && !document.accepted_at && data.can_accept ? 'primary' : 'secondary'" @click="reading = document.kind">
                            {{ document.needs_acceptance && !document.accepted_at && data.can_accept ? t('provider.documents.read_accept') : t('provider.documents.read') }}
                        </AppButton>
                    </li>
                </ul>
            </section>

            <!-- Moving -->
            <section v-if="data.can_transfer" class="card">
                <header class="border-b border-line px-5 py-3.5">
                    <h2 class="text-[15px] font-semibold text-fg">{{ t('provider.move.title') }}</h2>
                    <p class="mt-0.5 text-[13px] text-muted">{{ t('provider.move.text') }}</p>
                </header>

                <div v-if="data.transfer?.status === 'awaiting_partner'" class="flex flex-wrap items-center gap-3 px-5 py-4">
                    <AppBadge :tone="STATUS_TONES.awaiting_partner" dot>{{ t('provider.move.status.awaiting_partner') }}</AppBadge>
                    <span class="flex-1 text-[13.5px] text-fg-2">{{ t('provider.move.waiting', { partner: data.transfer.to.name, date: formatDateTime(data.transfer.created_at) }) }}</span>
                    <AppButton size="sm" variant="danger-soft" :icon="X" @click="cancelMove">{{ t('provider.move.cancel') }}</AppButton>
                </div>

                <form v-else class="space-y-4 px-5 py-5" novalidate @submit.prevent="preview ? requestMove() : showPreview()">
                    <p v-if="data.transfer && data.transfer.status !== 'awaiting_partner'" class="text-[12.5px] text-muted">
                        {{ t(`provider.move.last.${data.transfer.status}`, { partner: data.transfer.to.name, note: data.transfer.decision_note ?? '' }) }}
                    </p>

                    <AppSegmented v-if="targets.length > 1" v-model="move.target" :options="targets" :label="t('provider.move.where')" @update:model-value="preview = null" />

                    <AppField v-if="move.target === 'code'" :label="t('provider.move.code')" :hint="t('provider.move.code_hint')" :error="errors.code">
                        <template #default="{ id, invalid, describedby }">
                            <input :id="id" v-model="move.code" class="field-input max-w-xs font-mono uppercase" dir="ltr" placeholder="XXXX-XXXX-XXXX" autocomplete="off" :aria-invalid="invalid || undefined" :aria-describedby="describedby" @input="preview = null" />
                        </template>
                    </AppField>
                    <p v-else-if="errors.code" class="text-[12.5px] text-bad" role="alert">{{ errors.code }}</p>

                    <!-- What the move changes, before consenting -->
                    <div v-if="preview" class="space-y-2.5 rounded-xl border border-line p-4 text-[13px]">
                        <p class="font-medium text-fg">{{ t('provider.move.preview_title', { partner: preview.to.name }) }}</p>
                        <p v-for="problem in preview.problems" :key="problem" class="flex items-start gap-2 text-bad"><CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ problem }}</p>
                        <p class="flex items-start gap-2 text-fg-2"><CircleCheck class="mt-0.5 size-4 shrink-0 text-ok" aria-hidden="true" />{{ t('provider.move.keeps') }}</p>
                        <p v-if="preview.modules_off.length" class="flex items-start gap-2 text-bad"><PowerOff class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ t('provider.move.modules_off', { list: preview.modules_off.map((module) => module.name).join(', ') }) }}</p>
                        <p v-if="preview.partner_plan_ends" class="text-fg-2">{{ t('provider.move.plan_ends', { plan: preview.partner_plan_ends }) }}</p>
                        <p v-if="preview.domains_end.length" class="text-fg-2">{{ t('provider.move.domains_end', { list: preview.domains_end.join(', ') }) }}</p>
                        <p v-if="preview.support_access_ends" class="text-fg-2">{{ t('provider.move.support_ends') }}</p>
                        <p v-if="preview.open_invoices" class="text-fg-2">{{ t('provider.move.invoices_stay', { count: preview.open_invoices }) }}</p>
                        <p class="text-[12.5px] text-muted">{{ t(preview.to.house ? 'provider.move.house_note' : 'provider.move.partner_note', { partner: preview.to.name }) }}</p>
                    </div>

                    <template v-if="preview && !preview.problems.length">
                        <AppField :label="t('provider.move.reason')" :error="errors.reason">
                            <template #default="{ id, invalid }">
                                <input :id="id" v-model="move.reason" class="field-input" maxlength="500" :placeholder="t('provider.move.reason_placeholder')" :aria-invalid="invalid || undefined" />
                            </template>
                        </AppField>
                        <label class="flex items-start gap-2.5 text-[13.5px] text-fg-2">
                            <input v-model="move.consent" type="checkbox" class="mt-0.5 size-4 rounded accent-brand" />
                            <span>{{ t('provider.move.consent', { partner: preview.to.name }) }}</span>
                        </label>
                        <p v-if="errors.consent" class="text-[12.5px] text-bad" role="alert">{{ errors.consent }}</p>
                    </template>

                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="!preview" type="submit" :icon="ArrowRightLeft" :loading="busy === 'preview'" :disabled="move.target === 'code' && move.code.trim().length < 12">{{ t('provider.move.check') }}</AppButton>
                        <template v-else-if="!preview.problems.length">
                            <AppButton type="submit" variant="danger" :icon="ArrowRightLeft" :loading="busy === 'request'">{{ t(preview.to.house ? 'provider.move.submit_house' : 'provider.move.submit', { partner: preview.to.name }) }}</AppButton>
                            <AppButton @click="preview = null">{{ t('core.actions.cancel') }}</AppButton>
                        </template>
                    </div>
                </form>
            </section>
        </div>

        <LegalDocumentDialog :open="reading !== null" :organization-id="data?.organization.id ?? org.id" :kind="reading" @close="reading = null" @accepted="accepted" />
    </div>
</template>
