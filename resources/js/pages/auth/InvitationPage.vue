<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Eye, EyeOff, TriangleAlert } from 'lucide-vue-next';
import AuthTopBar from '@/layouts/AuthTopBar.vue';
import BrandLockup from '@/components/BrandLockup.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import { api } from '@/lib/http';
import { loadMe } from '@/lib/session';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * The page behind an invitation link: set a password and start. The link
 * works once and for a few days.
 */
const route = useRoute();
const router = useRouter();
const invitation = ref(null);
const invalid = ref(false);
const form = reactive({ password: '', confirm: '' });
const errors = reactive({ password: null, confirm: null });
const show = ref(false);
const saving = ref(false);

onMounted(async () => {
    try {
        invitation.value = (await api(`/session/invitations/${route.params.token}`)).data;
    } catch {
        invalid.value = true;
    }
});

async function submit() {
    errors.password = form.password.length < 10 || !/[A-Za-z]/.test(form.password) || !/\d/.test(form.password) ? t('invite.password_rules') : null;
    errors.confirm = form.confirm !== form.password ? t('invite.mismatch') : null;
    if (errors.password || errors.confirm) return;

    saving.value = true;
    try {
        await api(`/session/invitations/${route.params.token}`, { method: 'POST', body: { password: form.password, password_confirmation: form.confirm } });
        const me = await loadMe();
        await router.push(me?.context ? '/' : { name: 'choose', query: { auto: '1' } });
    } catch (error) {
        errors.password = error.field?.('password') ?? error.message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-canvas">
        <AuthTopBar />
        <main class="flex flex-1 items-center justify-center px-4 py-10">
            <div class="w-full max-w-sm">
                <BrandLockup class="mb-8" />

                <div v-if="invalid" class="card p-6 text-center" role="alert">
                    <TriangleAlert class="mx-auto size-8 text-warn" aria-hidden="true" />
                    <h1 class="mt-3 text-[18px] font-semibold text-fg">{{ t('invite.invalid_title') }}</h1>
                    <p class="mt-2 text-[13.5px] text-muted">{{ t('invite.invalid_text') }}</p>
                    <AppButton class="mt-5" to="/login">{{ t('invite.to_login') }}</AppButton>
                </div>

                <div v-else-if="!invitation" class="card space-y-3 p-6" role="status" :aria-label="t('core.states.loading')">
                    <div class="skeleton h-5 w-2/3" /><div class="skeleton h-10 w-full" /><div class="skeleton h-10 w-full" />
                </div>

                <form v-else class="card space-y-4 p-6" novalidate @submit.prevent="submit">
                    <div>
                        <h1 class="text-[20px] font-semibold text-fg">{{ t('invite.title', { name: invitation.name }) }}</h1>
                        <p class="mt-1 text-[13.5px] text-muted">{{ t('invite.text', { organization: invitation.organization }) }}</p>
                        <p class="mt-1 text-[12.5px] text-faint" dir="ltr">{{ invitation.email }}</p>
                    </div>
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
                    <AppButton type="submit" variant="primary" block :loading="saving">{{ t('invite.submit') }}</AppButton>
                    <p class="text-center text-[12px] text-faint">{{ t('invite.expires', { date: formatDateTime(invitation.expires_at) }) }}</p>
                </form>
            </div>
        </main>
    </div>
</template>
