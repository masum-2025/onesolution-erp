<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Check, Copy, DoorOpen, Printer, UserPlus, X } from 'lucide-vue-next';
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
import { formatDate, formatRelative } from '@/lib/format';
import { confirmAction } from '@/lib/dialogs';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * The client's side of its portal (Phase 5C-4): who waits for a decision,
 * who sees what, open invitations, and inviting someone to one record.
 */
const org = currentOrganization();
const base = `/api/organizations/${org.id}/portal`;
const portal = useResource(() => api(base).then((response) => response.data));
const data = computed(() => portal.data.value);
const waiting = computed(() => (data.value?.links ?? []).filter((link) => link.status === 'pending'));
const active = computed(() => (data.value?.links ?? []).filter((link) => link.status === 'active'));

const busy = ref(null);

async function decide(link, action) {
    let reason = null;
    if (action !== 'approve') {
        const confirmed = await confirmAction({
            title: action === 'revoke' ? t('portal.admin.revoke_title', { member: link.member, record: link.record ?? '' }) : t('portal.admin.reject_title'),
            message: action === 'revoke' ? t('portal.admin.revoke_text') : '',
            reason: 'optional',
            reasonLabel: t('portal.admin.reason'),
            confirmLabel: action === 'revoke' ? t('portal.admin.revoke') : t('portal.admin.reject'),
            danger: true,
        });
        if (!confirmed) return;
        reason = confirmed.reason || null;
    }

    busy.value = link.id;
    try {
        const response = await api(`${base}/links/${link.id}/${action}`, { method: 'POST', body: action === 'approve' ? undefined : { reason } });
        toast.success(response.message);
        await portal.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function cancelInvitation(invitation) {
    const confirmed = await confirmAction({
        title: t('portal.admin.cancel_invitation_title'),
        message: t('portal.admin.cancel_invitation_text'),
        confirmLabel: t('portal.admin.cancel_invitation'),
        danger: true,
    });
    if (!confirmed) return;

    busy.value = invitation.id;
    try {
        const response = await api(`${base}/invitations/${invitation.id}`, { method: 'DELETE' });
        toast.success(response.message);
        await portal.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

// ── Inviting ──
const dialog = reactive({ open: false, kind: null, term: '', results: [], searching: false, record: null, relation: null, name: '', channel: 'mail', email: '', phone: '', send: true, errors: {}, saving: false });
const created = ref(null);
const kind = computed(() => data.value?.kinds.find((candidate) => candidate.key === dialog.kind) ?? null);

function openInvite() {
    const first = data.value.kinds[0];
    Object.assign(dialog, { open: true, kind: first.key, term: '', results: [], record: null, relation: first.relations[0], name: '', channel: 'mail', email: '', phone: '', send: true, errors: {}, saving: false });
    search();
}

let searchTimer;
watch(() => dialog.term, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(search, 250);
});
watch(() => dialog.kind, () => {
    dialog.record = null;
    dialog.relation = kind.value?.relations[0] ?? null;
    if (dialog.open) search();
});

async function search() {
    if (!dialog.kind) return;
    dialog.searching = true;
    try {
        dialog.results = (await api(`${base}/kinds/${dialog.kind}/records`, { query: { q: dialog.term.trim() } })).data;
    } catch (error) {
        dialog.results = [];
        toast.error(error.message);
    } finally {
        dialog.searching = false;
    }
}

async function invite() {
    dialog.errors = {};
    if (!dialog.record) dialog.errors.record = t('portal.admin.dialog.record_required');
    if (dialog.name.trim().length < 2) dialog.errors.name = t('portal.admin.dialog.name_required');
    if (Object.keys(dialog.errors).length) return;

    dialog.saving = true;
    try {
        const response = await api(`${base}/invitations`, {
            method: 'POST',
            body: {
                subject_type: dialog.kind,
                subject_id: dialog.record.id,
                relation: dialog.relation,
                name: dialog.name.trim(),
                channel: dialog.channel,
                ...(dialog.channel === 'mail' ? { email: dialog.email.trim() } : { phone: dialog.phone.trim(), country_code: org.settings?.country_code ?? null }),
                send: dialog.send,
            },
        });
        dialog.open = false;
        created.value = response.data;
        await portal.reload();
    } catch (error) {
        const field = error.data?.field ?? Object.keys(error.errors ?? {})[0] ?? 'record';
        dialog.errors[field] = error.field?.(field) ?? error.message;
    } finally {
        dialog.saving = false;
    }
}

function printCode() {
    window.print();
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(t('portal.admin.created.copied'));
    } catch {
        toast.error(t('core.errors.server'));
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('portal.admin.title')" :description="t('portal.admin.text')">
            <template v-if="data?.can_manage && data.kinds.length" #actions>
                <AppButton variant="primary" :icon="UserPlus" @click="openInvite">{{ t('portal.admin.invite') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="portal.loading.value && !data" :rows="5" />
        <ErrorState v-else-if="portal.error.value" :error="portal.error.value" @retry="portal.reload()" />

        <template v-else-if="data">
            <EmptyState v-if="!data.kinds.length" :icon="DoorOpen" :title="t('portal.admin.no_kinds_title')" :text="t('portal.admin.no_kinds_text')" />

            <div v-else class="space-y-6">
                <!-- Waiting for a decision -->
                <section class="card">
                    <h2 class="flex items-center gap-2 border-b border-line px-5 py-3.5 text-[14px] font-semibold text-fg">
                        {{ t('portal.admin.waiting') }}
                        <AppBadge v-if="waiting.length" tone="warn">{{ waiting.length }}</AppBadge>
                    </h2>
                    <p v-if="!waiting.length" class="px-5 py-4 text-[13px] text-muted">{{ t('portal.admin.no_waiting') }}</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="link in waiting" :key="link.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                            <div class="min-w-0 flex-1">
                                <p class="text-[13.5px] font-medium text-fg">{{ t('portal.admin.member_sees', { member: link.member, relation: t(`portal.record.relation.${link.relation}`), record: link.record ?? link.kind }) }}</p>
                                <p class="text-[12px] text-muted">{{ link.kind }} · {{ formatRelative(link.since) }}</p>
                            </div>
                            <template v-if="data.can_manage">
                                <AppButton size="sm" variant="primary" :icon="Check" :loading="busy === link.id" @click="decide(link, 'approve')">{{ t('portal.admin.approve') }}</AppButton>
                                <AppButton size="sm" variant="danger-soft" :icon="X" @click="decide(link, 'reject')">{{ t('portal.admin.reject') }}</AppButton>
                            </template>
                        </li>
                    </ul>
                </section>

                <!-- Who sees what -->
                <section class="card">
                    <h2 class="border-b border-line px-5 py-3.5 text-[14px] font-semibold text-fg">{{ t('portal.admin.active') }}</h2>
                    <p v-if="!active.length" class="px-5 py-4 text-[13px] text-muted">{{ t('portal.admin.no_active') }}</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="link in active" :key="link.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                            <div class="min-w-0 flex-1">
                                <p class="text-[13.5px] font-medium text-fg">{{ t('portal.admin.member_sees', { member: link.member, relation: t(`portal.record.relation.${link.relation}`), record: link.record ?? link.kind }) }}</p>
                                <p class="text-[12px] text-muted">{{ link.kind }}</p>
                            </div>
                            <AppButton v-if="data.can_manage" size="sm" variant="danger-soft" :loading="busy === link.id" @click="decide(link, 'revoke')">{{ t('portal.admin.revoke') }}</AppButton>
                        </li>
                    </ul>
                </section>

                <!-- Open invitations -->
                <section class="card">
                    <h2 class="border-b border-line px-5 py-3.5 text-[14px] font-semibold text-fg">{{ t('portal.admin.invitations') }}</h2>
                    <p v-if="!data.invitations.length" class="px-5 py-4 text-[13px] text-muted">{{ t('portal.admin.no_invitations') }}</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="invitation in data.invitations" :key="invitation.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                            <div class="min-w-0 flex-1">
                                <p class="text-[13.5px] font-medium text-fg">{{ t('portal.admin.member_sees', { member: invitation.name, relation: t(`portal.record.relation.${invitation.relation}`), record: invitation.record ?? invitation.kind }) }}</p>
                                <p class="text-[12px] text-muted"><span dir="ltr">{{ t('portal.admin.to', { to: invitation.to }) }}</span> · {{ t('portal.admin.until', { date: formatDate(invitation.expires_at) }) }}</p>
                            </div>
                            <AppButton v-if="data.can_manage" size="sm" variant="ghost" :loading="busy === invitation.id" @click="cancelInvitation(invitation)">{{ t('portal.admin.cancel_invitation') }}</AppButton>
                        </li>
                    </ul>
                </section>
            </div>
        </template>

        <!-- Invite -->
        <AppDialog :open="dialog.open" :title="t('portal.admin.dialog.title')" :description="t('portal.admin.dialog.text')" :icon="UserPlus" size="lg" @close="dialog.open = false">
            <form id="portal-invite" class="space-y-4" novalidate @submit.prevent="invite">
                <AppField v-if="data && data.kinds.length > 1" :label="t('portal.admin.dialog.kind')">
                    <template #default="{ id }">
                        <select :id="id" v-model="dialog.kind" class="field-input">
                            <option v-for="option in data.kinds" :key="option.key" :value="option.key">{{ option.label }}</option>
                        </select>
                    </template>
                </AppField>

                <AppField :label="t('portal.admin.dialog.record')" :error="dialog.errors.record || dialog.errors.subject_id">
                    <template #default="{ id, invalid }">
                        <input :id="id" v-model="dialog.term" type="search" class="field-input" :placeholder="t('portal.admin.dialog.search')" :aria-invalid="invalid || undefined" />
                    </template>
                </AppField>
                <div class="max-h-44 overflow-y-auto rounded-xl border border-line" role="listbox" :aria-label="t('portal.admin.dialog.record')">
                    <p v-if="!dialog.searching && !dialog.results.length" class="px-4 py-3 text-[13px] text-muted">{{ t('portal.admin.dialog.no_results') }}</p>
                    <button
                        v-for="result in dialog.results"
                        :key="result.id"
                        type="button"
                        role="option"
                        :aria-selected="dialog.record?.id === result.id"
                        class="flex w-full items-center justify-between px-4 py-2.5 text-start text-[13.5px] transition hover:bg-subtle"
                        :class="dialog.record?.id === result.id ? 'bg-brand-soft font-medium text-fg' : 'text-fg-2'"
                        @click="dialog.record = result"
                    >
                        {{ result.name }}
                        <Check v-if="dialog.record?.id === result.id" class="size-4 text-brand-text" aria-hidden="true" />
                    </button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField :label="t('portal.admin.dialog.relation')">
                        <template #default="{ id }">
                            <select :id="id" v-model="dialog.relation" class="field-input">
                                <option v-for="relation in kind?.relations ?? []" :key="relation" :value="relation">{{ t(`portal.record.relation.${relation}`) }}</option>
                            </select>
                        </template>
                    </AppField>
                    <AppField :label="t('portal.admin.dialog.name')" :error="dialog.errors.name">
                        <template #default="{ id, invalid }">
                            <input :id="id" v-model="dialog.name" maxlength="150" class="field-input" :aria-invalid="invalid || undefined" />
                        </template>
                    </AppField>
                </div>

                <AppSegmented
                    v-model="dialog.channel"
                    block
                    :label="t('portal.admin.dialog.channel')"
                    :options="[
                        { value: 'mail', label: t('portal.admin.dialog.email') },
                        { value: 'sms', label: t('portal.admin.dialog.phone') },
                    ]"
                />
                <AppField v-if="dialog.channel === 'mail'" :label="t('portal.admin.dialog.email')" :error="dialog.errors.email">
                    <template #default="{ id, invalid }">
                        <input :id="id" v-model="dialog.email" type="email" autocomplete="off" dir="ltr" class="field-input" :aria-invalid="invalid || undefined" />
                    </template>
                </AppField>
                <AppField v-else :label="t('portal.admin.dialog.phone')" :error="dialog.errors.phone">
                    <template #default="{ id, invalid }">
                        <input :id="id" v-model="dialog.phone" type="tel" autocomplete="off" dir="ltr" class="field-input" placeholder="01XXXXXXXXX" :aria-invalid="invalid || undefined" />
                    </template>
                </AppField>

                <div class="flex items-start justify-between gap-4 rounded-xl border border-line px-4 py-3">
                    <div>
                        <p class="text-[13.5px] font-medium text-fg">{{ t('portal.admin.dialog.send') }}</p>
                        <p class="text-[12.5px] text-muted">{{ t('portal.admin.dialog.send_hint') }}</p>
                    </div>
                    <AppSwitch v-model="dialog.send" :label="t('portal.admin.dialog.send')" />
                </div>
            </form>
            <template #footer>
                <AppButton @click="dialog.open = false">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" form="portal-invite" variant="primary" :loading="dialog.saving">{{ t('portal.admin.dialog.create') }}</AppButton>
            </template>
        </AppDialog>

        <!-- The code, shown once -->
        <AppDialog :open="created !== null" :title="t('portal.admin.created.title')" :description="t('portal.admin.created.text')" :icon="Check" @close="created = null">
            <div v-if="created" class="space-y-4">
                <div>
                    <p class="text-[12.5px] font-medium text-muted">{{ t('portal.admin.created.code') }}</p>
                    <div class="mt-1 flex items-center gap-2">
                        <p class="tabular flex-1 rounded-xl bg-subtle px-4 py-3 text-center text-[22px] font-semibold tracking-[0.12em] text-fg" dir="ltr">{{ created.code }}</p>
                        <AppButton size="icon" :icon="Copy" :aria-label="t('portal.admin.created.copy')" @click="copy(created.code)" />
                    </div>
                </div>
                <div>
                    <p class="text-[12.5px] font-medium text-muted">{{ t('portal.admin.created.link') }}</p>
                    <div class="mt-1 flex items-center gap-2">
                        <p class="min-w-0 flex-1 truncate rounded-xl bg-subtle px-4 py-2.5 text-[12.5px] text-fg-2" dir="ltr">{{ created.link }}</p>
                        <AppButton size="icon" :icon="Copy" :aria-label="t('portal.admin.created.copy')" @click="copy(created.link)" />
                    </div>
                </div>
            </div>
            <template #footer>
                <AppButton :icon="Printer" @click="printCode">{{ t('portal.admin.created.print') }}</AppButton>
                <AppButton variant="primary" @click="created = null">{{ t('portal.admin.created.done') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
