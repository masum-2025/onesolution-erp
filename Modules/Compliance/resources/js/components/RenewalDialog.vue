<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Copy, Mail, Send } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { mailtoLink } from '../lib';

/**
 * Ask a line's provider to renew it. A provider on the platform receives it
 * in its own account; for any other one the request is noted and the text
 * of an email is ready to send (or copy).
 */
const props = defineProps({
    open: Boolean,
    line: { type: Object, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'sent']);

const form = reactive({ wanted_by: '', note: '' });
const errors = ref({});
const saving = ref(false);
const mail = ref(null);
watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, { wanted_by: '', note: '' });
    errors.value = {};
    mail.value = null;
});
// It reaches them on the platform only when their side can receive it; otherwise it is noted and emailed.
const onPlatform = computed(() => !!props.line?.provider?.receives_requests);
const reach = computed(() => (onPlatform.value ? 'on_platform' : props.line?.provider?.on_platform ? 'not_receiving' : 'off_platform'));
const today = new Date().toISOString().slice(0, 10);

async function send() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await props.calls.requestRenewal(props.line.id, { wanted_by: form.wanted_by || null, note: form.note.trim() || null });
        toast.success(t(data.status === 'sent' ? 'compliance.renewal.sent' : 'compliance.renewal.recorded'));
        emit('sent', data);
        // Not on the platform: keep the dialog open with the email to send.
        if (data.mail) mail.value = data.mail;
        else emit('close');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
async function copy() {
    try {
        await navigator.clipboard.writeText(`${mail.value.subject}\n\n${mail.value.body}`);
        toast.success(t('compliance.renewal.copied'));
    } catch {
        toast.error(t('compliance.renewal.copy_failed'));
    }
}
// The person's own mail app, with the subject and text filled in.
const openMail = () => window.location.assign(mailtoLink(mail.value));
const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog :open="open" :title="t('compliance.renewal.title')" :description="line ? `${line.factory.name} · ${line.service?.name} · ${line.provider?.name ?? ''}` : ''" @close="emit('close')">
        <form v-if="!mail" id="compliance-renewal" class="space-y-4" novalidate @submit.prevent="send">
            <p class="rounded-lg bg-subtle px-3 py-2 text-[13px]">{{ t(`compliance.renewal.${reach}`, { provider: line?.provider?.name ?? '' }) }}</p>
            <AppField v-slot="{ id }" :label="t('compliance.renewal.wanted_by')" :hint="t('compliance.renewal.wanted_by_hint')" :error="fieldError('wanted_by')" optional>
                <input :id="id" v-model="form.wanted_by" type="date" :min="today" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('compliance.renewal.note')" :error="fieldError('note')" optional>
                <textarea :id="id" v-model="form.note" rows="3" class="field-input" maxlength="1000"></textarea>
            </AppField>
        </form>
        <div v-else class="space-y-3">
            <p class="text-[13px] text-fg-2">{{ t('compliance.renewal.mail_ready') }}</p>
            <div class="rounded-xl bg-subtle px-4 py-3 text-[13px]">
                <p class="font-semibold">{{ mail.subject }}</p>
                <p class="mt-2 whitespace-pre-line">{{ mail.body }}</p>
            </div>
        </div>
        <template #footer>
            <template v-if="!mail">
                <AppButton variant="ghost" @click="emit('close')">{{ t('compliance.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="compliance-renewal" :icon="Send" :loading="saving">{{ t(onPlatform ? 'compliance.renewal.send' : 'compliance.renewal.note_it') }}</AppButton>
            </template>
            <template v-else>
                <AppButton variant="ghost" :icon="Copy" @click="copy">{{ t('compliance.renewal.copy') }}</AppButton>
                <AppButton variant="primary" :icon="Mail" @click="openMail">{{ t('compliance.renewal.open_mail') }}</AppButton>
                <AppButton variant="ghost" @click="emit('close')">{{ t('compliance.common.done') }}</AppButton>
            </template>
        </template>
    </AppDialog>
</template>
