<script setup>
import { computed, reactive, ref } from 'vue';
import { Copy, Globe, Lock, Plus, RefreshCw, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDateTime } from '@/lib/format';
import { session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Partner console: custom domains. A domain is added as "waiting for DNS",
 * shows the TXT record to publish, and becomes active after "Check now".
 */
const domains = useResource(() => api('/api/partner/domains').then((response) => response.data));
const clients = useResource(() => api('/api/partner/organizations', { query: { per_page: 100 } }).then((response) => response.data.filter((org) => org.parent_id === null)));
const canEdit = computed(() => session.me?.context?.role === 'owner');

const STATUS_TONES = { pending: 'warn', active: 'ok', disabled: 'neutral' };

const adding = ref(false);
const form = reactive({ host: '', organization_id: '' });
const errors = ref({});
const saving = ref(false);
const checking = ref(null);

function openAdd() {
    Object.assign(form, { host: '', organization_id: '' });
    errors.value = {};
    adding.value = true;
}

async function add() {
    errors.value = {};
    if (!/^([a-z0-9-]+\.)+[a-z]{2,}$/i.test(form.host.trim())) {
        errors.value.host = t('partner.domains.host_hint');
        return;
    }
    saving.value = true;
    try {
        const response = await api('/api/partner/domains', {
            method: 'POST',
            body: { host: form.host.trim(), ...(form.organization_id ? { organization_id: form.organization_id } : {}) },
        });
        toast.success(response.message);
        adding.value = false;
        domains.reload();
    } catch (error) {
        errors.value = { host: error.field('host') ?? error.message };
    } finally {
        saving.value = false;
    }
}

async function verify(domain) {
    checking.value = domain.id;
    try {
        const response = await api(`/api/partner/domains/${domain.id}/verify`, { method: 'POST' });
        toast.success(response.message);
    } catch (error) {
        toast.error(error.message);
    } finally {
        checking.value = null;
        domains.reload();
    }
}

async function remove(domain) {
    const answer = await confirmAction({
        title: t('partner.domains.remove_title', { host: domain.host }),
        message: t('partner.domains.remove_text'),
        reason: 'required',
        danger: true,
        confirmLabel: t('partner.domains.remove'),
    });
    if (!answer) return;
    try {
        const response = await api(`/api/partner/domains/${domain.id}`, { method: 'DELETE', body: { reason: answer.reason } });
        toast.success(response.message);
        domains.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(t('partner.domains.copied'));
    } catch {
        // Clipboard can be blocked; the value stays selectable on screen.
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('partner.domains.title')" :description="t('partner.domains.text')">
            <template #actions>
                <AppButton v-if="canEdit" variant="primary" :icon="Plus" @click="openAdd">{{ t('partner.domains.add') }}</AppButton>
            </template>
        </PageHeader>

        <p v-if="!canEdit" class="mb-4 flex items-center gap-2 rounded-xl bg-subtle p-3.5 text-[13px] text-fg-2">
            <Lock class="size-4 shrink-0 text-muted" aria-hidden="true" />{{ t('partner.domains.read_only') }}
        </p>

        <section class="card">
            <SkeletonRows v-if="domains.loading.value && !domains.data.value" :rows="3" avatar />
            <ErrorState v-else-if="domains.error.value" compact :error="domains.error.value" @retry="domains.reload()" />
            <EmptyState v-else-if="!domains.data.value?.length" :icon="Globe" :title="t('partner.domains.empty_title')" :text="t('partner.domains.empty_text')" compact>
                <AppButton v-if="canEdit" variant="primary" :icon="Plus" @click="openAdd">{{ t('partner.domains.add') }}</AppButton>
            </EmptyState>

            <ul v-else class="divide-y divide-line">
                <li v-for="domain in domains.data.value" :key="domain.id" class="px-4 py-4 sm:px-5">
                    <div class="flex flex-wrap items-center gap-3">
                        <Globe class="size-5 shrink-0 text-muted" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-mono text-[14px] font-medium text-fg" dir="ltr">{{ domain.host }}</span>
                            <span class="block text-[12.5px] text-muted">{{ domain.client?.name ?? t('partner.domains.all_clients') }}</span>
                        </span>
                        <AppBadge :tone="STATUS_TONES[domain.status]" dot>{{ t(`partner.domains.status.${domain.status}`) }}</AppBadge>
                        <AppButton v-if="canEdit && domain.status !== 'active'" size="sm" :icon="RefreshCw" :loading="checking === domain.id" @click="verify(domain)">
                            {{ t('partner.domains.verify') }}
                        </AppButton>
                        <AppButton v-if="canEdit" size="sm" variant="danger-soft" :icon="Trash2" :aria-label="`${t('partner.domains.remove')} ${domain.host}`" @click="remove(domain)" />
                    </div>

                    <div v-if="domain.status !== 'active'" class="mt-3 rounded-xl border border-warn/25 bg-warn-soft/60 p-3.5">
                        <p class="text-[12.5px] font-medium text-fg-2">{{ t('partner.domains.record_title') }}</p>
                        <dl class="mt-2 grid gap-2 text-[12.5px] sm:grid-cols-[auto_1fr_auto]">
                            <template v-for="field in ['type', 'name', 'value']" :key="field">
                                <dt class="text-muted">{{ t(`partner.domains.record_${field}`) }}</dt>
                                <dd class="min-w-0 truncate font-mono text-fg select-all" dir="ltr">{{ domain.record[field] }}</dd>
                                <button v-if="field !== 'type'" type="button" class="flex items-center gap-1 text-[12px] font-medium text-brand-text hover:underline" @click="copy(domain.record[field])">
                                    <Copy class="size-3.5" aria-hidden="true" />{{ t('partner.domains.copy') }}
                                </button>
                                <span v-else />
                            </template>
                        </dl>
                        <p v-if="domain.last_checked_at" class="mt-2 text-[12px] text-muted">{{ t('partner.domains.last_checked', { date: formatDateTime(domain.last_checked_at) }) }}</p>
                    </div>
                    <p v-else-if="domain.verified_at" class="mt-1 ps-8 text-[12px] text-muted">{{ t('partner.domains.verified', { date: formatDateTime(domain.verified_at) }) }}</p>
                </li>
            </ul>
        </section>

        <AppDialog :open="adding" :title="t('partner.domains.add_title')" :icon="Globe" @close="adding = false">
            <form id="domain-form" class="space-y-4" novalidate @submit.prevent="add">
                <AppField :label="t('partner.domains.host')" :hint="t('partner.domains.host_hint')" :error="errors.host">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.host" data-autofocus class="field-input font-mono" dir="ltr" placeholder="erp.yourcompany.com" autocapitalize="off" spellcheck="false" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
                <AppField :label="t('partner.domains.client')" :hint="t('partner.domains.client_hint')" optional>
                    <template #default="{ id, describedby }">
                        <select :id="id" v-model="form.organization_id" class="field-input" :aria-describedby="describedby">
                            <option value="">{{ t('partner.domains.all_clients') }}</option>
                            <option v-for="client in clients.data.value ?? []" :key="client.id" :value="client.id">{{ client.display_name }}</option>
                        </select>
                    </template>
                </AppField>
            </form>
            <template #footer>
                <AppButton @click="adding = false">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" form="domain-form" variant="primary" :loading="saving">{{ t('partner.domains.add') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
