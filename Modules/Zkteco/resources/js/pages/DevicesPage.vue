<script setup>
import { computed, ref } from 'vue';
import { AlertTriangle, Ban, CheckCircle2, Fingerprint, Monitor, Pencil, Plug, Plus, RefreshCw, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization } from '@/lib/session';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatNumber, formatRelative } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { zktecoApi } from '../api';
import DeviceDialog from '../components/DeviceDialog.vue';
import BridgeDialog from '../components/BridgeDialog.vue';

/**
 * The company's attendance devices: each one's branch and address, what it
 * said about itself, when it was last read (or why it could not be), and
 * how many punches wait for their user to be matched to an employee. Try a
 * device, read it now, add or change one.
 */
const api = zktecoApi(currentOrganization().id);
const setup = useResource(() => api.setup());
const list = useResource(() => api.devices());
const devices = computed(() => list.data.value?.data ?? []);
const can = (ability) => setup.data.value?.data?.can?.[ability] === true;
const unitName = (id) => setup.data.value?.data?.units?.find((unit) => unit.id === id)?.name ?? '';
const busy = ref({});
const dialog = ref({ open: false, device: null });

// Office bridges (an app hosted online reads devices through them).
const bridgeList = useResource(() => api.bridges());
const bridges = computed(() => bridgeList.data.value?.data ?? []);
const liveBridges = computed(() => bridges.value.filter((bridge) => !bridge.revoked_at));
const bridgeName = (id) => bridges.value.find((bridge) => bridge.id === id)?.name ?? '';
const bridgeDialog = ref(false);
// A bridge that has not called for this long is shown as silent (it runs every minute).
const SILENT_MINUTES = 10;
const silent = (bridge) => !bridge.last_seen_at || Date.now() - Date.parse(bridge.last_seen_at) > SILENT_MINUTES * 60 * 1000;

async function revoke(bridge) {
    const sure = await confirmAction({ title: t('zkteco.bridge.revoke_title', { name: bridge.name }), message: t('zkteco.bridge.revoke_text'), confirmLabel: t('zkteco.bridge.revoke'), danger: true });
    if (!sure) return;
    try {
        await api.revokeBridge(bridge.id);
        toast.success(t('zkteco.bridge.revoked'));
        bridgeList.reload();
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

async function act(device, kind) {
    busy.value = { ...busy.value, [device.id]: kind };
    try {
        const response = kind === 'test' ? await api.test(device.id) : await api.sync(device.id);
        if (kind === 'test') {
            const facts = response.data.facts;
            toast.success(t('zkteco.devices.test_ok', { model: facts.model ?? '?', users: formatNumber(facts.users), logs: formatNumber(facts.logs) }));
        } else {
            const result = response.data.result;
            toast.success(t('zkteco.devices.sync_ok', { recorded: formatNumber(result.recorded), unmatched: formatNumber(result.unmatched) }));
        }
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = { ...busy.value, [device.id]: null };
        list.reload();
    }
}

function saved() {
    dialog.value = { open: false, device: null };
    list.reload();
}
</script>

<template>
    <div>
        <PageHeader :title="t('zkteco.devices.title')" :description="t('zkteco.devices.text')">
            <template #actions>
                <AppButton v-if="can('manage')" variant="primary" :icon="Plus" @click="dialog = { open: true, device: null }">{{ t('zkteco.device.add') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="(list.loading.value && !list.data.value) || (setup.loading.value && !setup.data.value)" :rows="3" />
        <ErrorState v-else-if="list.error.value || setup.error.value" :error="list.error.value || setup.error.value" @retry="list.reload(); setup.reload()" />
        <section v-else-if="!devices.length" class="card">
            <EmptyState :icon="Fingerprint" :title="t('zkteco.devices.empty_title')" :text="t('zkteco.devices.empty_text')">
                <AppButton v-if="can('manage')" variant="primary" :icon="Plus" @click="dialog = { open: true, device: null }">{{ t('zkteco.device.add') }}</AppButton>
            </EmptyState>
        </section>
        <ul v-else class="grid gap-3 lg:grid-cols-2">
            <li v-for="device in devices" :key="device.id" class="card flex flex-col gap-3 p-4">
                <div class="flex items-start gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><Fingerprint class="size-5" aria-hidden="true" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold text-fg">{{ device.name }}</p>
                        <p class="tabular truncate text-[12.5px] text-muted">{{ device.host }}:{{ device.port }} · {{ device.protocol.toUpperCase() }} · {{ unitName(device.unit_id) }}</p>
                        <p v-if="device.model || device.serial" class="truncate text-[12.5px] text-muted">{{ [device.model, device.serial].filter(Boolean).join(' · ') }}</p>
                        <p v-if="device.bridge_id" class="mt-1"><AppBadge tone="brand" :icon="Monitor">{{ t('zkteco.devices.via_bridge', { name: bridgeName(device.bridge_id) }) }}</AppBadge></p>
                    </div>
                    <AppBadge v-if="!device.is_active" tone="neutral">{{ t('zkteco.devices.off') }}</AppBadge>
                    <AppBadge v-else-if="device.last_error" tone="bad" :icon="AlertTriangle">{{ t('zkteco.devices.not_answering') }}</AppBadge>
                    <AppBadge v-else-if="device.last_synced_at" tone="ok" :icon="CheckCircle2">{{ t('zkteco.devices.working') }}</AppBadge>
                    <AppBadge v-else tone="warn" dot>{{ t('zkteco.devices.not_read') }}</AppBadge>
                </div>
                <p v-if="device.last_error" class="rounded-lg bg-bad-soft px-3 py-2 text-[12.5px] text-bad" role="note">{{ t(`zkteco.reasons.${device.last_error}`) }}</p>
                <dl class="grid grid-cols-3 gap-2 text-center text-[12px]">
                    <div class="rounded-lg bg-subtle p-2"><dt class="text-muted">{{ t('zkteco.devices.users') }}</dt><dd class="tabular text-[15px] font-semibold text-fg">{{ device.users_count === null ? '—' : formatNumber(device.users_count) }}</dd></div>
                    <div class="rounded-lg bg-subtle p-2"><dt class="text-muted">{{ t('zkteco.devices.logs') }}</dt><dd class="tabular text-[15px] font-semibold text-fg">{{ device.logs_count === null ? '—' : formatNumber(device.logs_count) }}</dd></div>
                    <div class="rounded-lg p-2" :class="device.waiting ? 'bg-warn-soft' : 'bg-subtle'"><dt class="text-muted">{{ t('zkteco.devices.waiting') }}</dt><dd class="tabular text-[15px] font-semibold" :class="device.waiting ? 'text-warn' : 'text-fg'">{{ formatNumber(device.waiting ?? 0) }}</dd></div>
                </dl>
                <p class="text-[12.5px] text-muted">
                    {{ device.last_synced_at ? t('zkteco.devices.last_read', { when: formatRelative(device.last_synced_at) }) : t('zkteco.devices.never_read') }}
                    · {{ t('zkteco.devices.from', { date: device.import_from }) }}
                </p>
                <div class="mt-auto flex flex-wrap gap-2">
                    <AppButton :to="{ name: 'zkteco-device', params: { id: device.id } }" :icon="Users" :variant="device.waiting ? 'primary' : 'secondary'">{{ t('zkteco.devices.match') }}</AppButton>
                    <template v-if="can('manage')">
                        <!-- A device a bridge reads is out of the app's reach: the bridge tries it every minute. -->
                        <template v-if="!device.bridge_id">
                            <AppButton :icon="Plug" :loading="busy[device.id] === 'test'" :disabled="!!busy[device.id]" @click="act(device, 'test')">{{ t('zkteco.devices.test') }}</AppButton>
                            <AppButton :icon="RefreshCw" :loading="busy[device.id] === 'sync'" :disabled="!!busy[device.id] || !device.is_active" @click="act(device, 'sync')">{{ t('zkteco.devices.sync') }}</AppButton>
                        </template>
                        <AppButton size="icon" variant="ghost" :icon="Pencil" :aria-label="t('zkteco.device.edit', { name: device.name })" @click="dialog = { open: true, device }" />
                    </template>
                </div>
            </li>
        </ul>

        <!-- Office bridges: for an app hosted online, a program on an office computer reads the devices. -->
        <section class="mt-8">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-[16px] font-semibold text-fg">{{ t('zkteco.bridge.title') }}</h2>
                    <p class="text-[13px] text-muted">{{ t('zkteco.bridge.text') }}</p>
                </div>
                <AppButton v-if="can('manage')" :icon="Plus" @click="bridgeDialog = true">{{ t('zkteco.bridge.add') }}</AppButton>
            </div>
            <div v-if="!bridges.length" class="card px-4 py-5 text-[13px] text-muted">{{ t('zkteco.bridge.none') }}</div>
            <ul v-else class="card divide-y divide-line">
                <li v-for="bridge in bridges" :key="bridge.id" class="flex flex-wrap items-center gap-3 px-4 py-3" :class="bridge.revoked_at && 'opacity-60'">
                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-subtle text-fg-2"><Monitor class="size-4.5" aria-hidden="true" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-fg">{{ bridge.name }} <span class="tabular text-[12px] text-muted">…{{ bridge.token_hint }}</span></p>
                        <p class="truncate text-[12.5px] text-muted">
                            {{ bridge.revoked_at ? t('zkteco.bridge.is_revoked') : bridge.last_seen_at ? t('zkteco.bridge.seen', { when: formatRelative(bridge.last_seen_at), ip: bridge.last_ip ?? '' }) : t('zkteco.bridge.never_seen') }}
                            <template v-if="bridge.devices.length"> · {{ bridge.devices.map((device) => device.name).join(', ') }}</template>
                        </p>
                    </div>
                    <AppBadge v-if="bridge.revoked_at" tone="neutral" :icon="Ban">{{ t('zkteco.bridge.is_revoked') }}</AppBadge>
                    <AppBadge v-else-if="silent(bridge)" tone="warn" dot>{{ t('zkteco.bridge.silent') }}</AppBadge>
                    <AppBadge v-else tone="ok" :icon="CheckCircle2">{{ t('zkteco.bridge.running') }}</AppBadge>
                    <AppButton v-if="can('manage') && !bridge.revoked_at" size="sm" variant="danger-soft" @click="revoke(bridge)">{{ t('zkteco.bridge.revoke') }}</AppButton>
                </li>
            </ul>
        </section>

        <DeviceDialog v-if="setup.data.value" :open="dialog.open" :device="dialog.device" :setup="setup.data.value.data" :api="api" :today="setup.data.value.data.today" :bridges="liveBridges" @close="dialog = { open: false, device: null }" @saved="saved" />
        <BridgeDialog v-if="setup.data.value" :open="bridgeDialog" :setup="setup.data.value.data" :api="api" :program="bridgeList.data.value?.meta?.program ?? null" @close="bridgeDialog = false" @made="bridgeList.reload()" />
    </div>
</template>
