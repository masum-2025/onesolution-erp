<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { MessageSquareText, RotateCw } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import { api } from '@/lib/http';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * The one-time code step: where it was sent, one box for the digits (the
 * phone can fill it from the SMS), a new code after a short wait, and a
 * way back to change the address. The parent checks the code.
 */
const props = defineProps({
    challenge: { type: Object, required: true }, // { challenge_id, channel, to, length, resend_after }
    error: { type: String, default: null },
    busy: Boolean,
    submitLabel: { type: String, required: true },
});

const emit = defineEmits(['submit', 'back', 'resent']);

const code = ref('');
const input = ref(null);
const wait = ref(props.challenge.resend_after ?? 60);
const resending = ref(false);
const resendError = ref(null);
let timer;

const length = computed(() => props.challenge.length ?? 6);
const complete = computed(() => code.value.length === length.value);

function tick() {
    clearInterval(timer);
    timer = setInterval(() => {
        wait.value = Math.max(0, wait.value - 1);
        if (wait.value === 0) clearInterval(timer);
    }, 1000);
}

function onInput(event) {
    code.value = event.target.value.replace(/\D/g, '').slice(0, length.value);
    event.target.value = code.value;
    // All digits in (typed or filled from the SMS): check at once.
    if (complete.value && !props.busy) emit('submit', code.value);
}

async function resend() {
    resendError.value = null;
    resending.value = true;
    try {
        const response = await api('/session/otp/resend', { method: 'POST', body: { challenge_id: props.challenge.challenge_id } });
        wait.value = response.data.resend_after;
        code.value = '';
        tick();
        emit('resent', response.data);
    } catch (error) {
        resendError.value = error.message;
        if (error.data?.retry_after_seconds) {
            wait.value = error.data.retry_after_seconds;
            tick();
        }
    } finally {
        resending.value = false;
    }
}

// A wrong code: clear it and let the person type again.
watch(
    () => props.error,
    async (value) => {
        if (!value) return;
        code.value = '';
        await nextTick();
        input.value?.focus();
    },
);

onMounted(() => {
    tick();
    input.value?.focus();
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="complete && emit('submit', code)">
        <div class="flex items-start gap-3 rounded-xl bg-subtle p-3.5">
            <MessageSquareText class="mt-0.5 size-5 shrink-0 text-brand-strong" aria-hidden="true" />
            <p class="text-[13.5px] text-fg-2">
                {{ t(challenge.channel === 'sms' ? 'identity.code.sent_sms' : 'identity.code.sent_mail') }}
                <strong class="font-semibold text-fg" dir="ltr">{{ challenge.to }}</strong>
            </p>
        </div>

        <div>
            <label for="otp-code" class="mb-1.5 block text-[13px] font-medium text-fg">{{ t('identity.code.label', { length: length }) }}</label>
            <input
                id="otp-code"
                ref="input"
                :value="code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]*"
                :maxlength="length"
                dir="ltr"
                class="field-input h-14 text-center font-mono text-[26px] tracking-[0.5em]"
                :aria-invalid="!!error || undefined"
                :aria-describedby="error ? 'otp-error' : 'otp-hint'"
                @input="onInput"
            />
            <p v-if="error" id="otp-error" class="mt-1.5 text-[12.5px] text-bad" role="alert">{{ error }}</p>
            <p v-else id="otp-hint" class="mt-1.5 text-[12.5px] text-muted">{{ t('identity.code.hint') }}</p>
        </div>

        <AppButton type="submit" variant="primary" size="lg" block :loading="busy" :disabled="!complete">{{ submitLabel }}</AppButton>

        <div class="flex flex-wrap items-center justify-between gap-2 text-[13px]">
            <button type="button" class="text-muted hover:text-fg hover:underline" @click="emit('back')">{{ t('identity.code.change_address') }}</button>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 font-medium text-brand-strong hover:underline disabled:cursor-not-allowed disabled:text-faint disabled:no-underline"
                :disabled="wait > 0 || resending"
                @click="resend"
            >
                <RotateCw class="size-3.5" :class="resending ? 'animate-spin' : ''" aria-hidden="true" />
                {{ wait > 0 ? t('identity.code.resend_in', { seconds: formatNumber(wait) }) : t('identity.code.resend') }}
            </button>
        </div>
        <p v-if="resendError" class="text-[12.5px] text-bad" role="alert">{{ resendError }}</p>
    </form>
</template>
