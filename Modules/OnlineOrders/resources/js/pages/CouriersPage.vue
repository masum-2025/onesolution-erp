<script setup>
import { computed, reactive, ref } from 'vue';
import { Copy, KeyRound, Plus, Power, RotateCw, Truck } from 'lucide-vue-next';
import { AppButton, AppDialog, AppField, confirmAction, PageFrame, StatusBadge, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { onlineOrdersApi } from '../api';

/**
 * SC-5b: the company's courier accounts. Keys are typed in and never shown
 * again; the webhook address and its token are shown once, when made, to
 * paste into the courier's panel.
 */
const orders = onlineOrdersApi(currentOrganization().id);
const list = useResource(() => orders.couriers());
const rows = computed(() => list.data.value?.data ?? []);
const drivers = computed(() => list.data.value?.meta?.drivers ?? []);
const busy = ref(false);

// Add or change keys: one dialog. Shown once afterwards: the webhook address and token.
const editing = reactive({ open: false, id: null, version: 1, driver: '', name: '', keys: {} });
const shown = reactive({ open: false, url: '', token: '' });
const fieldsOf = (driver) => drivers.value.find((entry) => entry.key === driver)?.fields ?? ['api_key', 'secret_key'];

function openAdd() {
    const driver = drivers.value[0]?.key ?? '';
    Object.assign(editing, { open: true, id: null, version: 1, driver, name: driver ? t(`online_orders.couriers.drivers.${driver}`) : '', keys: {} });
}
function openKeys(courier) {
    Object.assign(editing, { open: true, id: courier.id, version: courier.version, driver: courier.driver, name: courier.name, keys: {} });
}
function showToken(data) {
    if (data.webhook_token) Object.assign(shown, { open: true, url: data.webhook_url, token: data.webhook_token });
}

async function save() {
    busy.value = true;
    try {
        const credentials = Object.fromEntries(fieldsOf(editing.driver).map((field) => [field, editing.keys[field] ?? '']));
        const { data } = editing.id
            ? await orders.changeCourier(editing.id, { base_version: editing.version, name: editing.name, credentials })
            : await orders.addCourier({ driver: editing.driver, name: editing.name, credentials });
        editing.open = false;
        toast.success(t(editing.id ? 'online_orders.couriers.saved' : 'online_orders.couriers.added'));
        showToken(data);
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}

async function change(courier, body, done) {
    busy.value = true;
    try {
        const { data } = await orders.changeCourier(courier.id, { base_version: courier.version, ...body });
        toast.success(done);
        showToken(data);
        list.reload();
    } catch (error) {
        toast.error(error.message);
        if (error.status === 409) list.reload();
    } finally {
        busy.value = false;
    }
}
const toggle = (courier) => change(courier, { is_active: !courier.is_active }, t('online_orders.couriers.saved'));
async function newToken(courier) {
    const confirmed = await confirmAction({ title: t('online_orders.couriers.new_token'), message: t('online_orders.couriers.new_token_confirm'), confirmLabel: t('online_orders.couriers.new_token') });
    if (confirmed) change(courier, { new_token: true }, t('online_orders.couriers.saved'));
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(t('online_orders.couriers.copied'));
    } catch {
        window.prompt(t('online_orders.couriers.token'), text);
    }
}
</script>

<template>
    <PageFrame :title="t('online_orders.couriers.title')" :description="t('online_orders.couriers.description')" width="lg" :resource="list"
        :empty="{ when: !rows.length, icon: Truck, title: t('online_orders.couriers.empty'), text: drivers.length ? t('online_orders.couriers.empty_text') : t('online_orders.couriers.none_allowed') }">
        <template #actions>
            <AppButton v-if="drivers.length" variant="primary" :icon="Plus" @click="openAdd">{{ t('online_orders.couriers.add') }}</AppButton>
        </template>

        <ul class="grid gap-3">
            <li v-for="courier in rows" :key="courier.id" class="card grid gap-3 p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <Truck class="size-4 text-muted" aria-hidden="true" />
                    <span class="text-[14px] font-medium">{{ courier.name }}</span>
                    <span class="text-[12.5px] text-muted">{{ t(`online_orders.couriers.drivers.${courier.driver}`) }}</span>
                    <StatusBadge :status="courier.is_active ? 'on' : 'off'" :tones="{ on: 'ok', off: 'neutral' }">{{ t(courier.is_active ? 'online_orders.couriers.active' : 'online_orders.couriers.off') }}</StatusBadge>
                </div>
                <div class="grid gap-1">
                    <span class="text-[12px] text-muted">{{ t('online_orders.couriers.webhook') }}</span>
                    <div class="flex items-center gap-2">
                        <code class="min-w-0 flex-1 truncate rounded bg-subtle px-2 py-1 text-[12px]" dir="ltr">{{ courier.webhook_url }}</code>
                        <AppButton size="sm" variant="ghost" :icon="Copy" :aria-label="t('online_orders.couriers.copied')" @click="copy(courier.webhook_url)" />
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <AppButton size="sm" :icon="KeyRound" :disabled="busy" @click="openKeys(courier)">{{ t('online_orders.couriers.edit') }}</AppButton>
                    <AppButton size="sm" :icon="RotateCw" :disabled="busy" @click="newToken(courier)">{{ t('online_orders.couriers.new_token') }}</AppButton>
                    <AppButton size="sm" :icon="Power" :disabled="busy" @click="toggle(courier)">{{ t(courier.is_active ? 'online_orders.couriers.switch_off' : 'online_orders.couriers.switch_on') }}</AppButton>
                </div>
            </li>
        </ul>

        <AppDialog :open="editing.open" :title="editing.id ? t('online_orders.couriers.edit') : t('online_orders.couriers.add')" :description="t('online_orders.couriers.keys_help')" @close="editing.open = false">
            <form class="grid gap-4" @submit.prevent="save">
                <AppField v-if="!editing.id" :label="t('online_orders.couriers.driver')" v-slot="{ id }">
                    <select :id="id" v-model="editing.driver" class="field-input"><option v-for="driver in drivers" :key="driver.key" :value="driver.key">{{ t(`online_orders.couriers.drivers.${driver.key}`) }}</option></select>
                </AppField>
                <AppField :label="t('online_orders.couriers.name')" v-slot="{ id }"><input :id="id" v-model="editing.name" class="field-input" required minlength="2" maxlength="80" /></AppField>
                <AppField v-for="field in fieldsOf(editing.driver)" :key="field" :label="t(`online_orders.couriers.fields.${field}`)" v-slot="{ id }">
                    <input :id="id" v-model="editing.keys[field]" type="password" class="field-input" dir="ltr" autocomplete="off" required maxlength="200" />
                </AppField>
                <div class="flex justify-end gap-2"><AppButton @click="editing.open = false">{{ t('online_orders.couriers.cancel') }}</AppButton><AppButton type="submit" variant="primary" :loading="busy">{{ t('online_orders.couriers.save') }}</AppButton></div>
            </form>
        </AppDialog>

        <AppDialog :open="shown.open" :title="t('online_orders.couriers.token')" :description="t('online_orders.couriers.token_once')" @close="shown.open = false">
            <div class="grid gap-3">
                <p class="text-[13px] text-muted">{{ t('online_orders.couriers.webhook_help') }}</p>
                <AppField :label="t('online_orders.couriers.webhook')" v-slot="{ id }"><div class="flex gap-2"><input :id="id" :value="shown.url" readonly class="field-input" dir="ltr" /><AppButton :icon="Copy" @click="copy(shown.url)" /></div></AppField>
                <AppField :label="t('online_orders.couriers.token')" v-slot="{ id }"><div class="flex gap-2"><input :id="id" :value="shown.token" readonly class="field-input font-mono" dir="ltr" /><AppButton :icon="Copy" @click="copy(shown.token)" /></div></AppField>
                <div class="flex justify-end"><AppButton variant="primary" @click="shown.open = false">{{ t('online_orders.couriers.copied') }}</AppButton></div>
            </div>
        </AppDialog>
    </PageFrame>
</template>
