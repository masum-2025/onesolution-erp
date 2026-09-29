<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Fingerprint, KeyRound, LifeBuoy } from 'lucide-vue-next';
import AppButton from './AppButton.vue';
import AppField from './AppField.vue';
import { passkeysSupported } from '@/lib/passkeys';
import { t } from '@/lib/i18n';

/**
 * The second step (Phase 8-1), for signing in and for confirming a
 * sensitive action: a code from the authenticator app, a recovery code,
 * or a passkey. The parent sends it to the server.
 */
const props = defineProps({
    methods: { type: Array, required: true }, // totp | recovery_code | passkey
    busy: Boolean,
    error: { type: String, default: null },
    submitLabel: { type: String, required: true },
    formId: { type: String, default: 'second-step-form' },
});

const emit = defineEmits(['code', 'passkey']);

const hasApp = computed(() => props.methods.includes('totp'));
const hasPasskey = computed(() => props.methods.includes('passkey') && passkeysSupported());
const hasRecovery = computed(() => props.methods.includes('recovery_code'));

// Start with the app when there is one; recovery codes are the way out.
const mode = ref(hasApp.value ? 'app' : 'recovery');
const code = ref('');
const input = ref(null);

const inputLabel = computed(() => (mode.value === 'app' ? t('core.second_step.app_code') : t('core.second_step.recovery_code')));

async function switchTo(next) {
    mode.value = next;
    code.value = '';
    await nextTick();
    input.value?.focus();
}

function submit() {
    const value = code.value.trim();
    if (!value) return;
    emit('code', mode.value === 'app' ? { code: value.replace(/\s+/g, '') } : { recovery_code: value });
}

onMounted(() => input.value?.focus());
</script>

<template>
    <div class="space-y-4">
        <AppButton v-if="hasPasskey" variant="primary" size="lg" block :icon="Fingerprint" :loading="busy && mode === 'passkey'" :disabled="busy" @click="mode = 'passkey'; emit('passkey')">
            {{ t('core.second_step.use_passkey') }}
        </AppButton>

        <p v-if="hasPasskey && (hasApp || hasRecovery)" class="flex items-center gap-3 text-[12px] text-faint" role="separator">
            <span class="h-px flex-1 bg-line" />{{ t('core.second_step.or') }}<span class="h-px flex-1 bg-line" />
        </p>

        <form v-if="hasApp || hasRecovery" :id="formId" class="space-y-3" novalidate @submit.prevent="submit">
            <AppField :label="inputLabel" :error="error" :hint="mode === 'app' ? t('core.second_step.app_hint') : t('core.second_step.recovery_hint')">
                <template #default="{ id, invalid, describedby }">
                    <input
                        :id="id"
                        ref="input"
                        v-model="code"
                        data-autofocus
                        class="field-input h-11 font-mono tracking-[0.2em]"
                        :inputmode="mode === 'app' ? 'numeric' : 'text'"
                        :autocomplete="mode === 'app' ? 'one-time-code' : 'off'"
                        :maxlength="mode === 'app' ? 8 : 20"
                        autocapitalize="characters"
                        spellcheck="false"
                        :aria-invalid="invalid || undefined"
                        :aria-describedby="describedby"
                    />
                </template>
            </AppField>
            <AppButton type="submit" :variant="hasPasskey ? 'secondary' : 'primary'" size="lg" block :loading="busy && mode !== 'passkey'" :disabled="busy || !code.trim()">
                {{ submitLabel }}
            </AppButton>
        </form>

        <div class="flex flex-wrap justify-center gap-x-4 gap-y-1 text-[13px]">
            <button v-if="hasApp && mode !== 'app'" type="button" class="inline-flex items-center gap-1.5 font-medium text-brand-strong hover:underline" @click="switchTo('app')">
                <KeyRound class="size-3.5" aria-hidden="true" />{{ t('core.second_step.use_app') }}
            </button>
            <button v-if="hasRecovery && mode !== 'recovery'" type="button" class="inline-flex items-center gap-1.5 font-medium text-brand-strong hover:underline" @click="switchTo('recovery')">
                <LifeBuoy class="size-3.5" aria-hidden="true" />{{ t('core.second_step.use_recovery') }}
            </button>
        </div>
    </div>
</template>
