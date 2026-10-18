<script setup>
import { reactive, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Adding or changing a device: its name, branch, IP address on the office
 * network, port and protocol, comm key (typed only to set or change it;
 * never shown), the first day whose punches are brought in, on/off.
 */
const props = defineProps({
    open: Boolean,
    device: { type: Object, default: null },
    setup: { type: Object, required: true },
    api: { type: Object, required: true },
    today: { type: String, required: true },
});
const emit = defineEmits(['close', 'saved']);
const form = reactive({ name: '', unit_id: '', host: '', port: '4370', protocol: 'udp', comm_key: '', change_key: false, import_from: '', is_active: true });
const errors = ref({});
const saving = ref(false);

watch(() => props.open, (open) => {
    if (!open) return;
    const device = props.device;
    Object.assign(form, device
        ? { name: device.name, unit_id: device.unit_id, host: device.host, port: String(device.port), protocol: device.protocol, comm_key: '', change_key: false, import_from: device.import_from, is_active: device.is_active }
        : { name: '', unit_id: props.setup.units?.[0]?.id ?? '', host: '', port: '4370', protocol: 'udp', comm_key: '', change_key: true, import_from: `${props.today.slice(0, 7)}-01`, is_active: true });
    errors.value = {};
});

async function save() {
    saving.value = true;
    errors.value = {};
    const body = {
        name: form.name.trim(), host: form.host.trim(), port: Number(form.port), protocol: form.protocol, import_from: form.import_from,
        ...(form.change_key ? { comm_key: form.comm_key === '' ? null : Number(form.comm_key) } : {}),
    };
    try {
        const saved = props.device
            ? await props.api.change(props.device.id, { ...body, is_active: form.is_active, base_version: props.device.version })
            : await props.api.add({ ...body, unit_id: form.unit_id });
        toast.success(t('zkteco.device.saved'));
        emit('saved', saved.data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="device ? t('zkteco.device.edit', { name: device.name }) : t('zkteco.device.add')" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="save">
            <AppField :label="t('zkteco.device.name')" :hint="t('zkteco.device.name_hint')" :error="errors.name?.[0]">
                <template #default="{ id, describedby }"><input :id="id" v-model="form.name" type="text" maxlength="80" class="field-input" :aria-describedby="describedby" required /></template>
            </AppField>
            <AppField v-if="!device && setup.units?.length > 1" :label="t('zkteco.device.branch')" :error="errors.unit_id?.[0]">
                <template #default="{ id }">
                    <select :id="id" v-model="form.unit_id" class="field-input">
                        <option v-for="unit in setup.units" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                    </select>
                </template>
            </AppField>
            <div class="grid grid-cols-[minmax(0,1fr)_6.5rem] gap-3">
                <AppField :label="t('zkteco.device.host')" :hint="t('zkteco.device.host_hint')" :error="errors.host?.[0]">
                    <template #default="{ id, describedby }"><input :id="id" v-model="form.host" type="text" inputmode="decimal" placeholder="192.168.1.201" class="field-input" :aria-describedby="describedby" required /></template>
                </AppField>
                <AppField :label="t('zkteco.device.port')" :error="errors.port?.[0]">
                    <template #default="{ id }"><input :id="id" v-model="form.port" type="number" min="1" max="65535" class="field-input" /></template>
                </AppField>
            </div>
            <AppField :label="t('zkteco.device.protocol')" :hint="t('zkteco.device.protocol_hint')">
                <template #default="{ id, describedby }">
                    <select :id="id" v-model="form.protocol" class="field-input" :aria-describedby="describedby">
                        <option v-for="protocol in setup.protocols ?? ['udp', 'tcp']" :key="protocol" :value="protocol">{{ protocol.toUpperCase() }}</option>
                    </select>
                </template>
            </AppField>
            <div class="space-y-2">
                <label v-if="device" class="flex items-center gap-2 text-[13.5px]"><input v-model="form.change_key" type="checkbox" />{{ device.has_comm_key ? t('zkteco.device.change_key') : t('zkteco.device.set_key') }}</label>
                <AppField v-if="form.change_key" :label="t('zkteco.device.comm_key')" :hint="t('zkteco.device.comm_key_hint')" :error="errors.comm_key?.[0]" optional>
                    <template #default="{ id, describedby }"><input :id="id" v-model="form.comm_key" type="password" inputmode="numeric" autocomplete="off" class="field-input w-40" :aria-describedby="describedby" /></template>
                </AppField>
            </div>
            <AppField :label="t('zkteco.device.import_from')" :hint="t('zkteco.device.import_from_hint')" :error="errors.import_from?.[0]">
                <template #default="{ id, describedby }"><input :id="id" v-model="form.import_from" type="date" class="field-input w-auto" :aria-describedby="describedby" required /></template>
            </AppField>
            <AppSwitch v-if="device" v-model="form.is_active" :label="t('zkteco.device.active')" show-label />
        </form>
        <template #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton variant="primary" :loading="saving" @click="save">{{ t('core.actions.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
