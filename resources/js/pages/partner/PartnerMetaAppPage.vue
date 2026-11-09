<script setup>
import { computed, reactive, ref } from 'vue';
import { Check, Copy, Facebook, X } from 'lucide-vue-next';
import { PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDateTime } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * The partner's own Meta app (SC-1): its clients' Facebook pages connect
 * through it, so Facebook shows the partner's app name. The id and secret
 * are checked with Meta, then wait for a second owner (or the waiting
 * time). The secret never comes back: only its last four characters.
 */
const record = useResource(() => api('/api/partner/meta-app'));
const app = computed(() => record.data.value?.data ?? null);
const busy = ref(false);

const editing = reactive({ open: false, app_id: '', app_secret: '', password: '' });
const approving = reactive({ open: false, password: '' });

async function run(call, after) {
    busy.value = true;
    try {
        const response = await call();
        record.data.value = { data: response.data };
        toast.success(response.message);
        after?.();
    } catch (error) {
        toast.error(error.message);
        if (error.status === 409) record.reload();
    } finally {
        busy.value = false;
    }
}

const save = () => run(() => api('/api/partner/meta-app', {
    method: 'PUT',
    body: { app_id: editing.app_id.trim(), app_secret: editing.app_secret.trim(), password: editing.password, base_version: app.value?.version ?? null },
}), () => Object.assign(editing, { open: false, app_secret: '', password: '' }));
const approve = () => run(() => api('/api/partner/meta-app/approve', { method: 'POST', body: { password: approving.password, base_version: app.value.version } }), () => Object.assign(approving, { open: false, password: '' }));
const reject = () => run(() => api('/api/partner/meta-app/reject', { method: 'POST', body: { base_version: app.value.version } }));

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(t('partner.meta.copied'));
    } catch {
        toast.error(t('partner.meta.copy_failed'));
    }
}
</script>

<template>
    <PageFrame :title="t('partner.meta.title')" :description="t('partner.meta.text')" width="md">
        <template #actions>
            <AppButton v-if="app" variant="primary" :icon="Facebook" @click="Object.assign(editing, { open: true, app_id: app.pending?.app_id ?? app.app_id ?? '', app_secret: '', password: '' })">
                {{ app.exists ? t('partner.meta.change') : t('partner.meta.add') }}
            </AppButton>
        </template>

        <SkeletonRows v-if="record.loading.value && !app" :rows="4" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />
        <template v-else-if="app">
            <section class="card grid gap-3 p-5">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-[15px] font-semibold">{{ app.app_name ?? t('partner.meta.none') }}</h2>
                    <AppBadge v-if="app.status" :tone="app.status === 'active' ? 'ok' : 'warn'">{{ t(`partner.meta.status.${app.status}`) }}</AppBadge>
                </div>
                <p v-if="!app.exists" class="text-[13.5px] text-muted">{{ t('partner.meta.none_text') }}</p>
                <dl v-else class="grid gap-2 text-[13.5px] sm:grid-cols-[10rem_1fr]">
                    <dt class="text-muted">{{ t('partner.meta.app_id') }}</dt><dd dir="ltr" class="font-mono">{{ app.app_id ?? '—' }}</dd>
                    <dt class="text-muted">{{ t('partner.meta.app_secret') }}</dt><dd dir="ltr" class="font-mono">{{ app.secret_hint ?? '—' }}</dd>
                    <dt class="text-muted">{{ t('partner.meta.approved_at') }}</dt><dd>{{ app.approved_at ? formatDateTime(app.approved_at) : '—' }}</dd>
                </dl>
            </section>

            <section v-if="app.pending" class="card mt-4 grid gap-3 border-warn/40 p-5">
                <h2 class="text-[14px] font-semibold">{{ t('partner.meta.pending_title') }}</h2>
                <p class="text-[13.5px]">{{ t('partner.meta.pending_text', { name: app.pending.app_name, id: app.pending.app_id, when: formatDateTime(app.pending.at) }) }}</p>
                <p v-if="app.pending.activates_at" class="text-[13px] text-muted">{{ t('partner.meta.activates', { when: formatDateTime(app.pending.activates_at) }) }}</p>
                <div class="flex flex-wrap justify-end gap-2">
                    <AppButton :icon="X" :disabled="busy" @click="reject">{{ t('partner.meta.reject') }}</AppButton>
                    <AppButton v-if="!app.pending.mine" variant="primary" :icon="Check" :disabled="busy" @click="Object.assign(approving, { open: true, password: '' })">{{ t('partner.meta.approve') }}</AppButton>
                    <p v-else class="self-center text-[12.5px] text-muted">{{ app.second_owner ? t('partner.meta.needs_other') : t('partner.meta.no_other', { hours: app.wait_hours }) }}</p>
                </div>
            </section>

            <section v-if="app.callbacks" class="card mt-4 grid gap-3 p-5">
                <h2 class="text-[14px] font-semibold">{{ t('partner.meta.setup_title') }}</h2>
                <p class="text-[13px] text-muted">{{ t('partner.meta.setup_text') }}</p>
                <div v-for="(url, key) in app.callbacks" :key="key" class="grid gap-1">
                    <span class="text-[12.5px] font-medium">{{ t(`partner.meta.callbacks.${key}`) }}</span>
                    <span class="flex items-center gap-2">
                        <code dir="ltr" class="min-w-0 flex-1 truncate rounded-md bg-surface-2 px-2 py-1.5 text-[12.5px]">{{ url }}</code>
                        <AppButton size="icon-sm" variant="ghost" :icon="Copy" :aria-label="t('partner.meta.copy')" @click="copy(url)" />
                    </span>
                </div>
                <p class="text-[12.5px] text-muted">{{ t('partner.meta.permissions') }}</p>
            </section>
        </template>

        <AppDialog :open="editing.open" :title="app?.exists ? t('partner.meta.change') : t('partner.meta.add')" :description="t('partner.meta.form_text')" @close="editing.open = false">
            <form class="grid gap-4" autocomplete="off" @submit.prevent="save">
                <AppField :label="t('partner.meta.app_id')" :hint="t('partner.meta.app_id_hint')" v-slot="{ id }"><input :id="id" v-model="editing.app_id" class="field-input" dir="ltr" inputmode="numeric" maxlength="30" required /></AppField>
                <AppField :label="t('partner.meta.app_secret')" :hint="t('partner.meta.app_secret_hint')" v-slot="{ id }"><input :id="id" v-model="editing.app_secret" type="password" class="field-input" dir="ltr" maxlength="64" autocomplete="new-password" required /></AppField>
                <AppField :label="t('partner.meta.password')" v-slot="{ id }"><input :id="id" v-model="editing.password" type="password" class="field-input" autocomplete="current-password" required /></AppField>
                <div class="flex justify-end gap-2"><AppButton @click="editing.open = false">{{ t('partner.meta.cancel') }}</AppButton><AppButton type="submit" variant="primary" :loading="busy">{{ t('partner.meta.save') }}</AppButton></div>
            </form>
        </AppDialog>

        <AppDialog :open="approving.open" :title="t('partner.meta.approve')" :description="t('partner.meta.approve_text')" @close="approving.open = false">
            <form class="grid gap-4" @submit.prevent="approve">
                <AppField :label="t('partner.meta.password')" v-slot="{ id }"><input :id="id" v-model="approving.password" type="password" class="field-input" autocomplete="current-password" required /></AppField>
                <div class="flex justify-end gap-2"><AppButton @click="approving.open = false">{{ t('partner.meta.cancel') }}</AppButton><AppButton type="submit" variant="primary" :loading="busy">{{ t('partner.meta.approve') }}</AppButton></div>
            </form>
        </AppDialog>
    </PageFrame>
</template>
