<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Eye, EyeOff, KeyRound } from 'lucide-vue-next';
import AuthTopBar from '@/layouts/AuthTopBar.vue';
import BrandLockup from '@/components/BrandLockup.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import { api } from '@/lib/http';
import { loadMe, logout, session } from '@/lib/session';
import { t } from '@/lib/i18n';

/**
 * Signed in with a first password someone else gave (an employer): the
 * person chooses their own before anything else opens (the API refuses
 * every organization until then: password_change_required).
 */
const router = useRouter();
const form = reactive({ current: '', password: '', confirm: '' });
const errors = reactive({ current: null, password: null, confirm: null });
const show = ref(false);
const saving = ref(false);

async function submit() {
    errors.current = form.current === '' ? t('identity.first_password.current_needed') : null;
    errors.password = form.password.length < 10 || !/[A-Za-z]/.test(form.password) || !/\d/.test(form.password) ? t('invite.password_rules') : null;
    if (!errors.password && form.password === form.current) errors.password = t('identity.first_password.same');
    errors.confirm = form.confirm !== form.password ? t('invite.mismatch') : null;
    if (errors.current || errors.password || errors.confirm) return;

    saving.value = true;
    try {
        await api('/api/me/password', { method: 'PUT', body: { current_password: form.current, password: form.password, password_confirmation: form.confirm } });
        await loadMe();
        await router.push({ name: 'choose', query: { auto: '1' } });
    } catch (error) {
        if (error.code === 'wrong_password') errors.current = error.message;
        else errors.password = error.field?.('password') ?? error.message;
    } finally {
        saving.value = false;
    }
}

async function signOut() {
    await logout();
    await router.push({ name: 'login' });
}
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-canvas">
        <AuthTopBar />
        <main class="flex flex-1 items-center justify-center px-4 py-10">
            <div class="w-full max-w-sm">
                <BrandLockup class="mb-8" />

                <form class="card space-y-4 p-6" novalidate @submit.prevent="submit">
                    <div>
                        <span class="grid size-10 place-items-center rounded-xl bg-brand-soft text-brand-text" aria-hidden="true"><KeyRound class="size-5" /></span>
                        <h1 class="mt-3 text-[20px] font-semibold text-fg">{{ t('identity.first_password.title', { name: session.me?.user?.name ?? '' }) }}</h1>
                        <p class="mt-1 text-[13.5px] text-muted">{{ t('identity.first_password.text') }}</p>
                    </div>
                    <AppField :label="t('identity.first_password.current')" :error="errors.current">
                        <template #default="{ id, invalid: bad }">
                            <input :id="id" v-model="form.current" :type="show ? 'text' : 'password'" autocomplete="current-password" class="field-input" :aria-invalid="bad || undefined" />
                        </template>
                    </AppField>
                    <AppField :label="t('invite.password')" :hint="t('invite.password_rules')" :error="errors.password">
                        <template #default="{ id, invalid: bad, describedby }">
                            <div class="relative">
                                <input :id="id" v-model="form.password" :type="show ? 'text' : 'password'" autocomplete="new-password" class="field-input pe-10" :aria-invalid="bad || undefined" :aria-describedby="describedby" />
                                <button type="button" class="absolute inset-y-0 end-0 grid w-10 place-items-center text-muted" :aria-label="t(show ? 'invite.hide' : 'invite.show')" @click="show = !show">
                                    <EyeOff v-if="show" class="size-4" aria-hidden="true" /><Eye v-else class="size-4" aria-hidden="true" />
                                </button>
                            </div>
                        </template>
                    </AppField>
                    <AppField :label="t('invite.confirm')" :error="errors.confirm">
                        <template #default="{ id, invalid: bad }">
                            <input :id="id" v-model="form.confirm" :type="show ? 'text' : 'password'" autocomplete="new-password" class="field-input" :aria-invalid="bad || undefined" />
                        </template>
                    </AppField>
                    <AppButton type="submit" variant="primary" block :loading="saving">{{ t('identity.first_password.submit') }}</AppButton>
                    <button type="button" class="block w-full text-center text-[13px] text-muted hover:text-fg hover:underline" @click="signOut">{{ t('identity.first_password.sign_out') }}</button>
                </form>
            </div>
        </main>
    </div>
</template>
