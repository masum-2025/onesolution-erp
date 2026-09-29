<script setup>
import { computed, onMounted, ref, shallowRef } from 'vue';
import { RefreshCw, TriangleAlert, WifiOff } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatNumber, formatRelative } from '@/lib/format';
import { confirmAction } from '@/lib/dialogs';
import { emit } from '@/lib/events';
import { can, currentOrganization, hasOfflineData } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * My account: the devices this person set up to work offline (Phase 7).
 * Removing one stops it at once; it wipes its offline data when it next
 * connects. "This browser" sets up offline work here, in the organization
 * the person is working in, and lists changes the server did not apply.
 * Shown only when there is something to show.
 */
const devices = useResource(() => api('/api/me/devices').then((response) => response.data));
const list = computed(() => devices.data.value ?? []);

const organization = currentOrganization();
const canHere = Boolean(organization) && can('offline_mode.use');

// The offline code loads only when it can be used here.
const lib = shallowRef(null);
const state = computed(() => lib.value?.offline ?? null);
const name = ref(t('offline.browser.name_default'));
const busy = ref(false);

onMounted(async () => {
    if (!canHere && !hasOfflineData()) return;
    lib.value = await import('@/lib/offline/index');
    await lib.value.initOffline();
});

async function enable() {
    busy.value = true;
    try {
        await lib.value.enableOffline(name.value.trim() || t('offline.browser.name_default'));
        toast.success(t('offline.browser.enabled'));
        emit('offline-changed');
        await devices.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}

async function sync() {
    try {
        const summary = await lib.value.syncNow();
        if (!summary) return;
        const notice = t(`core.offline.status.${summary.status}`);
        summary.status === 'ok' ? toast.success(notice) : toast.error(notice);
        await devices.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

function problemText(outcome) {
    return t(`offline.browser.results.${outcome.status}`);
}

async function remove(device) {
    const confirmed = await confirmAction({
        title: t('offline.mine.remove_title', { name: device.name }),
        message: t('offline.mine.remove_text'),
        confirmLabel: t('offline.mine.remove'),
        danger: true,
    });
    if (!confirmed) return;

    try {
        const response = await api(`/api/me/devices/${device.id}`, { method: 'DELETE' });
        toast.success(response.message);
        await devices.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <section v-if="list.length || canHere || state?.enabled" id="offline" class="card">
        <header class="border-b border-line px-5 py-4">
            <h2 class="flex items-center gap-2 text-[15px] font-semibold text-fg"><WifiOff class="size-4 text-muted" aria-hidden="true" />{{ t('offline.mine.title') }}</h2>
            <p class="mt-1 text-[13px] leading-relaxed text-muted">{{ t('offline.mine.text') }}</p>
        </header>

        <!-- This browser, in the organization the person is working in -->
        <div v-if="state?.ready && (canHere || state.enabled)" class="space-y-3 border-b border-line px-5 py-4">
            <div>
                <h3 class="text-[13.5px] font-semibold text-fg">{{ t('offline.browser.title') }}</h3>
                <p class="mt-0.5 text-[12.5px] leading-relaxed text-muted">{{ t('offline.browser.text') }}</p>
            </div>

            <form v-if="!state.enabled" class="flex flex-wrap items-end gap-3" novalidate @submit.prevent="enable">
                <AppField :label="t('offline.browser.name')" class="min-w-0 flex-1 basis-56">
                    <template #default="{ id }">
                        <input :id="id" v-model="name" maxlength="80" class="field-input" autocomplete="off" />
                    </template>
                </AppField>
                <AppButton type="submit" :loading="busy">{{ t('offline.browser.enable') }}</AppButton>
            </form>

            <template v-else>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-1 text-[12.5px] text-muted">
                        <p v-if="state.expiresAt">{{ t('offline.browser.until', { date: formatDateTime(state.expiresAt) }) }}</p>
                        <p>{{ state.lastSync ? t('offline.browser.last_sync', { when: formatRelative(state.lastSync) }) : t('offline.browser.never') }}</p>
                        <p v-if="state.pending" class="font-medium text-fg-2">{{ t('core.offline.pending', { count: state.pending, changes: formatNumber(state.pending) }) }}</p>
                    </div>
                    <AppButton size="sm" variant="secondary" :icon="RefreshCw" :loading="state.syncing" :disabled="!state.online" @click="sync">{{ t('offline.browser.sync_now') }}</AppButton>
                </div>

                <div v-if="state.outcomes.length" class="rounded-xl border border-warn/25 bg-warn-soft p-3">
                    <p class="flex items-center gap-1.5 text-[13px] font-semibold text-fg"><TriangleAlert class="size-4 text-warn" aria-hidden="true" />{{ t('offline.browser.problems') }}</p>
                    <ul class="mt-2 space-y-2">
                        <li v-for="outcome in state.outcomes" :key="outcome.op_id" class="flex flex-wrap items-start gap-2 text-[12.5px]">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-fg-2">
                                    {{ t('offline.browser.problem_line', { kind: outcome.operation.kind, action: t(`offline.browser.actions.${outcome.operation.action}`), when: formatRelative(outcome.operation.made_at) }) }}
                                </p>
                                <p class="text-muted">{{ outcome.message ?? problemText(outcome) }}</p>
                            </div>
                            <AppButton size="sm" variant="ghost" @click="lib.dismissOutcome(outcome.op_id)">{{ t('offline.browser.dismiss') }}</AppButton>
                        </li>
                    </ul>
                </div>
            </template>
        </div>

        <p v-if="!list.length" class="px-5 py-4 text-[13px] text-muted">{{ t('offline.mine.empty') }}</p>
        <ul v-else class="divide-y divide-line">
            <li v-for="device in list" :key="device.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                <div class="min-w-0 flex-1">
                    <p class="text-[13.5px] font-medium text-fg">{{ device.name }}</p>
                    <p class="text-[12.5px] text-muted">
                        {{ device.last_sync_at ? t('offline.mine.line', { organization: device.organization ?? '—', when: formatRelative(device.last_sync_at) }) : t('offline.mine.never', { organization: device.organization ?? '—' }) }}
                    </p>
                </div>
                <AppButton size="sm" variant="danger-soft" @click="remove(device)">{{ t('offline.mine.remove') }}</AppButton>
            </li>
        </ul>
    </section>
</template>
