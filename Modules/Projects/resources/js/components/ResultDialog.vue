<script setup>
import { reactive, ref, watch } from 'vue';
import { Send } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Send the audit's result back to the connected client: official days,
 * grade, certificate number and file. The client checks it and accepts it
 * into its own register; the request is marked done.
 */
const props = defineProps({
    open: Boolean,
    project: { type: Object, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'sent']);

const form = reactive({ last_audit_on: '', expires_on: '', result: '', certificate_no: '', note: '' });
const file = ref(null);
const errors = ref({});
const busy = ref(false);
watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, { last_audit_on: '', expires_on: '', result: '', certificate_no: '', note: '' });
    file.value = null;
    errors.value = {};
});

async function send() {
    busy.value = true;
    errors.value = {};
    try {
        await props.calls.sendResult(props.project.id, { ...form }, file.value);
        toast.success(t('projects.result.sent'));
        emit('sent');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog :open="open" :title="t('projects.result.title')" :description="project ? `${project.code} · ${project.client.name}` : ''" @close="emit('close')">
        <form id="result-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="send">
            <AppField v-slot="{ id }" :label="t('projects.result.last_audit_on')" :error="fieldError('last_audit_on')" optional>
                <input :id="id" v-model="form.last_audit_on" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.result.expires_on')" :error="fieldError('expires_on')" optional>
                <input :id="id" v-model="form.expires_on" type="date" :min="form.last_audit_on || undefined" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.result.result')" :error="fieldError('result')" optional>
                <input :id="id" v-model="form.result" class="field-input" maxlength="60" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.result.certificate_no')" :error="fieldError('certificate_no')" optional>
                <input :id="id" v-model="form.certificate_no" class="field-input" dir="ltr" maxlength="100" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.result.file')" :hint="t('projects.result.file_hint')" :error="fieldError('file')" optional class="sm:col-span-2">
                <input :id="id" type="file" accept=".pdf,.jpg,.jpeg,.png" class="field-input py-1.5" @change="file = $event.target.files?.[0] ?? null" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.result.note')" :error="fieldError('note')" optional class="sm:col-span-2">
                <textarea :id="id" v-model="form.note" rows="2" class="field-input" maxlength="2000"></textarea>
            </AppField>
            <p class="rounded-lg bg-subtle px-3 py-2 text-[12.5px] text-fg-2 sm:col-span-2">{{ t('projects.result.hint') }}</p>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('projects.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="result-form" :icon="Send" :loading="busy" :disabled="!form.expires_on && !form.result && !file">{{ t('projects.result.send') }}</AppButton>
        </template>
    </AppDialog>
</template>
