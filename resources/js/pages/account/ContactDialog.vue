<script setup>
import { reactive, ref, watch } from 'vue';
import { Mail, Smartphone } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import CodeEntry from '@/components/CodeEntry.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import { api } from '@/lib/http';
import { addressBody, signup } from '@/lib/identity';
import { t } from '@/lib/i18n';

/**
 * Add or change the email or phone: the new one gets a code; nothing
 * changes until the code is entered. The old address is told.
 */
const props = defineProps({
    open: Boolean,
    channel: { type: String, default: 'mail' }, // mail | sms
    countries: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const form = reactive({ channel: props.channel, email: '', phone: '', country: signup.default_country, current_password: '' });
const errors = reactive({});
const challenge = ref(null);
const codeError = ref(null);
const busy = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        Object.assign(form, { channel: props.channel, email: '', phone: '', current_password: '' });
        for (const key of Object.keys(errors)) delete errors[key];
        challenge.value = null;
        codeError.value = null;
    },
);

async function send() {
    for (const key of Object.keys(errors)) delete errors[key];
    busy.value = true;
    try {
        challenge.value = (await api('/api/me/contact', { method: 'POST', body: { ...addressBody(form), current_password: form.current_password } })).data;
    } catch (error) {
        for (const [field, messages] of Object.entries(error.errors ?? {})) errors[field] = messages[0];
        errors[error.data?.field ?? 'form'] ??= error.message;
    } finally {
        busy.value = false;
    }
}

async function verify(code) {
    codeError.value = null;
    busy.value = true;
    try {
        const response = await api('/api/me/contact/verify', { method: 'POST', body: { challenge_id: challenge.value.challenge_id, code } });
        emit('saved', response);
    } catch (error) {
        codeError.value = error.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppDialog
        :open="open"
        :title="t(channel === 'sms' ? 'identity.account.phone_dialog' : 'identity.account.email_dialog')"
        :description="t('identity.account.contact_text')"
        :icon="channel === 'sms' ? Smartphone : Mail"
        @close="emit('close')"
    >
        <CodeEntry
            v-if="challenge"
            :challenge="challenge"
            :error="codeError"
            :busy="busy"
            :submit-label="t('identity.account.confirm')"
            @submit="verify"
            @back="challenge = null"
            @resent="(data) => (challenge = { ...challenge, ...data })"
        />
        <form v-else id="contact-form" class="space-y-4" novalidate @submit.prevent="send">
            <p v-if="errors.form" class="text-[13px] text-bad" role="alert">{{ errors.form }}</p>
            <AppField v-if="channel === 'sms'" :label="t('identity.fields.new_phone')" :error="errors.phone">
                <template #default="{ id, invalid, describedby }">
                    <PhoneInput :id="id" v-model="form.phone" v-model:country="form.country" :countries="countries" :invalid="invalid" :describedby="describedby" />
                </template>
            </AppField>
            <AppField v-else :label="t('identity.fields.new_email')" :error="errors.email">
                <template #default="{ id, invalid, describedby }">
                    <input :id="id" v-model="form.email" type="email" inputmode="email" autocomplete="email" dir="ltr" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                </template>
            </AppField>
            <AppField :label="t('identity.fields.current_password')" :error="errors.current_password">
                <template #default="{ id, invalid, describedby }">
                    <input :id="id" v-model="form.current_password" type="password" autocomplete="current-password" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                </template>
            </AppField>
        </form>
        <template v-if="!challenge" #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton type="submit" form="contact-form" variant="primary" :loading="busy" :disabled="!form.current_password">{{ t('identity.signup.send_code') }}</AppButton>
        </template>
    </AppDialog>
</template>
