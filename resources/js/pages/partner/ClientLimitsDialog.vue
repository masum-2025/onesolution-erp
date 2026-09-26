<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Gauge, Lock } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import { api } from '@/lib/http';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * A per-client limit deal. Empty = follow the plan. The client itself can
 * never change these (the server keeps them out of its rule editor).
 */
const props = defineProps({
    open: Boolean,
    client: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const LIMITS = ['users', 'branches', 'storage_mb'];
const data = ref(null);
const loadError = ref(null);
const form = reactive({ users: '', branches: '', storage_mb: '', reason: '' });
const errors = ref({});
const saving = ref(false);
const canEdit = computed(() => data.value?.can_edit === true);

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        data.value = null;
        loadError.value = null;
        errors.value = {};
        try {
            data.value = (await api(`/api/partner/clients/${props.client.id}/limits`)).data;
            LIMITS.forEach((limit) => (form[limit] = data.value.deal[limit] ?? ''));
            form.reason = '';
        } catch (error) {
            loadError.value = error;
        }
    },
);

function planValue(limit) {
    // What applies without a deal: the effective value when there is no deal, else unknown here.
    const value = data.value?.deal[limit] === null ? data.value.effective[limit] : null;
    return value === null ? t('partner.limits.unlimited') : formatNumber(value);
}

async function save() {
    errors.value = {};
    if (form.reason.trim().length < 5) {
        errors.value.reason = t('core.confirm.reason_short');
        return;
    }
    saving.value = true;
    try {
        const limits = Object.fromEntries(LIMITS.map((limit) => [limit, form[limit] === '' || form[limit] === null ? null : Number(form[limit])]));
        const response = await api(`/api/partner/clients/${props.client.id}/limits`, { method: 'PUT', body: { limits, reason: form.reason.trim() } });
        toast.success(response.message);
        emit('close');
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([key, messages]) => [key.replace('limits.', ''), messages[0]]));
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('partner.limits.title', { name: client?.display_name ?? '' })" :description="t('partner.limits.text')" :icon="Gauge" @close="emit('close')">
        <ErrorState v-if="loadError" compact :error="loadError" />
        <div v-else-if="!data" class="space-y-3" role="status" :aria-label="t('core.states.loading')">
            <div v-for="n in 3" :key="n" class="skeleton h-10 w-full" />
        </div>
        <form v-else id="limits-form" class="space-y-4" novalidate @submit.prevent="save">
            <p v-if="!canEdit" class="flex items-center gap-2 rounded-xl bg-subtle p-3 text-[12.5px] text-fg-2">
                <Lock class="size-4 shrink-0 text-muted" aria-hidden="true" />{{ t('partner.limits.read_only') }}
            </p>
            <AppField
                v-for="limit in LIMITS"
                :key="limit"
                :label="t(`partner.limits.${limit}`)"
                :hint="`${data.usage[limit] === null ? '' : t('partner.limits.used', { used: formatNumber(data.usage[limit]) }) + ' · '}${data.deal[limit] === null ? t('partner.limits.follows_plan', { value: planValue(limit) }) : ''}`"
                :error="errors[limit]"
                optional
            >
                <template #default="{ id, invalid, describedby }">
                    <input :id="id" v-model="form[limit]" type="number" min="0" inputmode="numeric" class="field-input" :disabled="!canEdit" :placeholder="planValue(limit)" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                </template>
            </AppField>
            <AppField v-if="canEdit" :label="t('core.confirm.reason')" :hint="t('core.confirm.reason_hint')" :error="errors.reason">
                <template #default="{ id, invalid, describedby }">
                    <input :id="id" v-model="form.reason" class="field-input" maxlength="500" :placeholder="t('partner.limits.reason_placeholder')" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                </template>
            </AppField>
        </form>
        <template #footer>
            <AppButton @click="emit('close')">{{ canEdit ? t('core.actions.cancel') : t('core.actions.close') }}</AppButton>
            <AppButton v-if="canEdit" type="submit" form="limits-form" variant="primary" :loading="saving">{{ t('partner.limits.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
