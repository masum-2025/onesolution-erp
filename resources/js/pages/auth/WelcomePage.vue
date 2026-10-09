<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ArrowLeft, ArrowRight, Check } from 'lucide-vue-next';
import AuthTopBar from '@/layouts/AuthTopBar.vue';
import BrandLockup from '@/components/BrandLockup.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { countryName } from '@/lib/display';
import { signup } from '@/lib/identity';
import { loadMe, session } from '@/lib/session';
import { i18n, languageName, setLocale, t } from '@/lib/i18n';
import { toast } from '@/lib/toast';

/**
 * First run after sign-up, three short steps anyone can skip: language,
 * country, and the kind of work (which sets up the workspace for it).
 */
const router = useRouter();
const step = ref(1);
const saving = ref(false);
// Where people sign up from most; any other country can be set later in the workspace settings.
const COUNTRIES = ['BD', 'IN', 'PK', 'NP', 'LK', 'MY', 'AE', 'SA', 'GB', 'US'];

const form = reactive({
    locale: i18n.locale,
    country: session.me?.context?.settings?.country_code ?? signup.default_country,
    sector: null,
});

const sectors = useResource(() => api('/api/sectors').then((response) => response.data));
const languageOptions = computed(() => i18n.locales.map((locale) => ({ value: locale, label: languageName(locale) })));
const countries = computed(() => [...new Set([form.country, ...COUNTRIES])].filter(Boolean).map((code) => ({ code, name: countryName(code) })));

async function chooseLanguage(locale) {
    form.locale = locale;
    await setLocale(locale);
}

async function finish() {
    saving.value = true;
    try {
        await api('/api/me/onboarding', { method: 'POST', body: { locale: form.locale, country_code: form.country, sector_key: form.sector } });
        await loadMe();
        await router.push('/');
    } catch (error) {
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-canvas">
        <AuthTopBar />
        <main class="flex flex-1 items-center justify-center px-4 py-10">
            <div class="w-full max-w-md">
                <BrandLockup class="mb-8" />
                <div class="card p-6">
                    <p class="text-[12px] font-semibold tracking-wider text-faint uppercase">{{ t('identity.welcome.step', { step, total: 3 }) }}</p>
                    <div class="mt-2 flex gap-1.5" aria-hidden="true">
                        <span v-for="n in 3" :key="n" class="h-1 flex-1 rounded-full" :class="n <= step ? 'bg-brand' : 'bg-line'" />
                    </div>

                    <section v-if="step === 1" class="mt-5 space-y-4">
                        <h1 class="text-[20px] font-semibold text-fg">{{ t('identity.welcome.title', { name: session.me?.user?.name ?? '' }) }}</h1>
                        <p class="text-[13.5px] text-muted">{{ t('identity.welcome.language') }}</p>
                        <AppSegmented :model-value="form.locale" :options="languageOptions" :label="t('identity.fields.language')" block @update:model-value="chooseLanguage" />
                    </section>

                    <section v-else-if="step === 2" class="mt-5 space-y-4">
                        <h1 class="text-[20px] font-semibold text-fg">{{ t('identity.welcome.country_title') }}</h1>
                        <p class="text-[13.5px] text-muted">{{ t('identity.welcome.country_text') }}</p>
                        <select v-model="form.country" class="field-input h-11" :aria-label="t('identity.fields.country')">
                            <option v-for="country in countries" :key="country.code" :value="country.code">{{ country.name }}</option>
                        </select>
                    </section>

                    <section v-else class="mt-5 space-y-4">
                        <h1 class="text-[20px] font-semibold text-fg">{{ t('identity.welcome.work_title') }}</h1>
                        <p class="text-[13.5px] text-muted">{{ t('identity.welcome.work_text') }}</p>
                        <div v-if="sectors.loading.value && !sectors.data.value" class="space-y-2" role="status" :aria-label="t('core.states.loading')">
                            <div v-for="n in 3" :key="n" class="skeleton h-14 w-full" />
                        </div>
                        <div v-else class="grid gap-2" role="radiogroup" :aria-label="t('identity.welcome.work_title')">
                            <button
                                v-for="sector in sectors.data.value ?? []"
                                :key="sector.key"
                                type="button"
                                role="radio"
                                :aria-checked="form.sector === sector.key"
                                class="flex items-center gap-3 rounded-xl border px-4 py-3 text-start transition-colors"
                                :class="form.sector === sector.key ? 'border-brand bg-brand-soft' : 'border-line hover:bg-subtle'"
                                @click="form.sector = form.sector === sector.key ? null : sector.key"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[14px] font-medium text-fg">{{ sector.name }}</span>
                                    <span v-if="sector.description" class="block text-[12.5px] text-muted">{{ sector.description }}</span>
                                </span>
                                <Check v-if="form.sector === sector.key" class="size-4 text-brand-strong" aria-hidden="true" />
                            </button>
                        </div>
                    </section>

                    <div class="mt-6 flex items-center justify-between gap-3">
                        <AppButton v-if="step > 1" variant="ghost" :icon="ArrowLeft" @click="step -= 1">{{ t('identity.welcome.back') }}</AppButton>
                        <button v-else type="button" class="text-[13px] text-muted hover:text-fg hover:underline" @click="finish">{{ t('identity.welcome.skip') }}</button>
                        <AppButton v-if="step < 3" variant="primary" :icon-end="ArrowRight" @click="step += 1">{{ t('identity.welcome.next') }}</AppButton>
                        <AppButton v-else variant="primary" :loading="saving" :icon-end="Check" @click="finish">{{ t('identity.welcome.finish') }}</AppButton>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
