<script setup>
import { ref, watch } from 'vue';
import { KeyRound, Search } from 'lucide-vue-next';
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
    find();
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
