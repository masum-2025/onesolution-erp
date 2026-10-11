<script setup>
import { ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { t } from '@/lib/i18n';

/**
 * Asking for a reason before something that undoes money (cancel a bill,
 * void a receipt, refuse a request). At least three letters.
 */
const props = defineProps({
    open: Boolean,
    title: { type: String, required: true },
    text: { type: String, default: '' },
    label: { type: String, default: '' },
    confirmLabel: { type: String, required: true },
    busy: Boolean,
});
const emit = defineEmits(['close', 'submit']);
const reason = ref('');
watch(() => props.open, (open) => {
    if (open) reason.value = '';
});
</script>

<template>
    <AppDialog :open="open" :title="title" :description="text" tone="warn" @close="emit('close')">
        <AppField v-slot="{ id }" :label="label || t('education_fees.reason')">
            <textarea :id="id" v-model="reason" rows="3" class="field-input" maxlength="300" />
        </AppField>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education_fees.cancel') }}</AppButton>
            <AppButton variant="danger" :loading="busy" :disabled="reason.trim().length < 3" @click="emit('submit', reason.trim())">{{ confirmLabel }}</AppButton>
        </template>
    </AppDialog>
</template>
