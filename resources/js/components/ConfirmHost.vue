<script setup>
import { computed, ref, watch } from 'vue';
import { ShieldAlert, TriangleAlert } from 'lucide-vue-next';
import AppDialog from './AppDialog.vue';
import AppButton from './AppButton.vue';
import AppField from './AppField.vue';
import { closeDialog, dialog } from '@/lib/dialogs';
import { t } from '@/lib/i18n';

const reason = ref('');
const typed = ref('');
const checked = ref(false);
const touched = ref(false);

watch(
    () => dialog.open,
    (open) => {
        if (!open) return;
        reason.value = '';
        typed.value = '';
        checked.value = !!dialog.options.checkbox?.checked;
        touched.value = false;
    },
);

const reasonError = computed(() => {
    if (!touched.value || dialog.options.reason === 'none') return null;
    const length = reason.value.trim().length;
    if (dialog.options.reason === 'required' && length < 5) return t('core.confirm.reason_short');
    if (length > 500) return t('core.confirm.reason_long');
    return null;
});

const typedOk = computed(() => !dialog.options.typeToConfirm || typed.value.trim() === dialog.options.typeToConfirm);

function submit() {
    touched.value = true;
    if (reasonError.value || !typedOk.value) return;
    if (dialog.options.reason === 'required' && reason.value.trim().length < 5) return;
    closeDialog({ reason: reason.value.trim() || null, checked: checked.value });
}
</script>

<template>
    <AppDialog
        :open="dialog.open"
        layer="z-[60]"
        :title="dialog.options.title ?? ''"
        :description="dialog.options.message ?? ''"
        :icon="dialog.options.danger ? TriangleAlert : ShieldAlert"
        :tone="dialog.options.danger ? 'bad' : 'brand'"
        size="sm"
        @close="closeDialog(null)"
    >
        <form id="confirm-form" class="space-y-4" @submit.prevent="submit">
            <ul v-if="dialog.options.details?.length" class="space-y-1.5 rounded-xl border border-line bg-subtle/60 p-3 text-[13px] text-fg-2">
                <li v-for="line in dialog.options.details" :key="line" class="flex gap-2">
                    <span class="mt-2 size-1 shrink-0 rounded-full bg-faint" aria-hidden="true" />
                    <span>{{ line }}</span>
                </li>
            </ul>

            <AppField
                v-if="dialog.options.reason !== 'none'"
                :label="dialog.options.reasonLabel ?? t('core.confirm.reason')"
                :hint="t('core.confirm.reason_hint')"
                :error="reasonError"
                :optional="dialog.options.reason === 'optional'"
            >
                <template #default="{ id, invalid, describedby }">
                    <textarea
                        :id="id"
                        v-model="reason"
                        data-autofocus
                        rows="3"
                        maxlength="500"
                        class="field-input"
                        :aria-invalid="invalid || undefined"
                        :aria-describedby="describedby"
                        :placeholder="dialog.options.reasonPlaceholder ?? t('core.confirm.reason_placeholder')"
                        @blur="touched = true"
                    />
                </template>
            </AppField>

            <label v-if="dialog.options.checkbox" class="flex items-start gap-2.5 text-[13.5px] text-fg-2">
                <input v-model="checked" type="checkbox" class="mt-0.5 size-4 rounded accent-brand" />
                <span>
                    {{ dialog.options.checkbox.label }}
                    <span v-if="dialog.options.checkbox.hint" class="block text-[12.5px] text-muted">{{ dialog.options.checkbox.hint }}</span>
                </span>
            </label>

            <AppField
                v-if="dialog.options.typeToConfirm"
                :label="t('core.confirm.type_to_confirm', { word: dialog.options.typeToConfirm })"
                :error="touched && !typedOk ? t('core.confirm.type_mismatch') : null"
            >
                <template #default="{ id, invalid }">
                    <input :id="id" v-model="typed" class="field-input font-mono" autocomplete="off" spellcheck="false" :aria-invalid="invalid || undefined" />
                </template>
            </AppField>
        </form>

        <template #footer>
            <AppButton @click="closeDialog(null)">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton type="submit" form="confirm-form" :variant="dialog.options.danger ? 'danger' : 'primary'" :disabled="!typedOk">
                {{ dialog.options.confirmLabel ?? t('core.actions.confirm') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
