<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, ArrowRight, Blocks, Eye, EyeOff, Fingerprint, Languages, Lock, Mail, ShieldCheck, Smartphone, TriangleAlert } from 'lucide-vue-next';
import AuthTopBar from '@/layouts/AuthTopBar.vue';
import BrandLockup from '@/components/BrandLockup.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import SecondStepForm from '@/components/SecondStepForm.vue';
import { signup } from '@/lib/identity';
import { completeTwoFactor, login } from '@/lib/session';
import { passkeyErrorMessage, passkeysSupported, signInWithPasskey } from '@/lib/passkeys';
import { brand, brandText, taglineFor } from '@/lib/brand';
import { formatNumber } from '@/lib/format';
import { i18n, t } from '@/lib/i18n';

const router = useRouter();
const route = useRoute();

const form = reactive({ channel: 'mail', email: '', phone: '', country: signup.default_country, password: '' });
const errors = reactive({ email: null, phone: null, password: null });

// Phone sign-in where this address sends SMS (verified phones only, Phase 5C).
const channelOptions = computed(() => [
    { value: 'mail', label: t('auth.login.use_email'), icon: Mail },
    { value: 'sms', label: t('auth.login.use_phone'), icon: Smartphone },
]);
const showPassword = ref(false);
const submitting = ref(false);
const failure = ref(null);
const waitSeconds = ref(0);
let countdown;

const locked = computed(() => waitSeconds.value > 0);

// Two-step sign-in (Phase 8-1): after the password, or after a reset or an
// invitation that ended on the second step (?step=two-factor&methods=...).
const secondStep = ref(route.query.step === 'two-factor' ? { methods: String(route.query.methods ?? 'totp,recovery_code').split(',') } : null);
const stepBusy = ref(false);
const stepError = ref(null);
const passkeyBusy = ref(false);
const canUsePasskey = passkeysSupported();

function validate() {
    errors.phone = form.channel === 'sms' && form.phone.replace(/\D/g, '').length < 6 ? t('auth.login.phone_required') : null;
    errors.email = form.channel === 'sms' ? null : !form.email.trim() ? t('auth.login.email_required') : !/^\S+@\S+\.\S+$/.test(form.email.trim()) ? t('auth.login.email_invalid') : null;
    errors.password = !form.password ? t('auth.login.password_required') : null;
    return !errors.email && !errors.phone && !errors.password;
}

function startCountdown(seconds) {
    clearInterval(countdown);
    waitSeconds.value = seconds;
    countdown = setInterval(() => {
        waitSeconds.value -= 1;
        if (waitSeconds.value <= 0) clearInterval(countdown);
    }, 1000);
}

function safeRedirect(target) {
    return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//') ? target : null;
}

async function submit() {
    failure.value = null;
    if (!validate() || locked.value) return;

    submitting.value = true;
    try {
        const me = await login(
            form.channel === 'sms'
                ? { phone: form.phone.trim(), country_code: form.country, password: form.password }
                : { email: form.email.trim(), password: form.password },
        );
        if (me?.twoFactor) {
            secondStep.value = me.twoFactor;
            stepError.value = null;
            form.password = '';
            return;
        }
        await goOn(me);
    } catch (error) {
        if (error.status === 429) {
            startCountdown(error.retryAfter ?? 60);
        } else if (error.status === 422) {
            errors.email = error.field('email');
            errors.phone = error.field('phone');
            errors.password = error.field('password');
            if (!errors.email && !errors.phone && !errors.password) failure.value = error.message;
        } else {
            failure.value = error.message;
        }
        form.password = '';
    } finally {
        submitting.value = false;
    }
}

async function goOn(me) {
    const redirect = safeRedirect(route.query.redirect);
    const single = (me?.contexts?.organizations?.length ?? 0) + (me?.contexts?.partners?.length ?? 0) === 1;
    await router.push(me?.context ? redirect ?? '/' : { name: 'choose', query: { ...(redirect ? { redirect } : {}), ...(single ? { auto: '1' } : {}) } });
}

/** The waiting sign-in ended (time, tries): back to the password, with the reason. */
function backToPassword(message = null) {
    secondStep.value = null;
    stepError.value = null;
    failure.value = message;
    if (route.query.step) router.replace({ query: { ...route.query, step: undefined, methods: undefined } });
}

async function submitCode(body) {
    stepBusy.value = true;
    stepError.value = null;
    try {
        await goOn(await completeTwoFactor(body));
    } catch (error) {
        if (error.code === 'challenge_ended') backToPassword(error.message);
        else stepError.value = error.message;
    } finally {
        stepBusy.value = false;
    }
}

/** A passkey: alone (no password), or as the second step. */
async function usePasskey() {
    const asSecondStep = secondStep.value !== null;
    (asSecondStep ? stepBusy : passkeyBusy).value = true;
    stepError.value = null;
    failure.value = null;
    try {
        await goOn(await signInWithPasskey());
    } catch (error) {
        if (error.code === 'challenge_ended') backToPassword(error.message);
        else if (asSecondStep) stepError.value = passkeyErrorMessage(error);
        else failure.value = passkeyErrorMessage(error);
    } finally {
        stepBusy.value = false;
        passkeyBusy.value = false;
    }
}

onBeforeUnmount(() => clearInterval(countdown));
</script>

<template>
    <div class="min-h-dvh bg-surface lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
        <!-- Form -->
        <div class="flex min-h-dvh flex-col px-5 py-5 sm:px-10 lg:px-14">
            <AuthTopBar without-brand />

            <div class="flex flex-1 items-center justify-center py-10">
                <div class="w-full max-w-[380px] animate-rise">
                    <BrandLockup class="mb-9" />
                    <h1 class="text-[26px] leading-tight font-semibold tracking-[-0.025em] text-fg">{{ brandText('login_title', i18n.locale) || t('auth.login.title') }}</h1>
                    <p class="mt-2 text-[14px] text-muted">{{ brandText('login_text', i18n.locale) || t('auth.login.subtitle', { brand: brand.name }) }}</p>

                    <div
                        v-if="locked"
                        class="mt-6 flex items-start gap-2.5 rounded-xl border border-warn/25 bg-warn-soft px-3.5 py-3 text-[13px] text-fg-2"
                        role="alert"
                    >
                        <TriangleAlert class="mt-px size-4 shrink-0 text-warn" aria-hidden="true" />
                        <span>{{ t('auth.login.throttled', { seconds: formatNumber(waitSeconds) }) }}</span>
                    </div>
                    <div
                        v-else-if="failure"
                        class="mt-6 flex items-start gap-2.5 rounded-xl border border-bad/20 bg-bad-soft px-3.5 py-3 text-[13px] text-fg-2"
                        role="alert"
                    >
                        <TriangleAlert class="mt-px size-4 shrink-0 text-bad" aria-hidden="true" />
                        <span>{{ failure }}</span>
                    </div>

                    <div v-if="secondStep" class="mt-7 space-y-5">
                        <div class="flex items-start gap-3 rounded-xl border border-line bg-subtle/60 px-3.5 py-3">
                            <ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-text" aria-hidden="true" />
                            <div>
                                <p class="text-[14px] font-semibold text-fg">{{ t('auth.two_factor.title') }}</p>
                                <p class="mt-0.5 text-[13px] text-muted">{{ t('auth.two_factor.text') }}</p>
                            </div>
                        </div>
                        <SecondStepForm
                            :methods="secondStep.methods"
                            :busy="stepBusy"
                            :error="stepError"
                            :submit-label="t('auth.two_factor.submit')"
                            @code="submitCode"
                            @passkey="usePasskey"
                        />
                        <p class="text-center text-[13px]">
                            <button type="button" class="inline-flex items-center gap-1.5 font-medium text-muted hover:text-fg" @click="backToPassword()">
                                <ArrowLeft class="size-3.5 rtl:rotate-180" aria-hidden="true" />{{ t('auth.two_factor.back') }}
                            </button>
                        </p>
                    </div>

                    <form v-else class="mt-7 space-y-4" novalidate @submit.prevent="submit">
                        <AppSegmented v-if="signup.phone" v-model="form.channel" :options="channelOptions" :label="t('auth.login.sign_in_with')" block />
                        <AppField v-if="form.channel === 'sms'" :label="t('auth.login.phone')" :error="errors.phone">
                            <template #default="{ id, invalid, describedby }">
                                <PhoneInput :id="id" v-model="form.phone" v-model:country="form.country" :countries="signup.phone_countries" :invalid="invalid" :describedby="describedby" autocomplete="username" />
                            </template>
                        </AppField>
                        <AppField v-else :label="t('auth.login.email')" :error="errors.email">
                            <template #default="{ id, invalid, describedby }">
                                <input
                                    :id="id"
                                    v-model="form.email"
                                    type="email"
                                    autocomplete="username"
                                    inputmode="email"
                                    autocapitalize="off"
                                    spellcheck="false"
                                    class="field-input h-11"
                                    :placeholder="t('auth.login.email_placeholder')"
                                    :aria-invalid="invalid || undefined"
                                    :aria-describedby="describedby"
                                    autofocus
                                />
                            </template>
                        </AppField>

                        <AppField :label="t('auth.login.password')" :error="errors.password">
                            <template #default="{ id, invalid, describedby }">
                                <div class="relative">
                                    <input
                                        :id="id"
                                        v-model="form.password"
                                        :type="showPassword ? 'text' : 'password'"
                                        autocomplete="current-password"
                                        class="field-input h-11 pe-11"
                                        :aria-invalid="invalid || undefined"
                                        :aria-describedby="describedby"
                                    />
                                    <button
                                        type="button"
                                        class="absolute inset-y-0 end-0 grid w-11 place-items-center rounded-e-[9px] text-faint transition hover:text-fg"
                                        :aria-label="showPassword ? t('auth.login.hide_password') : t('auth.login.show_password')"
                                        :aria-pressed="showPassword"
                                        @click="showPassword = !showPassword"
                                    >
                                        <component :is="showPassword ? EyeOff : Eye" class="size-[18px]" aria-hidden="true" />
                                    </button>
                                </div>
                            </template>
                        </AppField>

                        <p class="-mt-1 text-end text-[13px]">
                            <RouterLink to="/forgot" class="font-medium text-brand-strong hover:underline">{{ t('auth.login.forgot') }}</RouterLink>
                        </p>

                        <AppButton type="submit" variant="primary" size="lg" block :loading="submitting" :disabled="locked" :icon-end="submitting ? null : ArrowRight" class="!mt-6">
                            {{ t('auth.login.submit') }}
                        </AppButton>

                        <template v-if="canUsePasskey">
                            <p class="flex items-center gap-3 text-[12px] text-faint" role="separator">
                                <span class="h-px flex-1 bg-line" />{{ t('auth.login.or') }}<span class="h-px flex-1 bg-line" />
                            </p>
                            <AppButton size="lg" block :icon="Fingerprint" :loading="passkeyBusy" :disabled="locked" @click="usePasskey">
                                {{ t('auth.login.passkey') }}
                            </AppButton>
                        </template>
                    </form>

                    <p v-if="signup.allowed" class="mt-6 text-center text-[13px] text-muted">
                        {{ t('auth.login.new_here') }}
                        <RouterLink to="/signup" class="font-medium text-brand-strong hover:underline">{{ t('auth.login.create_account') }}</RouterLink>
                    </p>
                    <p v-else class="mt-6 text-center text-[13px] text-muted">{{ t('auth.login.no_access') }}</p>
                </div>
            </div>

            <div class="space-y-2 text-center text-[12px] text-faint">
                <p class="flex items-center justify-center gap-1.5">
                    <Lock class="size-3.5" aria-hidden="true" />
                    {{ t('auth.login.secure_note') }}
                </p>
                <p v-if="brandText('footer_text', i18n.locale)">{{ brandText('footer_text', i18n.locale) }}</p>
                <p v-if="brand.terms_url || brand.privacy_url || brand.powered_by" class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1">
                    <a v-if="brand.terms_url" :href="brand.terms_url" target="_blank" rel="noopener noreferrer" class="hover:text-fg hover:underline">{{ t('auth.login.terms') }}</a>
                    <a v-if="brand.privacy_url" :href="brand.privacy_url" target="_blank" rel="noopener noreferrer" class="hover:text-fg hover:underline">{{ t('auth.login.privacy') }}</a>
                    <span v-if="brand.powered_by">{{ t('auth.login.powered_by', { name: brand.powered_by }) }}</span>
                </p>
            </div>
        </div>

        <!-- Showcase (decorative) -->
        <div class="relative m-3 hidden overflow-hidden rounded-[28px] bg-brand text-brand-fg lg:flex lg:flex-col" aria-hidden="true">
            <div class="absolute inset-0 bg-[radial-gradient(90%_70%_at_100%_0%,color-mix(in_oklab,var(--brand)_55%,white)_0%,transparent_60%),radial-gradient(70%_60%_at_0%_100%,color-mix(in_oklab,var(--brand)_60%,black)_0%,transparent_65%)]" />
            <div class="absolute inset-0 opacity-[0.14] [background-image:linear-gradient(currentColor_1px,transparent_1px),linear-gradient(90deg,currentColor_1px,transparent_1px)] [background-size:44px_44px] [mask-image:radial-gradient(75%_65%_at_50%_40%,black,transparent)]" />

            <div class="relative flex flex-1 flex-col justify-between p-12 xl:p-14">
                <span class="inline-flex w-fit items-center gap-2.5 rounded-full bg-white/12 py-1.5 ps-1.5 pe-3.5 text-[12.5px] font-medium ring-1 ring-white/20 backdrop-blur">
                    <span class="grid size-7 place-items-center rounded-full bg-white">
                        <img v-if="brand.mark_url" :src="brand.mark_url" alt="" class="size-5 object-contain" />
                        <span v-else class="size-1.5 rounded-full bg-brand" />
                    </span>
                    {{ taglineFor(i18n.locale) || t('auth.showcase.badge') }}
                </span>

                <div class="relative mx-auto my-10 w-full max-w-[440px]">
                    <div class="rounded-2xl bg-white/95 p-4 text-[#111114] shadow-[0_30px_60px_-20px_rgb(0_0_0/0.45)] ring-1 ring-black/5">
                        <div class="flex items-center justify-between">
                            <p class="text-[12px] font-medium text-[#6a6a75]">{{ t('auth.showcase.rule_module') }}</p>
                            <span class="inline-flex items-center gap-1 rounded-md bg-[#f3f3f5] px-1.5 py-0.5 text-[11px] font-medium text-[#3c3c44]">
                                <Lock class="size-3" />
                                {{ t('auth.showcase.locked') }}
                            </span>
                        </div>
                        <p class="mt-1 text-[15px] font-semibold">{{ t('auth.showcase.rule_label') }}</p>
                        <div class="mt-3 flex items-end justify-between">
                            <p class="text-[28px] leading-none font-semibold tracking-tight">{{ t('auth.showcase.rule_value') }}</p>
                            <p class="text-[12px] text-[#6a6a75]">{{ t('auth.showcase.rule_source') }}</p>
                        </div>
                    </div>

                    <div class="relative -mt-2 ms-10 me-[-12px] rounded-2xl bg-white/95 p-4 text-[#111114] shadow-[0_30px_60px_-20px_rgb(0_0_0/0.45)] ring-1 ring-black/5">
                        <p class="text-[12px] font-medium text-[#6a6a75]">{{ t('auth.showcase.modules') }}</p>
                        <ul class="mt-2.5 space-y-2.5 text-[13.5px]">
                            <li v-for="(on, name) in { hrm: true, payroll: true, inventory: false }" :key="name" class="flex items-center justify-between">
                                <span class="font-medium">{{ t(`auth.showcase.module_${name}`) }}</span>
                                <span class="relative inline-flex h-[20px] w-[34px] items-center rounded-full p-0.5" :class="on ? 'bg-brand' : 'bg-[#d6d6dc]'">
                                    <span class="size-4 rounded-full bg-white shadow" :class="on ? 'translate-x-3.5 rtl:-translate-x-3.5' : ''" />
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div>
                    <h2 class="max-w-md text-[30px] leading-[1.15] font-semibold tracking-[-0.025em]">{{ t('auth.showcase.headline') }}</h2>
                    <p class="mt-3 max-w-md text-[14.5px] leading-relaxed opacity-80">{{ t('auth.showcase.text') }}</p>
                    <ul class="mt-8 grid grid-cols-3 gap-4 text-[12.5px]">
                        <li class="flex flex-col gap-2">
                            <Blocks class="size-5 opacity-90" />
                            <span class="opacity-85">{{ t('auth.showcase.point_levels') }}</span>
                        </li>
                        <li class="flex flex-col gap-2">
                            <Languages class="size-5 opacity-90" />
                            <span class="opacity-85">{{ t('auth.showcase.point_languages') }}</span>
                        </li>
                        <li class="flex flex-col gap-2">
                            <ShieldCheck class="size-5 opacity-90" />
                            <span class="opacity-85">{{ t('auth.showcase.point_security') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
