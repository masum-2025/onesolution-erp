<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ArrowLeft, Eye, EyeOff, KeyRound, ShieldCheck } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { hrmApi } from '../api';

/**
 * Employees' logins at this company: the first password they get (kept as a
 * hash, so it is never shown again, only replaced) and the roles new logins
 * get. Employees choose their own password at first sign-in; how long the
 * first one works is the rule hrm.initial_password_days.
 */
const org = currentOrganization();
const hrm = hrmApi(org.id);
const settings = useResource(() => hrm.loginSettings());
const data = computed(() => settings.data.value?.data ?? null);

const form = reactive({ password: '', confirm: '', role_ids: [] });
const errors = reactive({ password: null, confirm: null, role_ids: null });
const show = ref(false);
const saving = ref(false);
watch(data, (value) => {
    if (value) form.role_ids = [...value.role_ids];
}, { immediate: true });

async function save() {
    errors.password = form.password && (form.password.length < 10 || !/[A-Za-z]/.test(form.password) || !/\d/.test(form.password)) ? t('hrm.first_login.password_rules') : null;
    errors.confirm = form.password && form.confirm !== form.password ? t('hrm.first_login.mismatch') : null;
    errors.role_ids = null;
    if (!data.value.password_set && !form.password) errors.password = t('hrm.first_login.password_needed');
    if (errors.password || errors.confirm) return;

    saving.value = true;
    try {
        const response = await hrm.saveLoginSettings({
            base_version: data.value.version,
            ...(form.password ? { password: form.password, password_confirmation: form.confirm } : {}),
            role_ids: form.role_ids,
        });
        settings.data.value = response;
        form.password = '';
        form.confirm = '';
        toast.success(t('hrm.first_login.saved'));
    } catch (error) {
        if (error.code === 'version_conflict') settings.reload();
        errors.password = error.errors?.password?.[0] ?? null;
        errors.role_ids = error.errors?.role_ids?.[0] ?? null;
        if (!errors.password && !errors.role_ids) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('hrm.first_login.page_title')" :description="t('hrm.first_login.page_text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'hrm' }" :icon="ArrowLeft">{{ t('hrm.profile.back') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="settings.loading.value && !data" :rows="4" />
        <ErrorState v-else-if="settings.error.value" :error="settings.error.value" @retry="settings.reload()" />
        <form v-else-if="data" class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]" novalidate @submit.prevent="save">
            <section class="card space-y-5 p-5 sm:p-6">
                <div>
                    <h2 class="flex items-center gap-2 text-[15px] font-semibold text-fg"><KeyRound class="size-4 text-brand-text" aria-hidden="true" />{{ t('hrm.first_login.password_title') }}</h2>
                    <p class="mt-1 text-[13px] text-muted">
                        <template v-if="data.password_set">{{ t('hrm.first_login.password_is_set', { when: formatDateTime(data.password_set_at), by: data.password_set_by ?? '–' }) }}</template>
                        <template v-else>{{ t('hrm.first_login.password_not_set') }}</template>
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField :label="data.password_set ? t('hrm.first_login.new_password') : t('hrm.first_login.password')" :hint="t('hrm.first_login.password_rules')" :error="errors.password">
                        <template #default="{ id, invalid: bad, describedby }">
                            <div class="relative">
                                <input :id="id" v-model="form.password" :type="show ? 'text' : 'password'" autocomplete="new-password" class="field-input pe-10" :aria-invalid="bad || undefined" :aria-describedby="describedby" />
                                <button type="button" class="absolute inset-y-0 end-0 grid w-10 place-items-center text-muted" :aria-label="t(show ? 'hrm.first_login.hide' : 'hrm.first_login.show')" @click="show = !show">
                                    <EyeOff v-if="show" class="size-4" aria-hidden="true" /><Eye v-else class="size-4" aria-hidden="true" />
                                </button>
                            </div>
                        </template>
                    </AppField>
                    <AppField :label="t('hrm.first_login.confirm')" :error="errors.confirm">
                        <template #default="{ id, invalid: bad }">
                            <input :id="id" v-model="form.confirm" :type="show ? 'text' : 'password'" autocomplete="new-password" class="field-input" :aria-invalid="bad || undefined" />
                        </template>
                    </AppField>
                </div>

                <fieldset>
                    <legend class="text-[13.5px] font-semibold text-fg">{{ t('hrm.first_login.roles_title') }}</legend>
                    <p class="mt-0.5 text-[12.5px] text-muted">{{ t('hrm.first_login.roles_text') }}</p>
                    <p v-if="!data.roles.length" class="mt-2 text-[12.5px] text-muted">{{ t('hrm.first_login.no_roles') }}</p>
                    <div v-else class="mt-2 grid gap-1.5 sm:grid-cols-2">
                        <label v-for="role in data.roles" :key="role.id" class="flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-[13.5px] hover:bg-subtle/60">
                            <input v-model="form.role_ids" type="checkbox" :value="role.id" class="size-4 accent-brand" />
                            <span class="min-w-0 truncate">{{ role.name }}</span>
                        </label>
                    </div>
                    <p v-if="errors.role_ids" class="mt-2 text-[12.5px] text-bad" role="alert">{{ errors.role_ids }}</p>
                </fieldset>

                <div class="flex justify-end">
                    <AppButton type="submit" variant="primary" :loading="saving">{{ t('hrm.first_login.save') }}</AppButton>
                </div>
            </section>

            <aside class="card h-fit space-y-2 p-5 text-[13px] text-fg-2">
                <h2 class="flex items-center gap-2 text-[14px] font-semibold text-fg"><ShieldCheck class="size-4 text-ok" aria-hidden="true" />{{ t('hrm.first_login.how_title') }}</h2>
                <p>{{ t('hrm.first_login.how_1') }}</p>
                <p>{{ t('hrm.first_login.how_2', { days: data.days }) }}</p>
                <p>{{ t('hrm.first_login.how_3') }}</p>
                <p>{{ t('hrm.first_login.how_4') }}</p>
            </aside>
        </form>
    </div>
</template>
