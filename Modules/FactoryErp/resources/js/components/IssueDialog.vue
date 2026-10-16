<script setup>
import { computed, ref, watch } from 'vue';
import { PackageMinus, PackagePlus } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { confirmAction } from '@/lib/dialogs';
import { newOpId } from '@/lib/billing';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { currentOrganization } from '@/lib/session';
import { useFactorySetup } from '../setup';
import { fillQuantity, formatMilli, milliToText, quantityToMilli } from '../lib';

/**
 * Materials to the floor for an order (kind "issue") or back from it
 * ("return"): the store, the day, a quantity per material of the order.
 * "What is left" fills in what the order still needs (or, coming back,
 * everything on the floor). One click is sent once (op id). More than the
 * order needs is asked again where the company only warns.
 */
const props = defineProps({
    open: Boolean,
    kind: { type: String, default: 'issue' },
    order: { type: Object, required: true },
    rows: { type: Array, required: true },
});
const emit = defineEmits(['close', 'done']);
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();

const warehouseId = ref('');
const issuedOn = ref('');
const note = ref('');
const typed = ref({});
let opId = '';
const busy = ref(false);
const issuing = computed(() => props.kind === 'issue');
// Coming back: only what is on the floor.
const lines = computed(() => props.rows.filter((row) => (issuing.value ? row.need_milli > 0 : row.issued_milli > 0)));
const limit = (row) => (issuing.value ? row.to_issue_milli : row.issued_milli);

watch(() => props.open, (open) => {
    if (!open) return;
    warehouseId.value = setup.warehouses.value[0]?.id ?? '';
    issuedOn.value = setup.data.value?.today ?? '';
    note.value = '';
    typed.value = {};
    opId = newOpId();
});

const bad = (row) => (typed.value[row.item_id] ?? '').trim() !== '' && quantityToMilli(typed.value[row.item_id]) === null;
const chosen = computed(() => lines.value
    .map((row) => ({ item_id: row.item_id, quantity_milli: quantityToMilli(typed.value[row.item_id] ?? '') }))
    .filter((line) => line.quantity_milli !== null));
const ready = computed(() => warehouseId.value && issuedOn.value && chosen.value.length > 0 && !lines.value.some(bad));

// Giving: what is left, as far as the stores hold it, in whole units where the unit has no decimals. Taking back: everything on the floor.
function fillLeft() {
    const decimals = (row) => setup.data.value?.units?.[setup.items.value[row.item_id]?.unit_id]?.decimals ?? 3;
    const quantity = (row) => (issuing.value ? fillQuantity(row.to_issue_milli, row.on_hand_milli, decimals(row)) : row.issued_milli);
    typed.value = Object.fromEntries(lines.value.map((row) => [row.item_id, quantity(row)]).filter(([, milli]) => milli > 0).map(([id, milli]) => [id, milliToText(milli)]));
}

async function send(allowOver = false) {
    busy.value = true;
    try {
        await api.issue(props.order.id, {
            kind: props.kind, warehouse_id: warehouseId.value, issued_on: issuedOn.value, lines: chosen.value,
            ...(note.value.trim() ? { note: note.value.trim() } : {}), ...(allowOver ? { allow_over: true } : {}), op_id: opId,
        });
        toast.success(t(issuing.value ? 'factory_erp.issues.issued' : 'factory_erp.issues.returned'));
        emit('done');
    } catch (error) {
        if (error.code === 'over_issue' && !allowOver) {
            const details = (error.data?.items ?? []).map((item) => t('factory_erp.issues.over_line', {
                item: setup.itemName(item.item_id), need: formatMilli(item.need_milli), after: formatMilli(item.issued_milli + item.quantity_milli), unit: setup.unitCode(item.item_id),
            }));
            busy.value = false;
            const yes = await confirmAction({ title: t('factory_erp.issues.over_title'), message: t('factory_erp.issues.over_text'), details, confirmLabel: t('factory_erp.issues.over_yes') });
            if (yes) await send(true);
            return;
        }
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppDialog
        :open="open"
        :icon="issuing ? PackagePlus : PackageMinus"
        :title="t(issuing ? 'factory_erp.issues.give_title' : 'factory_erp.issues.back_title', { number: order.number })"
        :description="t(issuing ? 'factory_erp.issues.give_text' : 'factory_erp.issues.back_text')"
        @close="emit('close')"
    >
        <div class="grid gap-3 sm:grid-cols-2">
            <AppField v-slot="{ id }" :label="t('factory_erp.issues.store')">
                <select :id="id" v-model="warehouseId" class="field-input">
                    <option v-for="warehouse in setup.warehouses.value" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }} ({{ warehouse.code }})</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('factory_erp.issues.day')">
                <input :id="id" v-model="issuedOn" type="date" class="field-input" :max="setup.data.value?.today" />
            </AppField>
        </div>
        <p v-if="!setup.warehouses.value.length" class="mt-3 rounded-xl bg-warn-soft px-3 py-2 text-[13px] text-warn">{{ t('factory_erp.issues.no_store') }}</p>

        <div class="mt-4 flex items-center justify-between gap-3">
            <h3 class="text-[13px] font-semibold text-fg">{{ t('factory_erp.issues.materials') }}</h3>
            <AppButton size="sm" variant="ghost" @click="fillLeft">{{ t(issuing ? 'factory_erp.issues.fill_left' : 'factory_erp.issues.fill_all') }}</AppButton>
        </div>
        <ul class="mt-2 divide-y divide-line rounded-xl border border-line">
            <li v-for="row in lines" :key="row.item_id" class="flex flex-wrap items-center gap-3 px-3 py-2">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[13.5px] font-medium text-fg">{{ row.name }}</p>
                    <p class="text-[12px] text-muted">
                        {{ t(issuing ? 'factory_erp.issues.left_hint' : 'factory_erp.issues.floor_hint', { quantity: formatMilli(limit(row)), unit: setup.unitCode(row.item_id) }) }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <input
                        v-model="typed[row.item_id]"
                        inputmode="decimal"
                        class="field-input tabular w-28 text-end"
                        :class="bad(row) ? 'border-bad' : ''"
                        :aria-invalid="bad(row)"
                        :aria-label="t('factory_erp.issues.quantity_of', { item: row.name })"
                        placeholder="0"
                    />
                    <span class="w-10 text-[12px] text-muted">{{ setup.unitCode(row.item_id) }}</span>
                </div>
            </li>
            <li v-if="!lines.length" class="px-3 py-4 text-center text-[13px] text-muted">{{ t(issuing ? 'factory_erp.issues.nothing_needed' : 'factory_erp.issues.nothing_out') }}</li>
        </ul>

        <AppField v-slot="{ id }" class="mt-4" :label="t('factory_erp.issues.note')">
            <input :id="id" v-model="note" class="field-input" maxlength="300" :placeholder="t('factory_erp.issues.note_hint')" />
        </AppField>

        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('factory_erp.cancel') }}</AppButton>
            <AppButton variant="primary" :loading="busy" :disabled="!ready" @click="send()">{{ t(issuing ? 'factory_erp.issues.give' : 'factory_erp.issues.take_back') }}</AppButton>
        </template>
    </AppDialog>
</template>
