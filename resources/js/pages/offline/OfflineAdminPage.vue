<script setup>
import { computed, ref } from 'vue';
import { Banknote, Laptop, PauseCircle, Smartphone } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate, formatRelative } from '@/lib/format';
import { confirmAction } from '@/lib/dialogs';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * An organization's offline devices and held changes (Phase 7), for people
 * with offline_mode.manage: remove a device (it wipes itself on its next
 * contact), apply or discard a change held from a removed device or person.
 */
const org = currentOrganization();
const base = `/api/organizations/${org.id}/offline`;
const state = useResource(() => api(base).then((response) => response.data));
const data = computed(() => state.data.value);
const busy = ref(null);

const isPhone = (platform) => /android|ios|iphone/i.test(platform ?? '');

async function act(id, path, body, message) {
    busy.value = id;
    try {
        const response = await api(`${base}/${path}`, { method: 'POST', body });
        toast.success(response.message ?? message);
        await state.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function removeDevice(device) {
    const confirmed = await confirmAction({
        title: t('offline.admin.remove_title', { name: device.name }),
        message: t('offline.admin.remove_text'),
        reason: 'optional',
        reasonLabel: t('offline.admin.reason'),
        confirmLabel: t('offline.admin.remove'),
        danger: true,
    });
    if (confirmed) act(device.id, `devices/${device.id}/revoke`, { reason: confirmed.reason || null });
}

async function release(held) {
    const confirmed = await confirmAction({ title: t('offline.admin.release_title'), message: t('offline.admin.release_text'), confirmLabel: t('offline.admin.release') });
    if (confirmed) act(held.id, `held/${held.id}/release`);
}

async function discard(held) {
    const confirmed = await confirmAction({
        title: t('offline.admin.discard_title'),
        message: t('offline.admin.discard_text'),
        reason: 'optional',
        reasonLabel: t('offline.admin.reason'),
        confirmLabel: t('offline.admin.discard'),
        danger: true,
    });
    if (confirmed) act(held.id, `held/${held.id}/discard`, { reason: confirmed.reason || null });
}
</script>

<template>
    <div>
        <PageHeader :title="t('offline.admin.title')" :description="t('offline.admin.text')" />

        <SkeletonRows v-if="state.loading.value && !data" :rows="5" />
        <ErrorState v-else-if="state.error.value" :error="state.error.value" @retry="state.reload()" />

        <div v-else-if="data" class="space-y-6">
            <!-- Held changes first: they need a decision. -->
            <section class="card">
                <h2 class="flex items-center gap-2 border-b border-line px-5 py-3.5 text-[14px] font-semibold text-fg">
                    {{ t('offline.admin.held') }}
                    <AppBadge v-if="data.held.length" tone="warn">{{ data.held.length }}</AppBadge>
                </h2>
                <p v-if="!data.held.length" class="px-5 py-4 text-[13px] text-muted">{{ t('offline.admin.no_held') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="held in data.held" :key="held.id" class="flex flex-wrap items-start gap-3 px-5 py-3.5">
                        <component :is="held.money ? Banknote : PauseCircle" class="mt-0.5 size-5 shrink-0" :class="held.money ? 'text-warn' : 'text-muted'" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                                {{ t('offline.admin.held_line', { person: held.person ?? '—', device: held.device ?? '—', kind: held.kind }) }}
                                <AppBadge v-if="held.money" tone="warn">{{ t('offline.admin.money') }}</AppBadge>
                            </p>
                            <p class="mt-0.5 text-[12.5px] text-muted">{{ held.reason_text }}</p>
                            <p class="text-[12px] text-faint">{{ formatRelative(held.received_at) }} · {{ t('offline.admin.until', { date: formatDate(held.expires_at) }) }}</p>
                        </div>
                        <div class="flex gap-2">
                            <AppButton size="sm" variant="primary" :loading="busy === held.id" @click="release(held)">{{ t('offline.admin.release') }}</AppButton>
                            <AppButton size="sm" variant="danger-soft" @click="discard(held)">{{ t('offline.admin.discard') }}</AppButton>
                        </div>
                    </li>
                </ul>
            </section>

            <section class="card">
                <h2 class="border-b border-line px-5 py-3.5 text-[14px] font-semibold text-fg">{{ t('offline.admin.devices') }}</h2>
                <p v-if="!data.devices.length" class="px-5 py-4 text-[13px] text-muted">{{ t('offline.admin.no_devices') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="device in data.devices" :key="device.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                        <component :is="isPhone(device.platform) ? Smartphone : Laptop" class="size-5 shrink-0 text-muted" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <p class="text-[13.5px] font-medium text-fg">{{ device.name }}</p>
                            <p class="text-[12.5px] text-muted">
                                {{ t('offline.admin.device_line', { person: device.person ?? '—', unit: device.unit ?? '—' }) }} ·
                                {{ device.last_sync_at ? t('offline.admin.last_sync', { when: formatRelative(device.last_sync_at) }) : t('offline.admin.never_synced') }}
                            </p>
                        </div>
                        <AppButton size="sm" variant="danger-soft" :loading="busy === device.id" @click="removeDevice(device)">{{ t('offline.admin.remove') }}</AppButton>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
