<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Plus, Ship } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { newOpId } from '@/lib/billing';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';

/**
 * An order's shipments: each with its number, day, how it goes, pieces,
 * value and whether it has left; what is in the store and not shipped yet
 * per size and colour; and a new draft shipment (its packing list is
 * written on the shipment's own page).
 */
const props = defineProps({ order: { type: Object, required: true } });
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const router = useRouter();
const list = useResource(() => api.shipments(props.order.id));
const items = computed(() => list.data.value?.data ?? []);
const ready = computed(() => Object.entries(list.data.value?.meta?.ready ?? {}).map(([key, pieces]) => ({ key: key.replace('|', ' / '), pieces })));
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const money = (minor) => formatMoney({ amount: minor, currency: props.order.currency });
const foreign = computed(() => setup.currency.value && setup.currency.value !== props.order.currency);
const canMake = computed(() => props.order.status === 'in_production' && setup.can('merchandise'));

const making = ref(false);
const form = reactive({ ship_on: '', mode: 'sea', warehouse_id: '', exchange_rate: '' });
const busy = ref(false);
let opId = '';
function start() {
    Object.assign(form, { ship_on: setup.data.value?.today ?? '', mode: 'sea', warehouse_id: setup.warehouses.value[0]?.id ?? '', exchange_rate: '' });
    opId = newOpId();
    making.value = true;
}
async function make() {
    busy.value = true;
    try {
        const { data } = await api.createShipment({ order_id: props.order.id, ship_on: form.ship_on, mode: form.mode, warehouse_id: form.warehouse_id, ...(foreign.value ? { exchange_rate: form.exchange_rate.trim() } : {}), op_id: opId });
        toast.success(t('factory_erp.shipments.made', { number: data.number }));
        router.push({ name: 'fac-shipment', params: { id: data.id } });
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-5">
        <section class="card">
            <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                <div class="min-w-0 flex-1">
                    <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.shipments.title') }}</h2>
                    <p class="text-[12.5px] text-muted">
                        <template v-if="ready.length">{{ t('factory_erp.shipments.ready', { cells: ready.map((cell) => `${cell.key} ${formatNumber(cell.pieces)}`).join(' · ') }) }}</template>
                        <template v-else>{{ t('factory_erp.shipments.none_ready') }}</template>
                    </p>
                </div>
                <AppButton v-if="canMake" size="sm" variant="primary" :icon="Plus" @click="start">{{ t('factory_erp.shipments.new') }}</AppButton>
            </header>
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="2" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!items.length" compact :icon="Ship" :title="t('factory_erp.shipments.empty_title')" :text="t('factory_erp.shipments.empty_text')" />
            <ul v-else class="divide-y divide-line">
                <li v-for="item in items" :key="item.id">
                    <RouterLink :to="{ name: 'fac-shipment', params: { id: item.id } }" class="flex flex-wrap items-center gap-3 px-5 py-3 hover:bg-subtle/60">
                        <span class="font-mono text-[13px] font-semibold text-fg" dir="ltr">{{ item.number }}</span>
                        <AppBadge :tone="item.status === 'shipped' ? 'ok' : 'neutral'" dot>{{ t(`factory_erp.shipments.statuses.${item.status}`) }}</AppBadge>
                        <AppBadge v-if="item.is_last" tone="brand">{{ t('factory_erp.shipments.last') }}</AppBadge>
                        <span class="min-w-0 flex-1 text-[12.5px] text-muted">{{ day(item.ship_on) }} · {{ t(`factory_erp.shipments.modes.${item.mode}`) }}<template v-if="item.destination"> · {{ item.destination }}</template></span>
                        <span class="tabular text-[13px]">{{ t('factory_erp.tickets.pieces_count', { count: formatNumber(item.pieces) }) }}</span>
                        <span class="tabular w-28 text-end text-[13.5px] font-semibold">{{ money(item.value_minor) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>

        <AppDialog :open="making" :icon="Ship" :title="t('factory_erp.shipments.new_title', { number: order.number })" :description="t('factory_erp.shipments.new_text')" @close="making = false">
            <div class="grid gap-3 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('factory_erp.shipments.ship_on')">
                    <input :id="id" v-model="form.ship_on" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('factory_erp.shipments.mode')">
                    <select :id="id" v-model="form.mode" class="field-input">
                        <option v-for="mode in ['sea', 'air', 'road']" :key="mode" :value="mode">{{ t(`factory_erp.shipments.modes.${mode}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('factory_erp.shipments.from_store')">
                    <select :id="id" v-model="form.warehouse_id" class="field-input">
                        <option v-for="warehouse in setup.warehouses.value" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }} ({{ warehouse.code }})</option>
                    </select>
                </AppField>
                <AppField v-if="foreign" v-slot="{ id, describedby }" :label="t('factory_erp.shipments.rate', { from: order.currency, to: setup.currency.value })" :hint="t('factory_erp.shipments.rate_hint')">
                    <input :id="id" v-model="form.exchange_rate" inputmode="decimal" class="field-input tabular" dir="ltr" :aria-describedby="describedby" placeholder="110.50" />
                </AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="making = false">{{ t('factory_erp.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!form.ship_on || !form.warehouse_id || (foreign && !form.exchange_rate.trim())" @click="make">{{ t('factory_erp.shipments.make') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
