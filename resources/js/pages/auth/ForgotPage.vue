<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Eye, EyeOff, KeyRound, Mail, Smartphone, TriangleAlert } from 'lucide-vue-next';
import AuthTopBar from '@/layouts/AuthTopBar.vue';
import BrandLockup from '@/components/BrandLockup.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import BotCheck from '@/components/BotCheck.vue';
import CodeEntry from '@/components/CodeEntry.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import { api } from '@/lib/http';
import { addressBody, passwordProblem, signup } from '@/lib/identity';
import { loadMe } from '@/lib/session';
import { resetCaches } from '@/lib/cache';
import { i18n, t } from '@/lib/i18n';

/**
 * "Forgot password": a code to the account's email or phone, then a new
 * password. Every other device is signed out and every address is told.
 */
const router = useRouter();

const step = ref('address'); // address | code | password
const form = reactive({ channel: 'mail', email: '', phone: '', country: signup.default_country, code: '', password: '' });
const errors = reactive({});
const failure = ref(null);
const botToken = ref(null);
const bot = ref(null);
const challenge = ref(null);
const codeError = ref(null);
const busy = ref(false);
const showPassword = ref(false);

const channelOptions = computed(() => [
    { value: 'mail', label: t('identity.fields.email'), icon: Mail },
    { value: 'sms', label: t('identity.fields.phone'), icon: Smartphone },
]);

function clearErrors() {
    failure.value = null;
    for (const key of Object.keys(errors)) delete errors[key];
}

async function send() {
    clearErrors();
    if (form.channel === 'mail' && !/^\S+@\S+\.\S+$/.test(form.email.trim())) errors.email = t('identity.errors.email');
    if (form.channel === 'sms' && form.phone.replace(/\D/g, '').length < 6) errors.phone = t('identity.errors.phone');
    if (Object.keys(errors).length) return;

    busy.value = true;
    try {
        const response = await api('/session/recovery', { method: 'POST', body: { ...addressBody(form), locale: i18n.locale, bot_token: botToken.value } });
        challenge.value = response.data;
        step.value = 'code';
    } catch (error) {
        for (const [field, messages] of Object.entries(error.errors ?? {})) errors[field] = messages[0];
        if (error.data?.field) errors[error.data.field] = error.message;
        if (!Object.keys(errors).length) failure.value = error.message;
        bot.value?.reset();
    } finally {
        busy.value = false;
    }
}

function codeEntered(code) {
    form.code = code;
    codeError.value = null;
    step.value = 'password';
}

async function reset() {
    clearErrors();
    if (passwordProblem(form.password)) {
        errors.password = t('identity.fields.password_rules');
        return;
    }

    busy.value = true;
    try {
        const result = await api('/session/recovery/verify', {
            method: 'POST',
            body: { challenge_id: challenge.value.challenge_id, code: form.code, password: form.password, password_confirmation: form.password },
        });
        // A new password does not skip two-step sign-in (Phase 8-1).
        if (result?.two_factor) {
            await router.push({ name: 'login', query: { step: 'two-factor', methods: result.two_factor.methods.join(',') } });
            return;
        }
        resetCaches();
        const me = await loadMe();
        await router.push(me?.context ? '/' : { name: 'choose', query: { auto: '1' } });
    } catch (error) {
        if (error.data?.field === 'code' || error.data?.restart) {
            // The code turned out wrong or used up: back to the code (or the start).
            codeError.value = error.message;
            step.value = error.data?.restart ? 'address' : 'code';
            if (error.data?.restart) failure.value = error.message;
        } else {
            errors.password = error.field?.('password') ?? error.message;
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-canvas">
        <AuthTopBar />
        <main class="flex flex-1 items-center justify-center px-4 py-10">
            <div class="w-full max-w-sm">
                <BrandLockup class="mb-8" />

                <div class="card space-y-4 p-6">
                    <div>
                        <h1 class="flex items-center gap-2 text-[20px] font-semibold text-fg"><KeyRound class="size-5 text-brand-strong" aria-hidden="true" />{{ t('identity.forgot.title') }}</h1>
                        <p class="mt-1 text-[13.5px] text-muted">{{ t(step === 'password' ? 'identity.forgot.new_text' : 'identity.forgot.text') }}</p>
                    </div>

                    <p v-if="failure" class="flex items-start gap-2 rounded-xl border border-bad/20 bg-bad-soft px-3.5 py-3 text-[13px] text-fg-2" role="alert">
                        <TriangleAlert class="mt-px size-4 shrink-0 text-bad" aria-hidden="true" />{{ failure }}
                    </p>

                    <form v-if="step === 'address'" class="space-y-4" novalidate @submit.prevent="send">
                        <AppSegmented v-if="signup.phone" v-model="form.channel" :options="channelOptions" :label="t('identity.fields.how')" block />
                        <AppField v-if="form.channel === 'sms'" :label="t('identity.fields.phone')" :error="errors.phone">
                            <template #default="{ id, invalid, describedby }">
                                <PhoneInput :id="id" v-model="form.phone" v-model:country="form.country" :countries="signup.phone_countries" :invalid="invalid" :describedby="describedby" />
                            </template>
                        </AppField>
                        <AppField v-else :label="t('identity.fields.email')" :error="errors.email">
                            <template #default="{ id, invalid, describedby }">
                                <input :id="id" v-model="form.email" type="email" inputmode="email" autocomplete="email" autocapitalize="off" spellcheck="false" dir="ltr" class="field-input h-11" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                            </template>
                        </AppField>
                        <BotCheck ref="bot" v-model="botToken" />
                        <p v-if="errors.bot_token" class="text-[12.5px] text-bad" role="alert">{{ errors.bot_token }}</p>
                        <AppButton type="submit" variant="primary" size="lg" block :loading="busy">{{ t('identity.signup.send_code') }}</AppButton>
                    </form>

                    <CodeEntry
                        v-else-if="step === 'code'"
                        :challenge="challenge"
                        :error="codeError"
                        :submit-label="t('identity.forgot.next')"
                        @submit="codeEntered"
                        @back="step = 'address'"
                        @resent="(data) => (challenge = { ...challenge, ...data })"
                    />

                    <form v-else class="space-y-4" novalidate @submit.prevent="reset">
                        <AppField :label="t('identity.fields.new_password')" :hint="t('identity.fields.password_rules')" :error="errors.password">
                            <template #default="{ id, invalid, describedby }">
                                <div class="relative">
                                    <input :id="id" v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" class="field-input h-11 pe-11" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                                    <button type="button" class="absolute inset-y-0 end-0 grid w-11 place-items-center text-faint hover:text-fg" :aria-label="t(showPassword ? 'identity.fields.hide_password' : 'identity.fields.show_password')" :aria-pressed="showPassword" @click="showPassword = !showPassword">
                                        <component :is="showPassword ? EyeOff : Eye" class="size-[18px]" aria-hidden="true" />
                                    </button>
                                </div>
                            </template>
                        </AppField>
                        <p class="text-[12.5px] text-muted">{{ t('identity.forgot.effects') }}</p>
                        <AppButton type="submit" variant="primary" size="lg" block :loading="busy">{{ t('identity.forgot.save') }}</AppButton>
                    </form>
                </div>

                <p class="mt-6 text-center text-[13px] text-muted">
                    <RouterLink to="/login" class="font-medium text-brand-strong hover:underline">{{ t('identity.back_to_login') }}</RouterLink>
                </p>
            </div>
        </main>
    </div>
</template>
