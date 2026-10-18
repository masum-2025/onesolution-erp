<script setup>
import { ref, watch } from 'vue';
import { Copy, Download, FileCog, KeyRound } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { downloadText } from '@/lib/csv';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Making an office bridge: a name (which computer it runs on) and branch;
 * then, this once, its token with the two files for the office computer:
 * zkteco-bridge.exe and zkteco-bridge.ini (the app's address and the token
 * inside, made here in the browser), and the steps to start it.
 */
const props = defineProps({
    open: Boolean,
    setup: { type: Object, required: true },
    api: { type: Object, required: true },
    program: { type: Object, default: null },
});
const emit = defineEmits(['close', 'made']);
const name = ref('');
const unitId = ref('');
const errors = ref({});
const saving = ref(false);
const made = ref(null);

watch(() => props.open, (open) => {
    if (!open) return;
    name.value = '';
    unitId.value = props.setup.units?.[0]?.id ?? '';
    errors.value = {};
    made.value = null;
});

async function create() {
    saving.value = true;
    errors.value = {};
    try {
        made.value = (await props.api.addBridge({ name: name.value.trim(), unit_id: unitId.value || null })).data;
        emit('made');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

function iniText() {
    return [
        '# One Solutions ZKTeco office bridge',
        `# ${made.value.name}`,
        `server=${made.value.server}`,
        `token=${made.value.token}`,
        '',
    ].join('\r\n');
}

// The exe from the app, with the signed-in session.
const downloadProgram = () => globalThis.location.assign(props.api.programUrl);

async function copyToken() {
    try {
        await navigator.clipboard.writeText(made.value.token);
        toast.success(t('zkteco.bridge.copied'));
    } catch {
        toast.error(t('zkteco.bridge.copy_failed'));
    }
}
</script>

<template>
    <AppDialog :open="open" size="lg" :title="made ? t('zkteco.bridge.ready', { name: made.name }) : t('zkteco.bridge.add')" @close="emit('close')">
        <form v-if="!made" class="space-y-4" @submit.prevent="create">
            <p class="text-[13px] text-fg-2">{{ t('zkteco.bridge.what') }}</p>
            <AppField :label="t('zkteco.bridge.name')" :hint="t('zkteco.bridge.name_hint')" :error="errors.name?.[0]">
                <template #default="{ id, describedby }"><input :id="id" v-model="name" type="text" maxlength="80" class="field-input" :aria-describedby="describedby" required autofocus /></template>
            </AppField>
            <AppField v-if="setup.units?.length > 1" :label="t('zkteco.device.branch')" :error="errors.unit_id?.[0]">
                <template #default="{ id }">
                    <select :id="id" v-model="unitId" class="field-input">
                        <option v-for="unit in setup.units" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                    </select>
                </template>
            </AppField>
        </form>

        <div v-else class="space-y-4">
            <p class="flex items-start gap-2 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn" role="alert">
                <KeyRound class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ t('zkteco.bridge.once') }}
            </p>
            <div class="flex flex-wrap gap-2">
                <AppButton variant="primary" :icon="Download" @click="downloadProgram">{{ t('zkteco.bridge.download_exe') }}</AppButton>
                <AppButton variant="primary" :icon="FileCog" @click="downloadText('zkteco-bridge.ini', iniText(), 'text/plain;charset=utf-8')">{{ t('zkteco.bridge.download_ini') }}</AppButton>
                <AppButton :icon="Copy" @click="copyToken">{{ t('zkteco.bridge.copy') }}</AppButton>
            </div>
            <p v-if="program" class="break-all text-[11.5px] text-muted">{{ t('zkteco.bridge.checksum', { sha: program.sha256 }) }}</p>
            <ol class="list-decimal space-y-1.5 ps-5 text-[13px] text-fg-2">
                <li>{{ t('zkteco.bridge.step_1') }}</li>
                <li>{{ t('zkteco.bridge.step_2') }}</li>
                <li>{{ t('zkteco.bridge.step_3') }} <code class="rounded bg-subtle px-1.5 py-0.5 text-[12px]">zkteco-bridge.exe --test</code></li>
                <li>{{ t('zkteco.bridge.step_4') }} <code class="rounded bg-subtle px-1.5 py-0.5 text-[12px]">zkteco-bridge.exe --install</code></li>
                <li>{{ t('zkteco.bridge.step_5') }}</li>
            </ol>
        </div>

        <template #footer>
            <template v-if="!made">
                <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="saving" @click="create">{{ t('zkteco.bridge.make') }}</AppButton>
            </template>
            <AppButton v-else variant="primary" @click="emit('close')">{{ t('zkteco.bridge.done') }}</AppButton>
        </template>
    </AppDialog>
</template>
