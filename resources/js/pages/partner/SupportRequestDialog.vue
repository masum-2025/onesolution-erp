<script setup>
import { reactive, ref, watch } from 'vue';
import { LifeBuoy } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import { api } from '@/lib/http';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Ask a client for read-only access. The client sees the reason, the
 * severity and the time asked for, and decides (unless its own rule
 * approves this severity automatically).
 */
const props = defineProps({ open: Boolean });
const emit = defineEmits(['close', 'requested']);

const SEVERITIES = ['critical', 'high', 'normal', 'low'];
const MINUTES = [15, 30, 60, 120, 240, 480];
const clients = ref(null);
const loadError = ref(null);
const form = reactive({ organization_id: '', severity: 'normal', minutes: 60, reason: '' });
const errors = ref({});
const saving = ref(false);

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        Object.assign(form, { organization_id: '', severity: 'normal', minutes: 60, reason: '' });
        errors.value = {};
        loadError.value = null;
        if (clients.value) return;
        try {
            clients.value = (await api('/api/partner/organizations', { query: { per_page: 100 } })).data;
        } catch (error) {
            loadError.value = error;
        }
    },
);

async function submit() {
    errors.value = {};
    if (!form.organization_id) errors.value.organization_id = t('trust.partner.client_required');
    if (form.reason.trim().length < 10) errors.value.reason = t('trust.partner.reason_short');
    if (Object.keys(errors.value).length) return;

    saving.value = true;
    try {
        const response = await api('/api/partner/support-grants', { method: 'POST', body: { ...form, reason: form.reason.trim() } });
        toast.success(response.message);
        emit('requested', response.data);
        emit('close');
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([key, messages]) => [key, messages[0]]));
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('trust.partner.request_title')" :description="t('trust.partner.request_text')" :icon="LifeBuoy" @close="emit('close')">
        <ErrorState v-if="loadError" compact :error="loadError" />
        <div v-else-if="!clients" class="space-y-3" role="status" :aria-label="t('core.states.loading')">
            <div v-for="n in 4" :key="n" class="skeleton h-10 w-full" />
        </div>
        <form v-else id="support-request-form" class="space-y-4" novalidate @submit.prevent="submit">
            <AppField :label="t('trust.partner.client')" :error="errors.organization_id">
                <template #default="{ id, invalid, describedby }">
                    <select :id="id" v-model="form.organization_id" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedby">
                        <option value="" disabled>{{ t('trust.partner.choose_client') }}</option>
                        <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.display_name }}</option>
                    </select>
                </template>
            </AppField>
            <div class="grid gap-4 sm:grid-cols-2">
                <AppField :label="t('trust.partner.severity')" :error="errors.severity">
                    <template #default="{ id }">
                        <select :id="id" v-model="form.severity" class="field-input">
                            <option v-for="severity in SEVERITIES" :key="severity" :value="severity">{{ t(`trust.support.severity.${severity}`) }}</option>
                        </select>
                    </template>
                </AppField>
                <AppField :label="t('trust.partner.minutes')" :hint="t('trust.partner.minutes_hint')" :error="errors.minutes">
                    <template #default="{ id, describedby }">
                        <select :id="id" v-model.number="form.minutes" class="field-input" :aria-describedby="describedby">
                            <option v-for="minutes in MINUTES" :key="minutes" :value="minutes">{{ t('trust.support.duration', { minutes: formatNumber(minutes) }) }}</option>
                        </select>
                    </template>
                </AppField>
            </div>
            <AppField :label="t('trust.partner.reason')" :hint="t('trust.partner.reason_hint')" :error="errors.reason">
                <template #default="{ id, invalid, describedby }">
                    <textarea :id="id" v-model="form.reason" rows="3" maxlength="1000" class="field-input" :placeholder="t('trust.partner.reason_placeholder')" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                </template>
            </AppField>
        </form>
        <template #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton type="submit" form="support-request-form" variant="primary" :loading="saving" :disabled="!clients">{{ t('trust.partner.send') }}</AppButton>
        </template>
    </AppDialog>
</template>
