<script setup>
import { computed, reactive, ref } from 'vue';
import { Check, CreditCard, Info, Pencil, Plug, Power, ShieldCheck, X } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate, formatDateTime } from '@/lib/format';
import { confirmAction } from '@/lib/dialogs';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * A client's own payment gateway accounts (Phase 6): where its customers'
 * money goes. Every change waits for a second person (or the waiting time),
 * so the page shows what is in effect and what is waiting, side by side.
 * Secrets are typed here and never shown again: the server sends hints only.
 */
const org = currentOrganization();
const base = `/api/organizations/${org.id}/merchant-accounts`;
const resource = useResource(() => api(base).then((response) => response.data));
const data = computed(() => resource.data.value);
const unconnected = computed(() => (data.value?.gateways ?? []).filter((gateway) => !data.value.accounts.some((account) => account.gateway === gateway.key)));

const STATUS_TONES = { active: 'ok', pending: 'warn', disabled: 'neutral' };
const busy = ref(null);

function gatewayOf(key) {
    return data.value?.gateways.find((gateway) => gateway.key === key) ?? null;
}

async function run(account, action, body) {
    busy.value = `${account.id}:${action}`;
    try {
        const response = await api(`${base}/${account.id}/${action}`, { method: 'POST', body });
        toast.success(response.message);
        await resource.reload();
        return true;
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'stale') await resource.reload();
        return false;
    } finally {
        busy.value = null;
    }
}

async function withReason(account, action) {
    const confirmed = await confirmAction({
        title: t(`payments.merchant.${action}_title`),
        message: t(`payments.merchant.${action}_text`),
        reason: 'optional',
        reasonLabel: t('payments.merchant.reason'),
        confirmLabel: t(`payments.merchant.${action}`),
        danger: true,
    });
    if (confirmed) await run(account, action, { base_version: account.version, reason: confirmed.reason || null });
}

// ── Password confirmation (approve, turn on) ──
const confirm = reactive({ open: false, account: null, action: null, password: '', error: null, saving: false });

function askPassword(account, action) {
    Object.assign(confirm, { open: true, account, action, password: '', error: null, saving: false });
}

async function submitPassword() {
    confirm.error = null;
    confirm.saving = true;
    try {
        const response = await api(`${base}/${confirm.account.id}/${confirm.action}`, {
            method: 'POST',
            body: { base_version: confirm.account.version, current_password: confirm.password },
        });
        confirm.open = false;
        toast.success(response.message);
        await resource.reload();
    } catch (error) {
        confirm.error = error.field?.('current_password') ?? error.message;
        if (error.code === 'stale') await resource.reload();
    } finally {
        confirm.saving = false;
    }
}

// ── Connect or change ──
const form = reactive({ open: false, account: null, gateway: null, label: '', mode: 'sandbox', details: true, credentials: {}, password: '', errors: {}, saving: false });
const formGateway = computed(() => gatewayOf(form.gateway));
const modeOptions = computed(() => [
    { value: 'sandbox', label: t('payments.merchant.mode.sandbox') },
    ...(data.value?.live_allowed ? [{ value: 'live', label: t('payments.merchant.mode.live') }] : []),
]);

function openConnect(gateway) {
    Object.assign(form, { open: true, account: null, gateway: gateway.key, label: '', mode: 'sandbox', details: true, credentials: {}, password: '', errors: {}, saving: false });
}

function openChange(account) {
    Object.assign(form, { open: true, account, gateway: account.gateway, label: account.label, mode: account.mode ?? 'sandbox', details: false, credentials: {}, password: '', errors: {}, saving: false });
}

async function submitForm() {
    form.errors = {};
    form.saving = true;
    const credentials = form.details ? { ...form.credentials } : undefined;
    try {
        const response = form.account
            ? await api(`${base}/${form.account.id}`, {
                  method: 'PATCH',
                  body: {
                      base_version: form.account.version,
                      label: form.label.trim(),
                      ...(credentials ? { mode: form.mode, credentials } : {}),
                      current_password: form.password,
                  },
              })
            : await api(base, {
                  method: 'POST',
                  body: { gateway: form.gateway, label: form.label.trim(), mode: form.mode, credentials, current_password: form.password },
              });
        form.open = false;
        toast.success(response.message);
        await resource.reload();
    } catch (error) {
        for (const [field, messages] of Object.entries(error.errors ?? {})) form.errors[field] = messages[0];
        // A gateway refusal or a rule problem has no single field: show it above the button.
        if (!Object.keys(form.errors).length) form.errors.form = error.message;
        if (error.code === 'stale') await resource.reload();
    } finally {
        form.saving = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('payments.merchant.title')" :description="t('payments.merchant.text')" />

        <SkeletonRows v-if="resource.loading.value && !data" :rows="4" />
        <ErrorState v-else-if="resource.error.value" :error="resource.error.value" @retry="resource.reload()" />

        <template v-else-if="data">
            <p v-if="data.inherited" class="mb-4 flex items-start gap-2 rounded-xl bg-subtle px-4 py-3 text-[13px] text-fg-2">
                <Info class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" />
                {{ t('payments.merchant.inherited', { company: data.company.name }) }}
            </p>

            <EmptyState
                v-if="!data.gateways.length && !data.accounts.length"
                :icon="CreditCard"
                :title="t('payments.merchant.none_offered_title')"
                :text="t('payments.merchant.none_offered_text')"
            />

            <div v-else class="space-y-4">
                <p v-if="data.can_manage && data.only_approver" class="flex items-start gap-2 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-fg-2">
                    <ShieldCheck class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />
                    {{ t('payments.merchant.only_approver', { hours: data.wait_hours }) }}
                </p>

                <p v-if="!data.accounts.length" class="text-[13px] text-muted">{{ t('payments.merchant.no_accounts') }}</p>

                <section v-for="account in data.accounts" :key="account.id" class="card">
                    <div class="flex flex-wrap items-start gap-3 border-b border-line px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <h2 class="flex flex-wrap items-center gap-2 text-[14.5px] font-semibold text-fg">
                                {{ account.label }}
                                <AppBadge :tone="STATUS_TONES[account.status]" dot>{{ t(`payments.merchant.status.${account.status}`) }}</AppBadge>
                                <AppBadge v-if="account.mode" :tone="account.mode === 'live' ? 'brand' : 'outline'">{{ t(`payments.merchant.mode.${account.mode}`) }}</AppBadge>
                            </h2>
                            <p class="mt-1 text-[12.5px] text-muted">
                                {{ account.gateway_label }}
                                <template v-if="account.hint"> · <span dir="ltr">{{ t('payments.merchant.store', { hint: account.hint }) }}</span></template>
                                · {{ t('payments.merchant.currency', { currency: account.currency }) }}
                                <template v-if="account.approved_at"> · {{ t('payments.merchant.approved', { date: formatDate(account.approved_at) }) }}</template>
                            </p>
                        </div>
                        <div v-if="data.can_manage" class="flex flex-wrap gap-2">
                            <AppButton size="sm" variant="ghost" :icon="Plug" :loading="busy === `${account.id}:test`" @click="run(account, 'test')">{{ t('payments.merchant.test') }}</AppButton>
                            <AppButton size="sm" :icon="Pencil" @click="openChange(account)">{{ t('payments.merchant.change') }}</AppButton>
                            <AppButton v-if="account.status === 'active'" size="sm" variant="danger-soft" :icon="Power" :loading="busy === `${account.id}:disable`" @click="withReason(account, 'disable')">{{ t('payments.merchant.disable') }}</AppButton>
                            <AppButton v-else-if="account.status === 'disabled' && account.hint" size="sm" :icon="Power" @click="askPassword(account, 'enable')">{{ t('payments.merchant.enable') }}</AppButton>
                        </div>
                    </div>

                    <div v-if="account.pending" class="border-b border-line bg-warn-soft/40 px-5 py-4">
                        <p class="text-[13px] font-semibold text-fg">{{ t('payments.merchant.pending_title') }}</p>
                        <p class="mt-0.5 text-[13px] text-fg-2">
                            {{ t('payments.merchant.pending_text', { person: account.pending.by ?? '—', date: formatDateTime(account.pending.at), mode: t(`payments.merchant.mode.${account.pending.mode}`), hint: account.pending.hint }) }}
                        </p>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            {{ account.pending.activates_at ? t('payments.merchant.pending_on', { date: formatDateTime(account.pending.activates_at) }) : t('payments.merchant.pending_after_approval') }}
                            <template v-if="account.pending.by_me"> {{ t('payments.merchant.pending_yours') }}</template>
                        </p>
                        <div v-if="data.can_manage" class="mt-3 flex flex-wrap gap-2">
                            <AppButton v-if="account.can_approve" size="sm" variant="primary" :icon="Check" @click="askPassword(account, 'approve')">{{ t('payments.merchant.approve') }}</AppButton>
                            <AppButton size="sm" variant="danger-soft" :icon="X" :loading="busy === `${account.id}:reject`" @click="withReason(account, 'reject')">{{ t('payments.merchant.reject') }}</AppButton>
                        </div>
                    </div>

                    <p v-if="account.checked_at" class="px-5 py-3 text-[12.5px]" :class="account.check_result === 'ok' ? 'text-muted' : 'text-bad'">
                        {{ account.check_result === 'ok' ? t('payments.merchant.check_ok', { date: formatDateTime(account.checked_at) }) : t('payments.merchant.check_failed', { date: formatDateTime(account.checked_at) }) }}
                    </p>
                </section>

                <div v-if="data.can_manage && unconnected.length" class="flex flex-wrap gap-2">
                    <AppButton v-for="gateway in unconnected" :key="gateway.key" variant="primary" :icon="CreditCard" @click="openConnect(gateway)">
                        {{ t('payments.merchant.connect', { gateway: gateway.label }) }}
                    </AppButton>
                </div>
            </div>
        </template>

        <!-- Connect or change -->
        <AppDialog
            :open="form.open"
            :title="form.account ? t('payments.dialog.change_title', { gateway: form.account.gateway_label }) : t('payments.dialog.connect_title')"
            :description="t('payments.dialog.text')"
            :icon="CreditCard"
            size="lg"
            @close="form.open = false"
        >
            <form id="merchant-account" class="space-y-4" novalidate @submit.prevent="submitForm">
                <AppField :label="t('payments.dialog.label')" :error="form.errors.label">
                    <template #default="{ id, invalid }">
                        <input :id="id" v-model="form.label" maxlength="100" class="field-input" :placeholder="t('payments.dialog.label_placeholder')" :aria-invalid="invalid || undefined" />
                    </template>
                </AppField>

                <div v-if="form.account" class="flex items-start justify-between gap-4 rounded-xl border border-line px-4 py-3">
                    <div>
                        <p class="text-[13.5px] font-medium text-fg">{{ t('payments.dialog.new_details') }}</p>
                        <p class="text-[12.5px] text-muted">{{ t('payments.dialog.new_details_hint') }}</p>
                    </div>
                    <AppSwitch v-model="form.details" :label="t('payments.dialog.new_details')" />
                </div>

                <template v-if="form.details">
                    <div>
                        <AppSegmented v-model="form.mode" block :label="t('payments.dialog.mode')" :options="modeOptions" />
                        <p v-if="!data?.live_allowed" class="mt-1.5 text-[12.5px] text-muted">{{ t('payments.dialog.live_closed') }}</p>
                        <p v-if="form.errors.mode" class="mt-1.5 text-[12.5px] text-bad" role="alert">{{ form.errors.mode }}</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppField v-for="field in formGateway?.fields ?? []" :key="field.key" :label="t(`payments.dialog.fields.${field.key}`)" :error="form.errors[`credentials.${field.key}`]">
                            <template #default="{ id, invalid }">
                                <input
                                    :id="id"
                                    v-model="form.credentials[field.key]"
                                    :type="field.secret ? 'password' : 'text'"
                                    autocomplete="off"
                                    dir="ltr"
                                    spellcheck="false"
                                    class="field-input"
                                    :aria-invalid="invalid || undefined"
                                />
                            </template>
                        </AppField>
                    </div>
                </template>

                <AppField :label="t('payments.dialog.current_password')" :hint="t('payments.dialog.current_password_hint')" :error="form.errors.current_password">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.password" type="password" autocomplete="current-password" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>

                <p v-if="form.errors.form" class="rounded-xl bg-bad-soft px-4 py-3 text-[13px] text-bad" role="alert">{{ form.errors.form }}</p>
            </form>
            <template #footer>
                <AppButton @click="form.open = false">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" form="merchant-account" variant="primary" :loading="form.saving">
                    {{ form.details ? t('payments.dialog.save') : t('payments.dialog.save_name') }}
                </AppButton>
            </template>
        </AppDialog>

        <!-- Approve or turn on: the person's password -->
        <AppDialog
            :open="confirm.open"
            :title="t(`payments.password.${confirm.action}_title`)"
            :description="confirm.account ? t(`payments.password.${confirm.action}_text`, { hint: confirm.action === 'approve' ? confirm.account.pending?.hint : confirm.account.hint }) : ''"
            :icon="ShieldCheck"
            size="sm"
            @close="confirm.open = false"
        >
            <form id="merchant-password" novalidate @submit.prevent="submitPassword">
                <AppField :label="t('payments.dialog.current_password')" :error="confirm.error">
                    <template #default="{ id, invalid }">
                        <input :id="id" v-model="confirm.password" type="password" autocomplete="current-password" class="field-input" :aria-invalid="invalid || undefined" />
                    </template>
                </AppField>
            </form>
            <template #footer>
                <AppButton @click="confirm.open = false">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" form="merchant-password" variant="primary" :loading="confirm.saving">{{ t('payments.password.confirm') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
