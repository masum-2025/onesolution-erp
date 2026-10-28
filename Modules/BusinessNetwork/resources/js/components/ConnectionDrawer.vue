<script setup>
import { computed, ref, watch } from 'vue';
import { CirclePause, CirclePlay, Save, XCircle } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { indentOf, statusTone } from '../api';
import UnitChecklist from './UnitChecklist.vue';

/**
 * One connection: who it is with, the shared factories (the client changes
 * them), what was sent each way (envelopes only), and pausing or ending it.
 */
const props = defineProps({
    open: Boolean,
    connectionId: { type: String, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'changed']);

const item = ref(null);
const messages = ref([]);
const error = ref(null);
const busy = ref(false);
const shared = ref([]);
const unitsError = ref(null);

async function load() {
    error.value = null;
    item.value = null;
    try {
        const [detail, sent] = await Promise.all([props.calls.show(props.connectionId), props.calls.messages(props.connectionId)]);
        item.value = detail.data;
        messages.value = sent.data;
        shared.value = [...detail.data.shared_ids];
    } catch (caught) {
        error.value = caught;
    }
}
watch(() => [props.open, props.connectionId], ([open]) => open && props.connectionId && load(), { immediate: true });

const changedUnits = computed(() => item.value && [...shared.value].sort().join() !== [...item.value.shared_ids].sort().join());

async function saveUnits() {
    busy.value = true;
    unitsError.value = null;
    try {
        item.value = (await props.calls.units(item.value.id, item.value.version, shared.value)).data;
        shared.value = [...item.value.shared_ids];
        toast.success(t('business_network.units.saved'));
        emit('changed');
    } catch (caught) {
        unitsError.value = caught.errors?.unit_ids?.[0] ?? caught.message;
        if (caught.code === 'version_conflict') load();
    } finally {
        busy.value = false;
    }
}

async function step(name) {
    const result = await confirmAction({
        title: t(`business_network.steps.${name}_title`, { name: item.value.other }),
        message: t(`business_network.steps.${name}_text`),
        confirmLabel: t(`business_network.steps.${name}`),
        danger: name === 'end',
        reason: name === 'end' ? 'required' : 'none',
    });
    if (!result) return;
    busy.value = true;
    try {
        item.value = (await props.calls.step(item.value.id, name, item.value.version, result.reason)).data;
        toast.success(t(`business_network.steps.${name}_done`));
        emit('changed');
    } catch (caught) {
        toast.error(caught.message);
        if (caught.code === 'version_conflict') load();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppDrawer :open="open" :title="item?.other ?? t('business_network.drawer.title')" width="sm:max-w-[600px]" @close="emit('close')">
        <ErrorState v-if="error" compact class="m-5" :error="error" @retry="load" />
        <SkeletonRows v-else-if="!item" class="px-5 py-5 sm:px-6" :rows="5" />
        <div v-else class="space-y-5 px-5 py-5 sm:px-6">
            <header class="space-y-1.5">
                <p class="flex flex-wrap items-center gap-2">
                    <AppBadge :tone="statusTone(item.status)">{{ t(`business_network.status.${item.status}`) }}</AppBadge>
                    <span class="text-[13px] text-muted">{{ t(`business_network.side.${item.side}`) }}</span>
                </p>
                <p v-if="item.note" class="text-[13px] text-muted">{{ t('business_network.drawer.note', { note: item.note }) }}</p>
                <p v-if="item.accepted_at" class="text-[13px] text-muted">{{ t('business_network.drawer.since', { date: formatDate(item.accepted_at) }) }}</p>
                <p v-if="item.status === 'paused'" class="rounded-lg bg-subtle px-3 py-2 text-[13px]">{{ t(`business_network.drawer.paused_by_${item.paused_by === item.side ? 'you' : 'them'}`) }}</p>
                <p v-if="item.status === 'ended'" class="rounded-lg bg-subtle px-3 py-2 text-[13px]">{{ t('business_network.drawer.ended', { date: formatDate(item.ended_at) }) }}<template v-if="item.end_reason"> “{{ item.end_reason }}”</template></p>
            </header>

            <!-- The client chooses what the provider may deal with; the provider reads it. -->
            <section v-if="item.can.units" :aria-label="t('business_network.units.title')">
                <UnitChecklist v-model="shared" :units="item.own_units" :error="unitsError" />
                <AppButton class="mt-3" :icon="Save" :loading="busy" :disabled="!changedUnits || !shared.length" @click="saveUnits">{{ t('business_network.units.save') }}</AppButton>
            </section>
            <section v-else-if="item.units.length" :aria-label="t('business_network.units.shared')">
                <h3 class="mb-2 text-[13px] font-semibold text-fg-2">{{ t('business_network.units.shared') }}</h3>
                <ul class="divide-y divide-line rounded-xl border border-line text-[13.5px]">
                    <li v-for="unit in item.units" :key="unit.id" class="flex items-center justify-between gap-3 py-2.5 pe-3" :style="{ paddingInlineStart: `${0.75 + indentOf(item.units, unit) * 1.25}rem` }">
                        <span class="truncate">{{ unit.name }}</span>
                        <span class="text-[12px] text-muted">{{ t(`business_network.units.type_${unit.type}`) }}</span>
                    </li>
                </ul>
            </section>

            <section :aria-label="t('business_network.messages.title')">
                <h3 class="mb-2 text-[13px] font-semibold text-fg-2">{{ t('business_network.messages.title') }}</h3>
                <p v-if="!messages.length" class="text-[13px] text-muted">{{ t('business_network.messages.none') }}</p>
                <ol v-else class="space-y-2">
                    <li v-for="message in messages" :key="message.id" class="rounded-lg border border-line px-3 py-2 text-[13px]">
                        <p class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">{{ message.sent ? t('business_network.messages.sent', { to: message.to }) : t('business_network.messages.received', { from: message.from }) }}</span>
                            <AppBadge :tone="message.status === 'delivered' ? 'ok' : message.status === 'failed' ? 'bad' : 'warn'">{{ t(`business_network.messages.status_${message.status}`) }}</AppBadge>
                        </p>
                        <p class="text-[12.5px] text-muted"><span class="font-mono" dir="ltr">{{ message.kind }}</span> · {{ formatDateTime(message.created_at) }}</p>
                        <p v-if="message.error" class="text-[12.5px] text-bad">{{ message.error }}</p>
                    </li>
                </ol>
            </section>
        </div>
        <template v-if="item && (item.can.pause || item.can.resume || item.can.end)" #footer>
            <div class="flex w-full flex-wrap justify-end gap-2 border-t border-line px-5 py-3.5 pb-[max(0.875rem,env(safe-area-inset-bottom))] sm:px-6">
                <AppButton v-if="item.can.end" variant="ghost" class="me-auto text-bad" :icon="XCircle" :disabled="busy" @click="step('end')">{{ t('business_network.steps.end') }}</AppButton>
                <AppButton v-if="item.can.pause" :icon="CirclePause" :disabled="busy" @click="step('pause')">{{ t('business_network.steps.pause') }}</AppButton>
                <AppButton v-if="item.can.resume" variant="primary" :icon="CirclePlay" :disabled="busy" @click="step('resume')">{{ t('business_network.steps.resume') }}</AppButton>
            </div>
        </template>
    </AppDrawer>
</template>
