<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Eye, EyeOff, Mail, Smartphone, TriangleAlert } from 'lucide-vue-next';
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
 * Self-serve sign-up: name, email or phone and a password; then the code
 * sent there. Nothing is created until the code is right.
 */
const router = useRouter();

const form = reactive({
    channel: signup.phone ? 'sms' : 'mail',
    name: '',
    email: '',
    phone: '',
    country: signup.default_country,
    password: '',
    terms: false,
    marketing: false,
});
const errors = reactive({});
const failure = ref(null);
const botToken = ref(null);
const bot = ref(null);
const showPassword = ref(false);
const sending = ref(false);
const challenge = ref(null);
const codeError = ref(null);
const verifying = ref(false);

const channelOptions = computed(() => [
    { value: 'sms', label: t('identity.fields.phone'), icon: Smartphone },
    { value: 'mail', label: t('identity.fields.email'), icon: Mail },
]);

function validate() {
    for (const key of Object.keys(errors)) delete errors[key];
    if (form.name.trim().length < 2) errors.name = t('identity.errors.name');
    if (form.channel === 'mail' && !/^\S+@\S+\.\S+$/.test(form.email.trim())) errors.email = t('identity.errors.email');
    if (form.channel === 'sms' && form.phone.replace(/\D/g, '').length < 6) errors.phone = t('identity.errors.phone');
    if (passwordProblem(form.password)) errors.password = t('identity.fields.password_rules');
    if (!form.terms) errors.accept_terms = t('identity.errors.terms');
    return Object.keys(errors).length === 0;
}

function showErrors(error) {
    for (const [field, messages] of Object.entries(error.errors ?? {})) errors[field] = messages[0];
    if (error.data?.field) errors[error.data.field] = error.message;
    if (!Object.keys(errors).length) failure.value = error.message;
}

async function send() {
    failure.value = null;
    if (!validate()) return;

    sending.value = true;
    try {
        const response = await api('/session/signup', {
            method: 'POST',
            body: {
                ...addressBody(form),
                name: form.name.trim(),
                password: form.password,
                // One visible password field (with "show"); the server still checks both match.
                password_confirmation: form.password,
                locale: i18n.locale,
                accept_terms: true,
                terms_version: signup.legal.terms_version,
                marketing: form.marketing,
                bot_token: botToken.value,
            },
        });
        challenge.value = response.data;
        codeError.value = null;
    } catch (error) {
        showErrors(error);
        bot.value?.reset();
    } finally {
        sending.value = false;
    }
}

async function verify(code) {
    codeError.value = null;
    verifying.value = true;
    try {
        await api('/session/signup/verify', { method: 'POST', body: { challenge_id: challenge.value.challenge_id, code } });
        resetCaches();
        await loadMe();
        await router.push({ name: 'choose', query: { auto: '1', redirect: '/welcome' } });
    } catch (error) {
        codeError.value = error.message;
        if (error.data?.restart) challenge.value = null;
    } finally {
        verifying.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-canvas">
        <AuthTopBar />
        <main class="flex flex-1 items-center justify-center px-4 py-10">
            <div class="w-full max-w-sm">
                <BrandLockup class="mb-8" />

                <div v-if="!signup.allowed" class="card p-6 text-center" role="alert">
                    <TriangleAlert class="mx-auto size-8 text-warn" aria-hidden="true" />
                    <h1 class="mt-3 text-[18px] font-semibold text-fg">{{ t('identity.signup.closed_title') }}</h1>
                    <p class="mt-2 text-[13.5px] text-muted">{{ t('identity.signup.closed_text') }}</p>
                    <AppButton class="mt-5" to="/login">{{ t('identity.to_login') }}</AppButton>
                </div>

                <div v-else-if="challenge" class="card space-y-4 p-6">
                    <h1 class="text-[20px] font-semibold text-fg">{{ t('identity.code.title') }}</h1>
                    <CodeEntry
                        :challenge="challenge"
                        :error="codeError"
                        :busy="verifying"
                        :submit-label="t('identity.signup.create')"
                        @submit="verify"
                        @back="challenge = null"
                        @resent="(data) => (challenge = { ...challenge, ...data })"
                    />
                </div>

                <form v-else class="card space-y-4 p-6" novalidate @submit.prevent="send">
                    <div>
                        <h1 class="text-[20px] font-semibold text-fg">{{ t('identity.signup.title') }}</h1>
                        <p class="mt-1 text-[13.5px] text-muted">{{ t('identity.signup.text') }}</p>
                    </div>

                    <p v-if="failure" class="flex items-start gap-2 rounded-xl border border-bad/20 bg-bad-soft px-3.5 py-3 text-[13px] text-fg-2" role="alert">
                        <TriangleAlert class="mt-px size-4 shrink-0 text-bad" aria-hidden="true" />{{ failure }}
                    </p>

                    <AppField :label="t('identity.fields.name')" :error="errors.name">
                        <template #default="{ id, invalid, describedby }">
                            <input :id="id" v-model="form.name" autocomplete="name" class="field-input h-11" maxlength="120" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                        </template>
                    </AppField>

                    <AppSegmented v-if="signup.phone" v-model="form.channel" :options="channelOptions" :label="t('identity.fields.how')" block />

                    <AppField v-if="form.channel === 'sms'" :label="t('identity.fields.phone')" :hint="t('identity.fields.phone_hint')" :error="errors.phone">
                        <template #default="{ id, invalid, describedby }">
                            <PhoneInput :id="id" v-model="form.phone" v-model:country="form.country" :countries="signup.phone_countries" :invalid="invalid" :describedby="describedby" />
                        </template>
                    </AppField>
                    <AppField v-else :label="t('identity.fields.email')" :error="errors.email">
                        <template #default="{ id, invalid, describedby }">
                            <input :id="id" v-model="form.email" type="email" inputmode="email" autocomplete="email" autocapitalize="off" spellcheck="false" dir="ltr" class="field-input h-11" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                        </template>
                    </AppField>

                    <AppField :label="t('identity.fields.password')" :hint="t('identity.fields.password_rules')" :error="errors.password">
                        <template #default="{ id, invalid, describedby }">
                            <div class="relative">
                                <input :id="id" v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" class="field-input h-11 pe-11" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                                <button type="button" class="absolute inset-y-0 end-0 grid w-11 place-items-center text-faint hover:text-fg" :aria-label="t(showPassword ? 'identity.fields.hide_password' : 'identity.fields.show_password')" :aria-pressed="showPassword" @click="showPassword = !showPassword">
                                    <component :is="showPassword ? EyeOff : Eye" class="size-[18px]" aria-hidden="true" />
                                </button>
                            </div>
                        </template>
                    </AppField>

                    <div class="space-y-2.5">
                        <label class="flex items-start gap-2.5 text-[13px] text-fg-2">
                            <input v-model="form.terms" type="checkbox" class="mt-0.5 size-4 shrink-0 rounded accent-brand" :aria-invalid="!!errors.accept_terms || undefined" />
                            <span>
                                {{ t('identity.signup.agree_before') }}
                                <a href="/legal/terms" target="_blank" rel="noopener" class="font-medium text-brand-strong hover:underline">{{ t('identity.signup.terms') }}</a>
                                {{ t('identity.signup.agree_and') }}
                                <a href="/legal/privacy" target="_blank" rel="noopener" class="font-medium text-brand-strong hover:underline">{{ t('identity.signup.privacy') }}</a>{{ t('identity.signup.agree_after') }}
                            </span>
                        </label>
                        <p v-if="errors.accept_terms" class="text-[12.5px] text-bad" role="alert">{{ errors.accept_terms }}</p>
                        <label class="flex items-start gap-2.5 text-[13px] text-fg-2">
                            <input v-model="form.marketing" type="checkbox" class="mt-0.5 size-4 shrink-0 rounded accent-brand" />
                            <span>{{ t('identity.signup.marketing') }}</span>
                        </label>
                    </div>

                    <BotCheck ref="bot" v-model="botToken" />
                    <p v-if="errors.bot_token" class="text-[12.5px] text-bad" role="alert">{{ errors.bot_token }}</p>

                    <AppButton type="submit" variant="primary" size="lg" block :loading="sending">{{ t('identity.signup.send_code') }}</AppButton>
                </form>

                <p class="mt-6 text-center text-[13px] text-muted">
                    {{ t('identity.signup.have_account') }}
                    <RouterLink to="/login" class="font-medium text-brand-strong hover:underline">{{ t('identity.to_login') }}</RouterLink>
                </p>
            </div>
        </main>
    </div>
</template>
