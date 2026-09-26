<script setup>
import { computed, reactive, ref } from 'vue';
import { Copy, KeyRound, Plus, Trash2 } from 'lucide-vue-next';
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
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Partner console: API keys for the partner's own systems (website, CRM)
 * to create clients, add people and change plans. Owners only; a key is
 * shown once.
 */
const keys = useResource(() => api('/api/partner/api-keys'));
const rows = computed(() => keys.data.value?.data ?? []);
const scopes = computed(() => keys.data.value?.scopes ?? []);
const baseUrl = computed(() => keys.data.value?.base_url ?? '/api/partner/v1');

const creating = ref(false);
const form = reactive({ name: '', scopes: [] });
const created = ref(null);
const errors = ref({});
const saving = ref(false);

const TONES = { active: 'ok', revoked: 'neutral', expired: 'outline' };

function openCreate() {
    Object.assign(form, { name: '', scopes: ['clients:read'] });
    created.value = null;
    errors.value = {};
    creating.value = true;
}

async function create() {
    errors.value = {};
    saving.value = true;
    try {
        created.value = (await api('/api/partner/api-keys', { method: 'POST', body: { name: form.name.trim(), scopes: form.scopes } })).data;
        keys.reload();
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([field, messages]) => [field.split('.')[0], messages[0]]));
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(t('partner.api.copied'));
    } catch {
        // Clipboard can be blocked; the key stays selectable on screen.
    }
}

async function revoke(key) {
    const answer = await confirmAction({ title: t('partner.api.revoke_title', { name: key.name }), message: t('partner.api.revoke_text'), danger: true, confirmLabel: t('partner.api.revoke') });
    if (!answer) return;
    try {
        toast.success((await api(`/api/partner/api-keys/${key.id}`, { method: 'DELETE' })).message);
        keys.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

const example = computed(
    () => `curl -X POST ${baseUrl.value}/clients \\
  -H "Authorization: Bearer osk_…" \\
  -H "Idempotency-Key: signup-4711" \\
  -H "Content-Type: application/json" \\
  -d '{"name":{"en":"Sunrise School"},"sector_key":"school","plan":"business",
       "owner_email":"head@sunrise.test","owner_name":"Head Teacher","country_code":"BD"}'`,
);
</script>

<template>
    <div>
        <PageHeader :title="t('partner.api.title')" :description="t('partner.api.text')">
            <template #actions>
                <AppButton variant="primary" :icon="Plus" @click="openCreate">{{ t('partner.api.new') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card mb-6">
            <SkeletonRows v-if="keys.loading.value && !keys.data.value" :rows="3" />
            <ErrorState v-else-if="keys.error.value" compact :error="keys.error.value" @retry="keys.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="KeyRound" :title="t('partner.api.empty_title')" :text="t('partner.api.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="key in rows" :key="key.id" class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                            {{ key.name }}
                            <code class="font-mono text-[12px] text-muted" dir="ltr">{{ key.prefix }}_••••</code>
                            <AppBadge :tone="TONES[key.status]" dot>{{ t(`partner.api.status.${key.status}`) }}</AppBadge>
                        </p>
                        <p class="mt-1 flex flex-wrap gap-1">
                            <AppBadge v-for="scope in key.scopes" :key="scope" tone="outline">{{ scope }}</AppBadge>
                        </p>
                        <p class="mt-1 text-[12px] text-muted">
                            {{ t('partner.api.meta', { by: key.created_by ?? '—', until: formatDate(key.expires_at) }) }} ·
                            {{ key.last_used_at ? t('partner.api.used', { date: formatDateTime(key.last_used_at) }) : t('partner.api.never_used') }}
                        </p>
                    </div>
                    <AppButton v-if="key.status === 'active'" size="sm" variant="danger-soft" :icon="Trash2" @click="revoke(key)">{{ t('partner.api.revoke') }}</AppButton>
                </li>
            </ul>
        </section>

        <!-- How to use it -->
        <section class="card space-y-3 p-5 text-[13px] text-fg-2">
            <h2 class="text-[15px] font-semibold text-fg">{{ t('partner.api.how_title') }}</h2>
            <p>{{ t('partner.api.how_text', { rate: formatNumber(keys.data.value?.rate_per_minute ?? 60) }) }}</p>
            <ul class="list-inside list-disc space-y-1 font-mono text-[12.5px]" dir="ltr">
                <li>GET {{ baseUrl }}/clients · clients:read</li>
                <li>GET {{ baseUrl }}/clients/{id} · clients:read</li>
                <li>POST {{ baseUrl }}/clients · clients:write</li>
                <li>POST {{ baseUrl }}/clients/{id}/members · members:write</li>
                <li>GET {{ baseUrl }}/plans · plans:read</li>
                <li>PUT {{ baseUrl }}/clients/{id}/plan · subscriptions:write</li>
            </ul>
            <pre class="overflow-x-auto rounded-xl bg-subtle p-3 font-mono text-[12px] text-fg" dir="ltr">{{ example }}</pre>
            <p class="text-[12.5px] text-muted">{{ t('partner.api.idempotency') }}</p>
        </section>

        <AppDialog :open="creating" :title="t('partner.api.new')" :description="t('partner.api.new_text')" :icon="KeyRound" @close="creating = false">
            <div v-if="created" class="space-y-3">
                <p class="text-[13px] text-fg-2">{{ t('partner.api.once') }}</p>
                <div class="flex items-center gap-2">
                    <code class="flex-1 overflow-x-auto rounded-xl bg-subtle px-3 py-3 font-mono text-[12.5px] text-fg" dir="ltr">{{ created.key }}</code>
                    <AppButton size="icon" :icon="Copy" :aria-label="t('partner.api.copy')" @click="copy(created.key)" />
                </div>
                <p class="text-[12.5px] text-muted">{{ t('partner.api.until', { date: formatDate(created.expires_at) }) }}</p>
            </div>
            <form v-else id="api-key-form" class="space-y-4" novalidate @submit.prevent="create">
                <AppField :label="t('partner.api.name')" :hint="t('partner.api.name_hint')" :error="errors.name">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.name" class="field-input" maxlength="80" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
                <fieldset class="space-y-2">
                    <legend class="text-[13px] font-medium text-fg">{{ t('partner.api.scopes') }}</legend>
                    <label v-for="scope in scopes" :key="scope" class="flex items-start gap-2.5 text-[13px] text-fg-2">
                        <input v-model="form.scopes" type="checkbox" :value="scope" class="mt-0.5 size-4 rounded accent-brand" />
                        <span><code class="font-mono text-fg" dir="ltr">{{ scope }}</code> · {{ t(`partner.api.scope.${scope.replace(':', '_')}`) }}</span>
                    </label>
                    <p v-if="errors.scopes" class="text-[12.5px] text-bad">{{ errors.scopes }}</p>
                </fieldset>
            </form>
            <template #footer>
                <AppButton @click="creating = false">{{ created ? t('core.actions.close') : t('core.actions.cancel') }}</AppButton>
                <AppButton v-if="!created" type="submit" form="api-key-form" variant="primary" :loading="saving" :disabled="!form.name.trim() || !form.scopes.length">{{ t('partner.api.create') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
