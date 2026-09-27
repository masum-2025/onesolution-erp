<script setup>
import { computed, reactive, ref } from 'vue';
import { Download, ShieldAlert, Trash2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { api } from '@/lib/http';
import { formatDate } from '@/lib/format';
import { loadMe } from '@/lib/session';
import { i18n, t } from '@/lib/i18n';
import { toast } from '@/lib/toast';

/**
 * The person's own data (Phase 5C-3): download it as a file, or delete the
 * account after a waiting period (and change their mind until then). What
 * would stop a deletion is listed before they try.
 */
const props = defineProps({
    account: { type: Object, required: true },
});
const emit = defineEmits(['changed']);

const downloading = ref(false);
const cancelling = ref(false);
const form = reactive({ open: false, password: '', confirm: '', errors: {}, busy: false });

const due = computed(() => props.account.deletion_due_at);
const blockers = computed(() => props.account.deletion_blockers ?? []);

async function download() {
    downloading.value = true;
    try {
        const response = await fetch('/api/me/data', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Locale': i18n.locale },
        });
        if (!response.ok) throw new Error(response.status === 429 ? t('identity.privacy.download_limit') : t('core.errors.server'));

        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = (response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/) ?? [])[1] ?? 'my-data.json';
        link.click();
        URL.revokeObjectURL(url);
    } catch (error) {
        toast.error(error.message);
    } finally {
        downloading.value = false;
    }
}

function openDeletion() {
    Object.assign(form, { open: true, password: '', confirm: '', errors: {}, busy: false });
}

async function requestDeletion() {
    form.errors = {};
    if (!form.password) form.errors.current_password = t('identity.errors.current_password');
    if (form.confirm.trim().toUpperCase() !== 'DELETE') form.errors.confirm = t('identity.privacy.type_delete');
    if (Object.keys(form.errors).length) return;

    form.busy = true;
    try {
        const response = await api('/api/me/deletion', { method: 'POST', body: { current_password: form.password, confirm: form.confirm } });
        form.open = false;
        toast.success(response.message);
        await loadMe();
        emit('changed');
    } catch (error) {
        if (error.code === 'wrong_password') form.errors.current_password = error.message;
        else if (error.code === 'deletion_blocked') {
            form.open = false;
            toast.error(error.message);
            emit('changed');
        } else form.errors.confirm = error.message;
    } finally {
        form.busy = false;
    }
}

async function cancelDeletion() {
    cancelling.value = true;
    try {
        const response = await api('/api/me/deletion', { method: 'DELETE' });
        toast.success(response.message);
        await loadMe();
        emit('changed');
    } catch (error) {
        toast.error(error.message);
    } finally {
        cancelling.value = false;
    }
}
</script>

<template>
    <section class="card p-5">
        <h2 class="flex items-center gap-2 text-[15px] font-semibold text-fg"><Download class="size-4 text-muted" aria-hidden="true" />{{ t('identity.privacy.data_title') }}</h2>
        <p class="mt-1.5 text-[13px] leading-relaxed text-muted">{{ t('identity.privacy.data_text') }}</p>
        <div class="mt-4 flex flex-wrap gap-2">
            <AppButton :icon="Download" :loading="downloading" @click="download">{{ t('identity.privacy.download') }}</AppButton>
            <AppButton variant="ghost" to="/export">{{ t('identity.privacy.workspace_export') }}</AppButton>
        </div>
    </section>

    <section class="card border-bad/25 p-5">
        <h2 class="flex items-center gap-2 text-[15px] font-semibold text-fg"><Trash2 class="size-4 text-bad" aria-hidden="true" />{{ t('identity.privacy.delete_title') }}</h2>

        <template v-if="due">
            <p class="mt-2 text-[13.5px] leading-relaxed text-fg-2">{{ t('identity.privacy.pending', { date: formatDate(due) }) }}</p>
            <AppButton class="mt-4" variant="primary" :loading="cancelling" @click="cancelDeletion">{{ t('identity.privacy.cancel') }}</AppButton>
        </template>

        <template v-else>
            <p class="mt-1.5 text-[13px] leading-relaxed text-muted">{{ t('identity.privacy.delete_text') }}</p>
            <div v-if="blockers.length" class="mt-4 rounded-xl border border-warn/25 bg-warn-soft px-4 py-3 text-[13px] text-fg-2" role="status">
                <p class="flex items-center gap-2 font-medium text-fg"><ShieldAlert class="size-4 shrink-0 text-warn" aria-hidden="true" />{{ t('identity.privacy.blocked') }}</p>
                <ul class="mt-2 list-disc space-y-1 ps-6">
                    <li v-for="blocker in blockers" :key="blocker.code + blocker.name">{{ blocker.message }}</li>
                </ul>
            </div>
            <AppButton class="mt-4" variant="danger-soft" :icon="Trash2" :disabled="blockers.length > 0" @click="openDeletion">{{ t('identity.privacy.delete') }}</AppButton>
        </template>
    </section>

    <AppDialog :open="form.open" :title="t('identity.privacy.dialog_title')" :description="t('identity.privacy.dialog_text')" :icon="Trash2" tone="bad" size="sm" @close="form.open = false">
        <form id="delete-account" class="space-y-4" novalidate @submit.prevent="requestDeletion">
            <AppField :label="t('identity.fields.current_password')" :error="form.errors.current_password">
                <template #default="{ id, invalid }">
                    <input :id="id" v-model="form.password" type="password" autocomplete="current-password" class="field-input" :aria-invalid="invalid || undefined" />
                </template>
            </AppField>
            <AppField :label="t('identity.privacy.type_delete')" :error="form.errors.confirm">
                <template #default="{ id, invalid }">
                    <input :id="id" v-model="form.confirm" type="text" autocomplete="off" spellcheck="false" dir="ltr" class="field-input" placeholder="DELETE" :aria-invalid="invalid || undefined" />
                </template>
            </AppField>
        </form>
        <template #footer>
            <AppButton @click="form.open = false">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton type="submit" form="delete-account" variant="danger" :loading="form.busy">{{ t('identity.privacy.confirm') }}</AppButton>
        </template>
    </AppDialog>
</template>
