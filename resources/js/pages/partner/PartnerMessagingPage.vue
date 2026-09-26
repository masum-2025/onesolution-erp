<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { AtSign, CircleCheck, CircleX, Copy, Lock, MessageSquareText, RefreshCw, Send, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Partner console: how messages reach its clients. Email from the partner's
 * own domain once its DNS records check out (else from ours, under the
 * partner's name), the SMS sender ID, and test messages.
 */
const state = useResource(() => api('/api/partner/messaging').then((response) => response.data));
const data = computed(() => state.data.value);
const canEdit = computed(() => data.value?.can_edit === true);
const domain = computed(() => data.value?.mail.domain ?? null);

const busy = ref('');
const errors = ref({});
const newDomain = ref('');
const sender = reactive({ local_part: '', from_name: '', reply_to: '' });
const senderId = ref('');
const phone = ref('');

watch(domain, (value) => Object.assign(sender, { local_part: value?.local_part ?? 'no-reply', from_name: value?.from_name ?? '', reply_to: value?.reply_to ?? '' }), { immediate: true });

const CHECKS = ['ownership', 'spf', 'dkim', 'dmarc'];
const STATUS_TONES = { pending: 'warn', active: 'ok', approved: 'ok', rejected: 'bad' };

async function call(key, url, options = {}, onDone = () => {}) {
    busy.value = key;
    errors.value = {};
    try {
        const response = await api(url, options);
        if (response.data) state.data.value = response.data;
        toast.success(response.message);
        onDone();
    } catch (error) {
        // A failed DNS check still returns the fresh results.
        if (error.data?.data) state.data.value = error.data.data;
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([field, messages]) => [field, messages[0]]));
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = '';
    }
}

const addDomain = () => call('add', '/api/partner/messaging/domain', { method: 'POST', body: { domain: newDomain.value.trim() } }, () => (newDomain.value = ''));
const verify = () => call('verify', '/api/partner/messaging/domain/verify', { method: 'POST' });
const saveSender = () =>
    call('sender', '/api/partner/messaging/domain', {
        method: 'PATCH',
        body: { local_part: sender.local_part.trim(), from_name: sender.from_name.trim() || null, reply_to: sender.reply_to.trim() || null },
    });
const testEmail = () => call('test-email', '/api/partner/messaging/test-email', { method: 'POST' });
const requestSenderId = () => call('sender-id', '/api/partner/messaging/sms-sender', { method: 'POST', body: { sender_id: senderId.value.trim() } }, () => (senderId.value = ''));
const testSms = () => call('test-sms', '/api/partner/messaging/test-sms', { method: 'POST', body: { phone: phone.value.trim() } });

async function removeDomain() {
    const answer = await confirmAction({
        title: t('messaging.mail.remove_title', { domain: domain.value.domain }),
        message: t('messaging.mail.remove_text'),
        reason: 'required',
        danger: true,
        confirmLabel: t('messaging.mail.remove'),
    });
    if (answer) call('remove', '/api/partner/messaging/domain', { method: 'DELETE', body: { reason: answer.reason } });
}

async function removeSenderId() {
    const answer = await confirmAction({ title: t('messaging.sms.remove_title'), message: t('messaging.sms.remove_text'), danger: true, confirmLabel: t('messaging.sms.remove') });
    if (answer) call('remove-sender', '/api/partner/messaging/sms-sender', { method: 'DELETE' });
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(t('messaging.copied'));
    } catch {
        // Clipboard can be blocked; the value stays selectable on screen.
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('messaging.title')" :description="t('messaging.text')">
            <template #actions>
                <AppButton to="/partner/templates" :icon="MessageSquareText">{{ t('messaging.edit_wording') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="state.loading.value && !data" :rows="5" />
        <ErrorState v-else-if="state.error.value" :error="state.error.value" @retry="state.reload()" />

        <div v-else-if="data" class="space-y-6">
            <p v-if="!canEdit" class="flex items-center gap-2 rounded-xl bg-subtle p-3.5 text-[13px] text-fg-2">
                <Lock class="size-4 shrink-0 text-muted" aria-hidden="true" />{{ t('messaging.read_only') }}
            </p>

            <!-- Email -->
            <section class="card">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4">
                    <div>
                        <h2 class="text-[15px] font-semibold text-fg">{{ t('messaging.mail.title') }}</h2>
                        <p class="mt-0.5 text-[13px] text-muted">{{ t('messaging.mail.from_now') }}</p>
                        <p class="mt-1 font-mono text-[13.5px] text-fg" dir="ltr">{{ data.mail.from.name }} &lt;{{ data.mail.from.address }}&gt;</p>
                        <p v-if="data.mail.from.reply_to" class="text-[12.5px] text-muted">{{ t('messaging.mail.reply_to_now', { address: data.mail.from.reply_to }) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <AppBadge :tone="data.mail.from.own_domain ? 'ok' : 'neutral'">{{ t(data.mail.from.own_domain ? 'messaging.mail.own' : 'messaging.mail.ours') }}</AppBadge>
                        <AppButton v-if="canEdit" size="sm" :icon="Send" :loading="busy === 'test-email'" @click="testEmail">{{ t('messaging.mail.test') }}</AppButton>
                    </div>
                </header>

                <div class="space-y-5 px-5 py-5">
                    <p v-if="!data.mail.custom_domain_allowed" class="text-[13px] text-muted">{{ t('messaging.mail.not_allowed') }}</p>

                    <!-- No domain yet -->
                    <form v-else-if="!domain && canEdit" class="flex flex-wrap items-end gap-3" novalidate @submit.prevent="addDomain">
                        <AppField class="min-w-[16rem] flex-1" :label="t('messaging.mail.domain')" :hint="t('messaging.mail.domain_hint')" :error="errors.domain">
                            <template #default="{ id, invalid, describedby }">
                                <input :id="id" v-model="newDomain" class="field-input font-mono" dir="ltr" placeholder="mail.yourcompany.com" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                            </template>
                        </AppField>
                        <AppButton type="submit" variant="primary" :icon="AtSign" :loading="busy === 'add'" :disabled="!newDomain.trim()">{{ t('messaging.mail.add') }}</AppButton>
                    </form>
                    <p v-else-if="!domain" class="text-[13px] text-muted">{{ t('messaging.mail.none') }}</p>

                    <!-- Domain and its DNS records -->
                    <template v-if="domain">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="font-mono text-[14px] font-medium text-fg" dir="ltr">{{ domain.domain }}</span>
                            <AppBadge :tone="STATUS_TONES[domain.status]" dot>{{ t(`messaging.mail.status.${domain.status}`) }}</AppBadge>
                            <span v-if="domain.last_checked_at" class="text-[12px] text-muted">{{ t('messaging.mail.checked', { date: formatDateTime(domain.last_checked_at) }) }}</span>
                            <span class="flex-1" />
                            <AppButton v-if="canEdit" size="sm" :icon="RefreshCw" :loading="busy === 'verify'" @click="verify">{{ t('messaging.mail.verify') }}</AppButton>
                            <AppButton v-if="canEdit" size="sm" variant="danger-soft" :icon="Trash2" :aria-label="t('messaging.mail.remove')" @click="removeDomain" />
                        </div>
                        <p class="text-[12.5px] text-muted">{{ t('messaging.mail.records_text') }}</p>

                        <ul class="space-y-2.5">
                            <li v-for="check in CHECKS" :key="check" class="rounded-xl border p-3.5" :class="domain.checks[check] ? 'border-line' : 'border-warn/30 bg-warn-soft/40'">
                                <div class="flex items-center gap-2 text-[13px] font-medium text-fg">
                                    <CircleCheck v-if="domain.checks[check]" class="size-4 text-ok" aria-hidden="true" />
                                    <CircleX v-else class="size-4 text-warn" aria-hidden="true" />
                                    {{ t(`messaging.mail.checks.${check}`) }}
                                    <span class="sr-only">{{ t(domain.checks[check] ? 'messaging.mail.ok' : 'messaging.mail.missing') }}</span>
                                </div>
                                <dl class="mt-2 grid gap-1.5 text-[12.5px] sm:grid-cols-[5rem_1fr]">
                                    <dt class="text-muted">{{ t('messaging.mail.type') }}</dt>
                                    <dd class="font-mono text-fg-2">{{ domain.records[check].type }}</dd>
                                    <dt class="text-muted">{{ t('messaging.mail.name') }}</dt>
                                    <dd class="flex min-w-0 items-center gap-1.5">
                                        <code class="min-w-0 truncate font-mono text-fg-2" dir="ltr">{{ domain.records[check].name }}</code>
                                        <AppButton size="icon-sm" variant="ghost" :icon="Copy" :aria-label="t('messaging.copy')" @click="copy(domain.records[check].name)" />
                                    </dd>
                                    <dt class="text-muted">{{ t('messaging.mail.value') }}</dt>
                                    <dd class="flex min-w-0 items-start gap-1.5">
                                        <code class="min-w-0 font-mono break-all text-fg-2" dir="ltr">{{ domain.records[check].value }}</code>
                                        <AppButton size="icon-sm" variant="ghost" :icon="Copy" :aria-label="t('messaging.copy')" @click="copy(domain.records[check].value)" />
                                    </dd>
                                </dl>
                            </li>
                        </ul>

                        <form v-if="canEdit" class="grid gap-4 border-t border-line pt-5 sm:grid-cols-3" novalidate @submit.prevent="saveSender">
                            <AppField :label="t('messaging.mail.local_part')" :error="errors.local_part">
                                <template #default="{ id, invalid }">
                                    <div class="flex items-center gap-1.5" dir="ltr">
                                        <input :id="id" v-model="sender.local_part" class="field-input font-mono" :aria-invalid="invalid || undefined" />
                                        <span class="shrink-0 font-mono text-[12.5px] text-muted">@{{ domain.domain }}</span>
                                    </div>
                                </template>
                            </AppField>
                            <AppField :label="t('messaging.mail.from_name')" :hint="t('messaging.mail.from_name_hint')" :error="errors.from_name" optional>
                                <template #default="{ id, invalid, describedby }">
                                    <input :id="id" v-model="sender.from_name" class="field-input" maxlength="80" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                                </template>
                            </AppField>
                            <AppField :label="t('messaging.mail.reply_to')" :hint="t('messaging.mail.reply_to_hint')" :error="errors.reply_to" optional>
                                <template #default="{ id, invalid, describedby }">
                                    <input :id="id" v-model="sender.reply_to" type="email" class="field-input" dir="ltr" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                                </template>
                            </AppField>
                            <div class="sm:col-span-3">
                                <AppButton type="submit" :loading="busy === 'sender'">{{ t('messaging.mail.save_sender') }}</AppButton>
                            </div>
                        </form>
                    </template>
                </div>
            </section>

            <!-- SMS -->
            <section class="card">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4">
                    <div>
                        <h2 class="text-[15px] font-semibold text-fg">{{ t('messaging.sms.title') }}</h2>
                        <p class="mt-0.5 text-[13px] text-muted">{{ t(data.sms.enabled ? 'messaging.sms.on' : 'messaging.sms.off') }}</p>
                    </div>
                    <p class="text-[13px] text-fg-2">{{ t('messaging.sms.sender_now') }} <span class="font-mono font-medium text-fg" dir="ltr">{{ data.sms.sender_id }}</span></p>
                </header>

                <div class="space-y-5 px-5 py-5">
                    <div v-if="data.sms.own" class="flex flex-wrap items-center gap-3">
                        <span class="font-mono text-[14px] font-medium text-fg" dir="ltr">{{ data.sms.own.sender_id }}</span>
                        <AppBadge :tone="STATUS_TONES[data.sms.own.status]" dot>{{ t(`messaging.sms.status.${data.sms.own.status}`) }}</AppBadge>
                        <span v-if="data.sms.own.note" class="text-[12.5px] text-muted">{{ data.sms.own.note }}</span>
                        <span class="flex-1" />
                        <AppButton v-if="canEdit" size="sm" variant="danger-soft" :icon="Trash2" :aria-label="t('messaging.sms.remove')" @click="removeSenderId" />
                    </div>

                    <form v-if="canEdit" class="flex flex-wrap items-end gap-3" novalidate @submit.prevent="requestSenderId">
                        <AppField class="min-w-[14rem] flex-1" :label="t(data.sms.own ? 'messaging.sms.change' : 'messaging.sms.sender_id')" :hint="t(data.sms.needs_approval ? 'messaging.sms.approval_hint' : 'messaging.sms.hint')" :error="errors.sender_id">
                            <template #default="{ id, invalid, describedby }">
                                <input :id="id" v-model="senderId" class="field-input font-mono" maxlength="11" dir="ltr" placeholder="ACME ERP" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                            </template>
                        </AppField>
                        <AppButton type="submit" :loading="busy === 'sender-id'" :disabled="!senderId.trim()">{{ t(data.sms.needs_approval ? 'messaging.sms.request' : 'messaging.sms.save') }}</AppButton>
                    </form>

                    <form v-if="canEdit && data.sms.enabled" class="flex flex-wrap items-end gap-3 border-t border-line pt-5" novalidate @submit.prevent="testSms">
                        <AppField class="min-w-[14rem] flex-1" :label="t('messaging.sms.test_to')" :hint="t('messaging.sms.test_hint')" :error="errors.phone">
                            <template #default="{ id, invalid, describedby }">
                                <input :id="id" v-model="phone" type="tel" class="field-input font-mono" dir="ltr" placeholder="+8801712345678" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                            </template>
                        </AppField>
                        <AppButton type="submit" :icon="Send" :loading="busy === 'test-sms'" :disabled="!phone.trim()">{{ t('messaging.sms.test') }}</AppButton>
                    </form>
                </div>
            </section>
        </div>
    </div>
</template>
