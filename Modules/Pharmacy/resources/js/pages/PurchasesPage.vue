<script setup>
import { computed, reactive, ref } from 'vue';
import { PackagePlus, Plus, Trash2 } from 'lucide-vue-next';
import { DateInput, MoneyInput, PageFrame } from '@/ui';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { packsOf, pharmacyApi } from '../api';

/** Buying medicines: the supplier, then each medicine with its pack, quantity, cost, batch number and expiry. Stock is there at once. */
const ph = pharmacyApi(currentOrganization().id);
const setup = useResource(() => ph.setup());
const list = useResource(() => ph.purchases());
const medicines = useResource(() => ph.medicines({ per_page: 100 }));
const currency = computed(() => setup.data.value?.data?.currency ?? null);
const warehouses = computed(() => setup.data.value?.data?.options?.warehouse_id ?? []);
const canBuy = computed(() => !!setup.data.value?.data?.can?.buy);
const medicineList = computed(() => (medicines.data.value?.data ?? []).filter((row) => row.is_active));
const byId = (id) => medicineList.value.find((row) => row.id === id) ?? null;
const purchases = computed(() => list.data.value?.data ?? []);

const today = new Date().toISOString().slice(0, 10);
const newLine = () => ({ medicine_id: '', pack: 'box', quantity: 1, pack_cost_minor: null, batch_number: '', expires_on: '' });
const form = reactive({ warehouse_id: '', supplier: '', reference: '', purchased_on: today, lines: [newLine()] });
const errors = ref({});
const saving = ref(false);
const lineTotal = (line) => (line.pack_cost_minor ?? 0) * (line.quantity || 0);
const total = computed(() => form.lines.reduce((sum, line) => sum + lineTotal(line), 0));
const ready = computed(() => form.supplier.trim() && (form.warehouse_id || warehouses.value[0]) && form.lines.length
    && form.lines.every((line) => line.medicine_id && line.quantity > 0 && line.pack_cost_minor !== null && line.batch_number.trim() && line.expires_on));
const lineError = (index, field) => errors.value[`lines.${index}.${field}`]?.[0] ?? null;

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await ph.buy({
            op_id: crypto.randomUUID(), warehouse_id: form.warehouse_id || warehouses.value[0]?.id, supplier: form.supplier.trim(), reference: form.reference.trim() || null, purchased_on: form.purchased_on,
            lines: form.lines.map((line) => ({ ...line, batch_number: line.batch_number.trim() })),
        });
        toast.success(t('pharmacy.purchases.saved'));
        Object.assign(form, { supplier: '', reference: '', purchased_on: today, lines: [newLine()] });
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
const exported = computed(() => ({
    module: 'pharmacy',
    page: 'pharmacy.purchases',
    columns: [
        { key: 'purchased_on', label: t('pharmacy.purchases.date'), value: (row) => formatDate(row.purchased_on) },
        { key: 'document_number', label: t('pharmacy.purchases.number') },
        { key: 'supplier', label: t('pharmacy.purchases.supplier') },
        { key: 'line_count', label: t('pharmacy.purchases.lines') },
        { key: 'total_minor', label: t('pharmacy.purchases.total'), type: 'money' },
    ],
    rows: purchases.value,
}));
</script>

<template>
    <PageFrame :title="t('pharmacy.purchases.title')" :description="t('pharmacy.purchases.text')" width="full" :resource="list" :export="exported" guide="pharmacy.guide.purchases">
        <section v-if="canBuy" class="card mb-4 p-4 print:hidden">
            <h2 class="mb-3 text-[15px] font-semibold">{{ t('pharmacy.purchases.new') }}</h2>
            <form class="grid gap-4" novalidate @submit.prevent="save">
                <div class="grid gap-3 sm:grid-cols-4">
                    <AppField v-slot="{ id }" :label="t('pharmacy.purchases.supplier')" :error="errors.supplier?.[0]" class="sm:col-span-2">
                        <input :id="id" v-model="form.supplier" class="field-input" maxlength="150" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('pharmacy.purchases.date')" :error="errors.purchased_on?.[0]">
                        <DateInput :id="id" v-model="form.purchased_on" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('pharmacy.purchases.warehouse')" :error="errors.warehouse_id?.[0]">
                        <select :id="id" v-model="form.warehouse_id" class="field-input">
                            <option v-for="row in warehouses" :key="row.id" :value="row.id">{{ row.name }}</option>
                        </select>
                    </AppField>
                </div>
                <div v-for="(line, index) in form.lines" :key="index" class="grid gap-2 rounded-xl border border-line p-3 sm:grid-cols-[2fr_1fr_1fr_1.3fr_1.3fr_1.3fr_auto]">
                    <AppField v-slot="{ id }" :label="t('pharmacy.purchases.medicine')" :error="lineError(index, 'medicine_id')">
                        <select :id="id" v-model="line.medicine_id" class="field-input">
                            <option value="" disabled>—</option>
                            <option v-for="row in medicineList" :key="row.id" :value="row.id">{{ textIn(row.brand_name) }} {{ row.strength }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('pharmacy.sell.pack')" :error="lineError(index, 'pack')">
                        <select :id="id" v-model="line.pack" class="field-input">
                            <option v-for="pack in byId(line.medicine_id) ? packsOf(byId(line.medicine_id)) : ['piece', 'strip', 'box']" :key="pack" :value="pack">{{ t(`pharmacy.packs.${pack}`) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('pharmacy.sell.quantity')" :error="lineError(index, 'quantity')">
                        <input :id="id" v-model.number="line.quantity" type="number" min="1" class="field-input tabular" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('pharmacy.purchases.cost')" :error="lineError(index, 'pack_cost_minor')">
                        <MoneyInput :id="id" v-model="line.pack_cost_minor" :currency="currency" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('pharmacy.purchases.batch')" :error="lineError(index, 'batch_number')">
                        <input :id="id" v-model="line.batch_number" class="field-input font-mono" dir="ltr" maxlength="60" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('pharmacy.purchases.expiry')" :error="lineError(index, 'expires_on')">
                        <DateInput :id="id" v-model="line.expires_on" />
                    </AppField>
                    <button type="button" class="mt-6 grid size-9 place-items-center rounded-lg text-muted hover:bg-subtle hover:text-bad disabled:opacity-40" :disabled="form.lines.length === 1"
                            :aria-label="t('pharmacy.sell.remove')" @click="form.lines.splice(index, 1)"><Trash2 class="size-4" aria-hidden="true" /></button>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <AppButton variant="ghost" :icon="Plus" @click="form.lines.push(newLine())">{{ t('pharmacy.purchases.add_line') }}</AppButton>
                    <span class="text-[14px]">{{ t('pharmacy.purchases.total') }} <strong class="tabular">{{ formatMoney({ amount: total, currency }) }}</strong></span>
                    <AppButton variant="primary" type="submit" :loading="saving" :disabled="!ready">{{ t('pharmacy.purchases.save') }}</AppButton>
                </div>
            </form>
        </section>

        <section class="card">
            <EmptyState v-if="!purchases.length" :icon="PackagePlus" :title="t('pharmacy.purchases.empty')" :text="t('pharmacy.purchases.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in purchases" :key="row.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ row.supplier }} <span class="font-mono text-[12px] text-muted" dir="ltr">{{ row.document_number }}</span></span>
                        <span class="block text-[12.5px] text-muted">{{ formatDate(row.purchased_on) }} · {{ t('pharmacy.purchases.line_count', { count: row.line_count }) }}</span>
                    </span>
                    <span class="tabular text-[14px] font-semibold">{{ formatMoney({ amount: row.total_minor, currency: row.currency }) }}</span>
                </li>
            </ul>
        </section>
    </PageFrame>
</template>
