<script setup>
import { ref, watch } from 'vue';
import { ShieldCheck } from 'lucide-vue-next';
import AppDialog from './AppDialog.vue';
import AppButton from './AppButton.vue';
import SecondStepForm from './SecondStepForm.vue';
import { api } from '@/lib/http';
import { confirmWithPasskey, passkeyErrorMessage } from '@/lib/passkeys';
import { finishStepUp, stepUp } from '@/lib/stepUp';
import { t } from '@/lib/i18n';

/**
 * "Confirm it's you" (Phase 8-1): shown when a sensitive action needs a
 * recent second step. Once confirmed, the action is sent again by itself.
 */
const busy = ref(false);
const error = ref(null);

watch(
    () => stepUp.open,
    (open) => {
        if (open) error.value = null;
    },
);

async function withCode(body) {
    busy.value = true;
    error.value = null;
    try {
        await api('/api/me/security/confirm', { method: 'POST', body, noStepUp: true });
        finishStepUp(true);
    } catch (failure) {
        error.value = failure.message;
    } finally {
        busy.value = false;
    }
}

async function withPasskey() {
    busy.value = true;
    error.value = null;
    try {
        await confirmWithPasskey();
        finishStepUp(true);
    } catch (failure) {
        error.value = passkeyErrorMessage(failure);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppDialog
        :open="stepUp.open"
        layer="z-[65]"
        :title="t('core.second_step.confirm_title')"
        :description="t('core.second_step.confirm_text')"
        :icon="ShieldCheck"
        size="sm"
        @close="finishStepUp(false)"
    >
        <SecondStepForm
            v-if="stepUp.open"
            form-id="step-up-form"
            :methods="stepUp.methods"
            :busy="busy"
            :error="error"
            :submit-label="t('core.second_step.confirm')"
            @code="withCode"
            @passkey="withPasskey"
        />
        <template #footer>
            <AppButton @click="finishStepUp(false)">{{ t('core.actions.cancel') }}</AppButton>
        </template>
    </AppDialog>
</template>
