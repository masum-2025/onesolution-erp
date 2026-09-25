<script setup>
import { ref, watch } from 'vue';
import { ArrowRightLeft, Info } from 'lucide-vue-next';
import AppDialog from '@/components/AppDialog.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import OrgPicker from '@/components/OrgPicker.vue';
import { api } from '@/lib/http';
import { invalidate } from '@/lib/cache';
import { t } from '@/lib/i18n';
import { toast } from '@/lib/toast';

/**
 * Move a unit under another parent. Valid parents come from the
 * allowed_parents rule; the server checks cycles, depth and partner again.
 */
const props = defineProps({
    open: Boolean,
    organization: { type: Object, default: null },
    allow: { type: Function, default: null },
});

const emit = defineEmits(['close', 'moved']);

const target = ref(null);
const reason = ref('');
const errors = ref({});
const saving = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        target.value = null;
        reason.value = '';
        errors.value = {};
    },
);

async function submit() {
    errors.value = {};
    if (!target.value) errors.value.new_parent_id = t('orgs.move.parent_required');
    if (reason.value.trim().length < 5) errors.value.reason = t('core.confirm.reason_short');
    if (Object.keys(errors.value).length) return;

    saving.value = true;
    try {
        const { data } = await api(`/api/organizations/${props.organization.id}/move`, {
            method: 'POST',
            body: { new_parent_id: target.value, reason: reason.value.trim() },
        });
        invalidate('organizations');
        toast.success(t('orgs.move.done', { name: data.display_name }));
        emit('moved', data);
        emit('close');
    } catch (error) {
        errors.value = error.status === 422 && Object.keys(error.errors).length
            ? Object.fromEntries(Object.entries(error.errors).map(([key, messages]) => [key, messages[0]]))
            : { form: error.message };
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('orgs.move.title', { name: organization?.display_name ?? '' })" :description="t('orgs.move.text')" :icon="ArrowRightLeft" @close="emit('close')">
        <form id="move-form" class="space-y-4" novalidate @submit.prevent="submit">
            <p v-if="errors.form" class="rounded-xl border border-bad/20 bg-bad-soft px-3.5 py-2.5 text-[13px] text-fg-2" role="alert">{{ errors.form }}</p>

            <AppField :label="t('orgs.move.new_parent')" :error="errors.new_parent_id">
                <template #default="{ id, invalid }">
                    <OrgPicker v-model="target" :input-id="id" :invalid="invalid" :allow="allow" :label="t('orgs.move.new_parent')" />
                </template>
            </AppField>

            <AppField :label="t('core.confirm.reason')" :hint="t('core.confirm.reason_hint')" :error="errors.reason">
                <template #default="{ id, invalid, describedby }">
                    <textarea :id="id" v-model="reason" rows="3" maxlength="500" class="field-input" :placeholder="t('orgs.move.reason_placeholder')" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                </template>
            </AppField>

            <p class="flex items-start gap-2 rounded-xl bg-subtle px-3.5 py-3 text-[12.5px] leading-relaxed text-muted">
                <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                {{ t('orgs.move.note') }}
            </p>
        </form>

        <template #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton type="submit" form="move-form" variant="primary" :loading="saving">{{ t('orgs.move.submit') }}</AppButton>
        </template>
    </AppDialog>
</template>
