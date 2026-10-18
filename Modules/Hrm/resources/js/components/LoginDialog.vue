<script setup>
import { ref, watch } from 'vue';
import { KeyRound, Search, Send } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Link the employee to the login they use (an active staff or portal member
 * of the company, not linked to someone else), so they can check in and see
 * their own records. Search by name or email.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    employee: { type: Object, required: true },
    hrm: { type: Object, required: true },
});
const emit = defineEmits(['close', 'linked', 'conflict']);

const search = ref('');
const results = ref([]);
const loading = ref(false);
const loadError = ref(null);
const saving = ref(null);
let timer = null;

// A new login by invitation: their email (from the record), a role, one step.
const invite = ref({ email: '', role_id: '' });
const inviteError = ref(null);
const inviting = ref(false);
const roles = ref([]);
async function loadRoles() {
    try {
        roles.value = ((await props.hrm.roles()).data ?? []).filter((role) => role.assignable);
    } catch {
        roles.value = [];
    }
}

async function sendInvite() {
    inviteError.value = null;
    inviting.value = true;
    try {
        const { data, meta } = await props.hrm.inviteLogin(props.employee.id, {
            base_version: props.employee.version,
            email: invite.value.email.trim() || null,
            ...(invite.value.role_id ? { role_ids: [invite.value.role_id] } : {}),
        });
        toast.success(meta?.invited ? t('hrm.login.invited', { email: data.login?.email ?? '' }) : t('hrm.login.linked', { name: data.login?.name ?? '' }));
        emit('linked', data);
    } catch (error) {
        if (error.code === 'version_conflict') emit('conflict');
        inviteError.value = error.errors?.email?.[0] ?? error.message;
    } finally {
        inviting.value = false;
    }
}

async function find() {
    loading.value = true;
    loadError.value = null;
    try {
        results.value = (await props.hrm.logins(props.employee.id, search.value.trim())).data;
    } catch (error) {
        loadError.value = error;
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (open) => {
    if (!open) return;
    search.value = '';
    invite.value = { email: props.employee.email ?? '', role_id: '' };
    inviteError.value = null;
    find();
    if (!roles.value.length) loadRoles();
});
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(find, 300);
});

async function link(user) {
    saving.value = user.id;
    try {
        const { data } = await props.hrm.linkLogin(props.employee.id, { base_version: props.employee.version, user_id: user.id });
        toast.success(t('hrm.login.linked', { name: user.name }));
        emit('linked', data);
    } catch (error) {
        if (error.code === 'version_conflict') emit('conflict');
        toast.error(error.errors?.user_id?.[0] ?? error.message);
    } finally {
        saving.value = null;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('hrm.login.title')" :description="t('hrm.login.text', { name: employee.full_name })" :icon="KeyRound" @close="emit('close')">
        <!-- New: an invitation to their email, no sign-up for them. -->
        <form v-if="!employee.login" class="mb-5 space-y-3 rounded-xl border border-brand/20 bg-brand-soft/40 p-3.5" @submit.prevent="sendInvite">
            <p class="flex items-center gap-2 text-[13.5px] font-semibold text-fg"><Send class="size-4 text-brand-text" aria-hidden="true" />{{ t('hrm.login.invite_title') }}</p>
            <p class="text-[12.5px] text-fg-2">{{ t('hrm.login.invite_text') }}</p>
            <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_12rem]">
                <label class="block">
                    <span class="mb-1 block text-[12.5px] font-medium text-fg">{{ t('hrm.login.invite_email') }}</span>
                    <input v-model="invite.email" type="email" class="field-input" dir="ltr" autocomplete="off" :aria-invalid="!!inviteError || undefined" />
                </label>
                <label v-if="roles.length" class="block">
                    <span class="mb-1 block text-[12.5px] font-medium text-fg">{{ t('hrm.login.invite_role') }}</span>
                    <select v-model="invite.role_id" class="field-input">
                        <option value="">{{ t('hrm.login.invite_no_role') }}</option>
                        <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                    </select>
                </label>
            </div>
            <p v-if="inviteError" class="text-[12.5px] text-bad" role="alert">{{ inviteError }}</p>
            <AppButton type="submit" variant="primary" :icon="Send" :loading="inviting">{{ t('hrm.login.invite_send') }}</AppButton>
        </form>
        <p v-if="!employee.login" class="mb-2 text-[12.5px] font-medium text-muted">{{ t('hrm.login.or_existing') }}</p>
        <label class="relative block">
            <span class="sr-only">{{ t('hrm.login.search') }}</span>
            <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
            <input v-model="search" type="search" class="field-input ps-9" :placeholder="t('hrm.login.search')" autocomplete="off" />
        </label>
        <div class="mt-3 max-h-80 overflow-y-auto">
            <SkeletonRows v-if="loading && !results.length" :rows="3" />
            <ErrorState v-else-if="loadError" compact :error="loadError" @retry="find" />
            <p v-else-if="!results.length" class="px-1 py-4 text-[13px] text-muted">{{ t('hrm.login.empty') }}</p>
            <ul v-else class="divide-y divide-line rounded-xl border border-line">
                <li v-for="user in results" :key="user.id" class="flex items-center gap-3 px-3 py-2">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13.5px] font-medium">{{ user.name }}</span>
                        <span class="block truncate text-[12px] text-muted" dir="ltr">{{ user.email }}</span>
                    </span>
                    <AppButton size="sm" variant="secondary" :loading="saving === user.id" @click="link(user)">{{ t('hrm.login.choose') }}</AppButton>
                </li>
            </ul>
        </div>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('hrm.login.close') }}</AppButton>
        </template>
    </AppDialog>
</template>
