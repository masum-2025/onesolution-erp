<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { KeyRound, Laptop, Lock, Mail, MonitorSmartphone, Smartphone, UserRound } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import ContactDialog from './ContactDialog.vue';
import PrivacySection from './PrivacySection.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { passwordProblem } from '@/lib/identity';
import { confirmAction } from '@/lib/dialogs';
import { formatDateTime, formatRelative } from '@/lib/format';
import { loadMe, logout } from '@/lib/session';
import { i18n, setLocale, t } from '@/lib/i18n';
import { toast } from '@/lib/toast';

/**
 * My account: the person's own name, language, email and phone, password
 * and signed-in devices. Same in every organization they work in.
 */
const router = useRouter();
const account = useResource(() => api('/api/me/account').then((response) => response.data));
const sessions = useResource(() => api('/api/me/sessions').then((response) => response.data));
const me = computed(() => account.data.value);

const profile = reactive({ name: '', locale: 'en', timezone: '', marketing: false });
// The person's own timezone (Phase 6); empty = the organization's.
const timezones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];
const savingProfile = ref(false);
const password = reactive({ current: '', next: '' });
const passwordErrors = reactive({});
const savingPassword = ref(false);
const contact = reactive({ open: false, channel: 'mail' });

watch(me, (value) => {
    if (!value) return;
    Object.assign(profile, { name: value.name, locale: value.locale ?? i18n.locale, timezone: value.timezone ?? '', marketing: value.marketing });
});

const languageOptions = computed(() => i18n.locales.map((locale) => ({ value: locale, label: t(`core.languages.${locale}`) })));

async function saveProfile() {
    savingProfile.value = true;
    try {
        const response = await api('/api/me/account', { method: 'PATCH', body: { name: profile.name.trim(), locale: profile.locale, timezone: profile.timezone || null, marketing: profile.marketing } });
        account.data.value = response.data;
        if (profile.locale !== i18n.locale) await setLocale(profile.locale);
        await loadMe();
        toast.success(response.message);
    } catch (error) {
        toast.error(error.field?.('name') ?? error.field?.('timezone') ?? error.message);
    } finally {
        savingProfile.value = false;
    }
}

async function savePassword() {
    for (const key of Object.keys(passwordErrors)) delete passwordErrors[key];
    if (!password.current) passwordErrors.current_password = t('identity.errors.current_password');
    if (passwordProblem(password.next)) passwordErrors.password = t('identity.fields.password_rules');
    if (Object.keys(passwordErrors).length) return;

    savingPassword.value = true;
    try {
        const response = await api('/api/me/password', { method: 'PUT', body: { current_password: password.current, password: password.next, password_confirmation: password.next } });
        Object.assign(password, { current: '', next: '' });
        toast.success(response.message);
        sessions.reload();
    } catch (error) {
        passwordErrors[error.data?.field ?? 'password'] = error.field?.('password') ?? error.message;
    } finally {
        savingPassword.value = false;
    }
}

function openContact(channel) {
    Object.assign(contact, { open: true, channel });
}

function contactSaved(response) {
    account.data.value = response.data;
    contact.open = false;
    toast.success(response.message);
    loadMe();
}

const removal = reactive({ open: false, password: '', error: null, busy: false });

async function removePhone() {
    removal.error = null;
    removal.busy = true;
    try {
        const response = await api('/api/me/phone', { method: 'DELETE', body: { current_password: removal.password } });
        account.data.value = response.data;
        Object.assign(removal, { open: false, password: '' });
        toast.success(response.message);
    } catch (error) {
        removal.error = error.message;
    } finally {
        removal.busy = false;
    }
}

async function endSession(item) {
    try {
        toast.success((await api(`/api/me/sessions/${item.id}`, { method: 'DELETE' })).message);
    } catch (error) {
        toast.error(error.message);
    }
    sessions.reload();
}

async function endOthers() {
    const answer = await confirmAction({ title: t('identity.account.end_others_title'), message: t('identity.account.end_others_text'), confirmLabel: t('identity.account.end_others') });
    if (!answer) return;
    try {
        toast.success((await api('/api/me/sessions', { method: 'DELETE' })).message);
    } catch (error) {
        toast.error(error.message);
    }
    sessions.reload();
}

async function signOutHere() {
    await logout();
    await router.push({ name: 'login' });
}

function deviceName(item) {
    const browser = item.browser ?? t('identity.account.unknown_browser');
    return item.platform ? t('identity.account.device', { browser, platform: item.platform }) : browser;
}
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <PageHeader :title="t('identity.account.title')" :description="t('identity.account.text')" />

        <SkeletonRows v-if="account.loading.value && !me" :rows="6" />
        <ErrorState v-else-if="account.error.value" :error="account.error.value" @retry="account.reload()" />

        <div v-else-if="me" class="space-y-6">
            <p v-if="me.locked_until" class="flex items-start gap-2.5 rounded-xl border border-warn/25 bg-warn-soft px-4 py-3 text-[13px] text-fg-2" role="status">
                <Lock class="mt-px size-4 shrink-0 text-warn" aria-hidden="true" />{{ t('identity.account.locked', { date: formatDateTime(me.locked_until) }) }}
            </p>

            <!-- Profile -->
            <form class="card space-y-4 p-5" novalidate @submit.prevent="saveProfile">
                <h2 class="flex items-center gap-2 text-[15px] font-semibold text-fg"><UserRound class="size-4 text-muted" aria-hidden="true" />{{ t('identity.account.profile') }}</h2>
                <AppField :label="t('identity.fields.name')">
                    <template #default="{ id }">
                        <input :id="id" v-model="profile.name" autocomplete="name" maxlength="120" class="field-input" />
                    </template>
                </AppField>
                <div>
                    <p class="mb-1.5 text-[13px] font-medium text-fg">{{ t('identity.fields.language') }}</p>
                    <AppSegmented v-model="profile.locale" :options="languageOptions" :label="t('identity.fields.language')" />
                </div>
                <AppField :label="t('identity.fields.timezone')" :hint="t('identity.fields.timezone_hint')" optional>
                    <template #default="{ id, describedby }">
                        <select :id="id" v-model="profile.timezone" class="field-input" :aria-describedby="describedby">
                            <option value="">{{ t('identity.fields.timezone_organization') }}</option>
                            <option v-for="zone in timezones" :key="zone" :value="zone" dir="ltr">{{ zone.replace(/_/g, ' ') }}</option>
                        </select>
                    </template>
                </AppField>
                <div><AppSwitch v-model="profile.marketing" :label="t('identity.signup.marketing')" show-label /></div>
                <AppButton type="submit" variant="primary" :loading="savingProfile">{{ t('core.actions.save') }}</AppButton>
            </form>

            <!-- How they sign in -->
            <section class="card divide-y divide-line">
                <h2 class="px-5 py-4 text-[15px] font-semibold text-fg">{{ t('identity.account.sign_in') }}</h2>
                <div class="flex flex-wrap items-center gap-3 px-5 py-4">
                    <Mail class="size-5 shrink-0 text-muted" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-[13px] text-muted">{{ t('identity.fields.email') }}</p>
                        <p class="truncate text-[14px] font-medium text-fg" dir="ltr">{{ me.email ?? t('identity.account.not_added') }}</p>
                    </div>
                    <AppBadge v-if="me.email" :tone="me.email_verified ? 'ok' : 'outline'" dot>{{ t(me.email_verified ? 'identity.account.verified' : 'identity.account.not_verified') }}</AppBadge>
                    <AppButton size="sm" :disabled="!!me.locked_until" @click="openContact('mail')">{{ t(me.email ? 'identity.account.change' : 'identity.account.add') }}</AppButton>
                </div>
                <div v-if="me.options.phone || me.phone" class="flex flex-wrap items-center gap-3 px-5 py-4">
                    <Smartphone class="size-5 shrink-0 text-muted" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-[13px] text-muted">{{ t('identity.fields.phone') }}</p>
                        <p class="truncate text-[14px] font-medium text-fg" dir="ltr">{{ me.phone ?? t('identity.account.not_added') }}</p>
                    </div>
                    <AppBadge v-if="me.phone" :tone="me.phone_verified ? 'ok' : 'outline'" dot>{{ t(me.phone_verified ? 'identity.account.verified' : 'identity.account.not_verified') }}</AppBadge>
                    <AppButton v-if="me.options.phone" size="sm" :disabled="!!me.locked_until" @click="openContact('sms')">{{ t(me.phone ? 'identity.account.change' : 'identity.account.add') }}</AppButton>
                    <AppButton v-if="me.phone && me.email" size="sm" variant="danger-soft" :disabled="!!me.locked_until" @click="Object.assign(removal, { open: true, password: '', error: null })">{{ t('identity.account.remove') }}</AppButton>
                </div>
            </section>

            <!-- Password -->
            <form class="card space-y-4 p-5" novalidate @submit.prevent="savePassword">
                <div>
                    <h2 class="flex items-center gap-2 text-[15px] font-semibold text-fg"><KeyRound class="size-4 text-muted" aria-hidden="true" />{{ t('identity.account.password') }}</h2>
                    <p class="mt-1 text-[12.5px] text-muted">
                        {{ me.password_changed_at ? t('identity.account.password_changed_at', { date: formatDateTime(me.password_changed_at) }) : t('identity.account.password_text') }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField :label="t('identity.fields.current_password')" :error="passwordErrors.current_password">
                        <template #default="{ id, invalid }">
                            <input :id="id" v-model="password.current" type="password" autocomplete="current-password" class="field-input" :aria-invalid="invalid || undefined" />
                        </template>
                    </AppField>
                    <AppField :label="t('identity.fields.new_password')" :hint="t('identity.fields.password_rules')" :error="passwordErrors.password">
                        <template #default="{ id, invalid, describedby }">
                            <input :id="id" v-model="password.next" type="password" autocomplete="new-password" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                        </template>
                    </AppField>
                </div>
                <p class="text-[12.5px] text-muted">{{ t('identity.account.password_effect') }}</p>
                <AppButton type="submit" variant="primary" :loading="savingPassword">{{ t('identity.account.change_password') }}</AppButton>
            </form>

            <!-- Devices -->
            <section class="card">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
                    <h2 class="flex items-center gap-2 text-[15px] font-semibold text-fg"><MonitorSmartphone class="size-4 text-muted" aria-hidden="true" />{{ t('identity.account.devices') }}</h2>
                    <AppButton v-if="(sessions.data.value ?? []).length > 1" size="sm" variant="danger-soft" @click="endOthers">{{ t('identity.account.end_others') }}</AppButton>
                </header>
                <SkeletonRows v-if="sessions.loading.value && !sessions.data.value" :rows="2" />
                <ErrorState v-else-if="sessions.error.value" compact :error="sessions.error.value" @retry="sessions.reload()" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in sessions.data.value" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                        <component :is="item.platform === 'Android' || item.platform === 'iOS' ? Smartphone : Laptop" class="size-5 shrink-0 text-muted" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                                {{ deviceName(item) }}
                                <AppBadge v-if="item.current" tone="ok" dot>{{ t('identity.account.this_device') }}</AppBadge>
                            </p>
                            <p class="text-[12px] text-muted"><span dir="ltr">{{ item.ip }}</span> · {{ t('identity.account.last_seen', { when: formatRelative(item.last_seen_at) }) }}</p>
                        </div>
                        <AppButton v-if="item.current" size="sm" @click="signOutHere">{{ t('core.auth.sign_out') }}</AppButton>
                        <AppButton v-else size="sm" variant="danger-soft" @click="endSession(item)">{{ t('identity.account.end') }}</AppButton>
                    </li>
                </ul>
            </section>

            <!-- My data and deleting the account (Phase 5C-3) -->
            <PrivacySection :account="me" @changed="account.reload()" />
        </div>

        <AppDialog :open="removal.open" :title="t('identity.account.remove_phone_title')" :description="t('identity.account.remove_phone_text')" :icon="Smartphone" tone="bad" size="sm" @close="removal.open = false">
            <form id="remove-phone" novalidate @submit.prevent="removePhone">
                <AppField :label="t('identity.fields.current_password')" :error="removal.error">
                    <template #default="{ id, invalid }">
                        <input :id="id" v-model="removal.password" type="password" autocomplete="current-password" class="field-input" :aria-invalid="invalid || undefined" />
                    </template>
                </AppField>
            </form>
            <template #footer>
                <AppButton @click="removal.open = false">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" form="remove-phone" variant="danger" :loading="removal.busy" :disabled="!removal.password">{{ t('identity.account.remove') }}</AppButton>
            </template>
        </AppDialog>
        <ContactDialog :open="contact.open" :channel="contact.channel" :countries="me?.options.phone_countries ?? []" @close="contact.open = false" @saved="contactSaved" />
    </div>
</template>
