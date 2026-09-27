<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { DoorOpen, TriangleAlert } from 'lucide-vue-next';
import AuthTopBar from '@/layouts/AuthTopBar.vue';
import BrandLockup from '@/components/BrandLockup.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import CodeEntry from '@/components/CodeEntry.vue';
import { api } from '@/lib/http';
import { passwordProblem } from '@/lib/identity';
import { formatDate } from '@/lib/format';
import { resetCaches } from '@/lib/cache';
import { enterContext, loadMe, session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Joining a client's portal (Phase 5C-4) with the link or the code from an
 * invitation. The invitation is bound to an email or phone: a signed-in
 * person must have it verified; someone new gets a code there.
 */
const route = useRoute();
const router = useRouter();

const key = ref(String(route.params.key ?? ''));
const typed = ref('');
const invitation = ref(null);
const problem = ref(null);
const loading = ref(false);
const busy = ref(false);
const mode = ref('new');
const form = reactive({ name: '', password: '' });
const errors = reactive({});
const challenge = ref(null);
const codeError = ref(null);

const signedIn = computed(() => session.me !== null);

async function look(value) {
    problem.value = null;
    loading.value = true;
    try {
        invitation.value = (await api(`/session/portal/invitations/${encodeURIComponent(value.trim())}`)).data;
        key.value = value.trim();
        form.name ||= invitation.value.name;
    } catch (error) {
        invitation.value = null;
        problem.value = error.message;
    } finally {
        loading.value = false;
    }
}

async function enter(response) {
    if (response.message) toast.success(response.message);
    resetCaches();
    await loadMe();
    await enterContext({ organization_id: response.data.organization_id });
    await router.push({ name: 'portal-home' });
}

async function join() {
    busy.value = true;
    try {
        await enter(await api('/session/portal/join', { method: 'POST', body: { key: key.value } }));
    } catch (error) {
        problem.value = error.message;
    } finally {
        busy.value = false;
    }
}

async function signup() {
    for (const field of Object.keys(errors)) delete errors[field];
    if (form.name.trim().length < 2) errors.name = t('identity.errors.name');
    if (passwordProblem(form.password)) errors.password = t('identity.fields.password_rules');
    if (Object.keys(errors).length) return;

    busy.value = true;
    try {
        const response = await api('/session/portal/signup', {
            method: 'POST',
            body: { key: key.value, name: form.name.trim(), password: form.password, password_confirmation: form.password },
        });
        challenge.value = response.data;
    } catch (error) {
        if (error.code === 'account_exists') mode.value = 'existing';
        problem.value = error.message;
    } finally {
        busy.value = false;
    }
}

async function verify(code) {
    codeError.value = null;
    busy.value = true;
    try {
        await enter(await api('/session/portal/signup/verify', { method: 'POST', body: { challenge_id: challenge.value.challenge_id, code } }));
    } catch (error) {
        codeError.value = error.message;
        if (error.data?.restart) challenge.value = null;
    } finally {
        busy.value = false;
    }
}

onMounted(() => {
    if (key.value) look(key.value);
});
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-canvas">
        <AuthTopBar />
        <main class="flex flex-1 items-center justify-center px-4 py-10">
            <div class="w-full max-w-sm">
                <BrandLockup class="mb-8" />

                <!-- The code sent back by email or SMS, for a new account -->
                <div v-if="challenge" class="card space-y-4 p-6">
                    <h1 class="text-[20px] font-semibold text-fg">{{ t('identity.code.title') }}</h1>
                    <CodeEntry
                        :challenge="challenge"
                        :error="codeError"
                        :busy="busy"
                        :submit-label="t('portal.join.create')"
                        @submit="verify"
                        @back="challenge = null"
                        @resent="(data) => (challenge = { ...challenge, ...data })"
                    />
                </div>

                <!-- What the invitation is for -->
                <div v-else-if="invitation" class="card space-y-5 p-6">
                    <div class="flex items-start gap-3">
                        <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text">
                            <DoorOpen class="size-5" aria-hidden="true" />
                        </div>
                        <div>
                            <h1 class="text-[18px] font-semibold text-fg">{{ invitation.organization }}</h1>
                            <p class="mt-1 text-[13.5px] leading-relaxed text-fg-2">{{ t('portal.join.for', { org: invitation.organization, name: invitation.name, kind: invitation.kind }) }}</p>
                        </div>
                    </div>
                    <p class="rounded-lg bg-subtle px-3 py-2 text-[12.5px] text-fg-2">
                        {{ t('portal.join.bound', { to: invitation.to }) }} {{ t('portal.join.expires', { date: formatDate(invitation.expires_at) }) }}
                    </p>

                    <p v-if="problem" class="flex gap-2 text-[13px] text-bad" role="alert"><TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ problem }}</p>

                    <template v-if="signedIn">
                        <p class="text-[13px] text-muted">{{ t('portal.join.signed_in_as', { name: session.me.user.name }) }}</p>
                        <AppButton variant="primary" size="lg" block :loading="busy" @click="join">{{ t('portal.join.join') }}</AppButton>
                    </template>

                    <template v-else>
                        <AppSegmented
                            v-model="mode"
                            block
                            :label="t('portal.join.title')"
                            :options="[
                                { value: 'new', label: t('portal.join.new_account') },
                                { value: 'existing', label: t('portal.join.have_account') },
                            ]"
                        />

                        <AppButton v-if="mode === 'existing'" variant="primary" size="lg" block :to="{ name: 'login', query: { redirect: `/portal/join/${key}` } }">
                            {{ t('portal.join.sign_in') }}
                        </AppButton>

                        <form v-else class="space-y-4" novalidate @submit.prevent="signup">
                            <AppField :label="t('portal.join.name')" :error="errors.name">
                                <template #default="{ id, invalid }">
                                    <input :id="id" v-model="form.name" autocomplete="name" maxlength="120" class="field-input" :aria-invalid="invalid || undefined" />
                                </template>
                            </AppField>
                            <AppField :label="t('portal.join.password')" :hint="t('identity.fields.password_rules')" :error="errors.password">
                                <template #default="{ id, invalid, describedby }">
                                    <input :id="id" v-model="form.password" type="password" autocomplete="new-password" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                                </template>
                            </AppField>
                            <AppButton type="submit" variant="primary" size="lg" block :loading="busy">{{ t('portal.join.send_code') }}</AppButton>
                        </form>
                    </template>

                    <button type="button" class="w-full text-center text-[13px] text-muted hover:text-fg" @click="invitation = null; problem = null">{{ t('portal.join.other_code') }}</button>
                </div>

                <!-- Typing a code -->
                <form v-else class="card space-y-4 p-6" novalidate @submit.prevent="look(typed)">
                    <h1 class="text-[20px] font-semibold text-fg">{{ t('portal.join.title') }}</h1>
                    <p class="text-[13.5px] text-muted">{{ t('portal.join.text') }}</p>
                    <AppField :label="t('portal.join.code')" :error="problem">
                        <template #default="{ id, invalid }">
                            <input
                                :id="id"
                                v-model="typed"
                                autocomplete="one-time-code"
                                autocapitalize="characters"
                                spellcheck="false"
                                dir="ltr"
                                maxlength="64"
                                class="field-input tabular tracking-[0.12em] uppercase"
                                :placeholder="t('portal.join.code_placeholder')"
                                :aria-invalid="invalid || undefined"
                            />
                        </template>
                    </AppField>
                    <AppButton type="submit" variant="primary" size="lg" block :loading="loading" :disabled="typed.trim().length < 10">{{ t('portal.join.check') }}</AppButton>
                </form>
            </div>
        </main>
    </div>
</template>
