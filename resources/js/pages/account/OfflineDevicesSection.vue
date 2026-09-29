<script setup>
import { computed } from 'vue';
import { WifiOff } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatRelative } from '@/lib/format';
import { confirmAction } from '@/lib/dialogs';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * My account: the devices this person set up to work offline (Phase 7).
 * Removing one stops it at once; it wipes its offline data when it next
 * connects. Shown only when there is something to show.
 */
const devices = useResource(() => api('/api/me/devices').then((response) => response.data));
const list = computed(() => devices.data.value ?? []);

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
    <section v-if="list.length" class="card">
        <header class="border-b border-line px-5 py-4">
            <h2 class="flex items-center gap-2 text-[15px] font-semibold text-fg"><WifiOff class="size-4 text-muted" aria-hidden="true" />{{ t('offline.mine.title') }}</h2>
            <p class="mt-1 text-[13px] leading-relaxed text-muted">{{ t('offline.mine.text') }}</p>
        </header>
        <ul class="divide-y divide-line">
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
