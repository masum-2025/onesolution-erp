<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Check, Plus, Trash2, Undo2, X } from 'lucide-vue-next';
import { PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { pharmacyApi, pieces } from '../api';

/**
 * Supplier returns: batches going back (expired or expiring), sent by one
 * person and approved or rejected by another. Approved, stock leaves.
 */
const ph = pharmacyApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useResource(() => ph.setup());
const can = computed(() => setup.data.value?.data?.can ?? {});
const warehouses = computed(() => setup.data.value?.data?.options?.warehouse_id ?? []);
const status = ref(['submitted', 'approved', 'rejected'].includes(route.query.status) ? route.query.status : '');
const list = useResource(() => ph.returns(status.value ? { status: status.value } : {}));
watch(status, () => list.reload());
const returns = computed(() => list.data.value?.data ?? []);
const batches = useResource(() => ph.stock({ show: 'all' }));
const choices = computed(() => [{ value: '', label: t('pharmacy.returns.all') }, ...['submitted', 'approved', 'rejected'].map((value) => ({ value, label: t(`pharmacy.returns.status.${value}`) }))]);
const tones = { submitted: 'warn', approved: 'ok', rejected: 'neutral' };

// A new return; from "Stock and expiry" a batch comes already filled in.
const newLine = () => ({ key: `${Date.now()}-${Math.random()}`, batch: '', quantity: 1 });
const form = reactive({ open: false, warehouse_id: '', supplier: '', reason: '', lines: [newLine()] });
const errors = ref({});
const saving = ref(false);
const options = computed(() => (batches.data.value?.data ?? []).filter((row) => !form.warehouse_id || row.warehouse_id === form.warehouse_id));
const batchKey = (row) => `${row.medicine_id}|${row.batch_number}`;
if (route.query.medicine && route.query.batch) {
    Object.assign(form, { open: true, warehouse_id: String(route.query.warehouse ?? ''), lines: [{ key: 'from-stock', batch: `${route.query.medicine}|${route.query.batch}`, quantity: Number(route.query.quantity) || 1 }] });
}
watch(() => warehouses.value, (rows) => {
    if (!form.warehouse_id && rows[0]) form.warehouse_id = rows[0].id;
}, { immediate: true });
async function send() {
    saving.value = true;
    errors.value = {};
    try {
        await ph.sendReturn({
            op_id: crypto.randomUUID(), warehouse_id: form.warehouse_id, supplier: form.supplier.trim(), reason: form.reason.trim(),
            lines: form.lines.map((line) => {
                const [medicine_id, batch_number] = line.batch.split('|');
                return { medicine_id, batch_number, quantity: line.quantity };
            }),
        });
        toast.success(t('pharmacy.returns.sent'));
        Object.assign(form, { open: false, supplier: '', reason: '', lines: [newLine()] });
        router.replace({ query: {} });
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const deciding = ref(null);
const note = ref('');
async function decide(approve) {
    try {
        const body = { base_version: deciding.value.row.version, ...(note.value.trim() ? { note: note.value.trim() } : {}) };
        await (approve ? ph.approveReturn(deciding.value.row.id, body) : ph.rejectReturn(deciding.value.row.id, body));
        toast.success(t(approve ? 'pharmacy.returns.approved' : 'pharmacy.returns.rejected'));
        deciding.value = null;
        note.value = '';
        list.reload();
        batches.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <PageFrame :title="t('pharmacy.returns.title')" :description="t('pharmacy.returns.text')" width="full" :resource="list" guide="pharmacy.guide.returns">
        <template v-if="can.manage" #actions>
            <AppButton variant="primary" :icon="Plus" @click="form.open = true">{{ t('pharmacy.returns.new') }}</AppButton>
        </template>
        <template #top>
            <div class="mb-4 print:hidden"><AppSegmented v-model="status" :options="choices" :label="t('pharmacy.returns.filter')" /></div>
        </template>
        <section class="card">
            <EmptyState v-if="!returns.length" :icon="Undo2" :title="t('pharmacy.returns.empty')" :text="t('pharmacy.returns.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in returns" :key="row.id" class="px-5 py-3">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ row.supplier }} <span v-if="row.number" class="font-mono text-[12px] text-muted" dir="ltr">{{ row.number }}</span></span>
                            <span class="block text-[12.5px] text-muted">{{ formatDateTime(row.created_at) }} · {{ row.reason }}</span>
                        </span>
                        <AppBadge :tone="tones[row.status]" dot>{{ t(`pharmacy.returns.status.${row.status}`) }}</AppBadge>
                        <AppButton v-if="row.status === 'submitted' && can.approve && !row.mine" size="sm" variant="primary" @click="deciding = { row }">{{ t('pharmacy.returns.decide') }}</AppButton>
                        <span v-else-if="row.status === 'submitted' && row.mine" class="text-[12px] text-muted">{{ t('pharmacy.returns.waiting') }}</span>
                    </div>
                    <ul class="mt-1.5 text-[12.5px] text-muted">
                        <li v-for="line in row.lines" :key="`${line.medicine_id}-${line.batch_number}`">• {{ textIn(line.name) }} · {{ t('pharmacy.purchases.batch') }} <span class="font-mono" dir="ltr">{{ line.batch_number }}</span><template v-if="line.expires_on"> ({{ formatDate(line.expires_on) }})</template> · {{ t('pharmacy.sell.pieces', { count: formatNumber(pieces(line.quantity_milli)) }) }}</li>
                    </ul>
                </li>
            </ul>
        </section>

        <AppDialog :open="form.open" size="lg" :title="t('pharmacy.returns.new')" :icon="Undo2" @close="form.open = false">
            <form id="pharmacy-return" class="grid gap-3" novalidate @submit.prevent="send">
                <div class="grid gap-3 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('pharmacy.purchases.supplier')" :error="errors.supplier?.[0]">
                        <input :id="id" v-model="form.supplier" class="field-input" maxlength="150" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('pharmacy.purchases.warehouse')" :error="errors.warehouse_id?.[0]">
                        <select :id="id" v-model="form.warehouse_id" class="field-input">
                            <option v-for="row in warehouses" :key="row.id" :value="row.id">{{ row.name }}</option>
                        </select>
                    </AppField>
                </div>
                <AppField v-slot="{ id }" :label="t('pharmacy.returns.reason')" :error="errors.reason?.[0]">
                    <input :id="id" v-model="form.reason" class="field-input" maxlength="300" :placeholder="t('pharmacy.returns.reason_hint')" />
                </AppField>
                <div v-for="(line, index) in form.lines" :key="line.key" class="grid grid-cols-[1fr_7rem_auto] items-end gap-2">
                    <AppField v-slot="{ id }" :label="t('pharmacy.returns.batch')" :error="errors[`lines.${index}.batch_number`]?.[0]">
                        <select :id="id" v-model="line.batch" class="field-input">
                            <option value="" disabled>—</option>
                            <option v-for="row in options" :key="batchKey(row)" :value="batchKey(row)">{{ textIn(row.name) }} · {{ row.batch_number }} · {{ row.expires_on ? formatDate(row.expires_on) : '—' }} ({{ pieces(row.quantity_milli) }})</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('pharmacy.stock.pieces')" :error="errors[`lines.${index}.quantity`]?.[0]">
                        <input :id="id" v-model.number="line.quantity" type="number" min="1" class="field-input tabular" />
                    </AppField>
                    <button type="button" class="mb-0.5 grid size-9 place-items-center rounded-lg text-muted hover:bg-subtle hover:text-bad disabled:opacity-40" :disabled="form.lines.length === 1" :aria-label="t('pharmacy.sell.remove')" @click="form.lines.splice(index, 1)"><Trash2 class="size-4" aria-hidden="true" /></button>
                </div>
                <AppButton variant="ghost" :icon="Plus" class="justify-self-start" @click="form.lines.push(newLine())">{{ t('pharmacy.purchases.add_line') }}</AppButton>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="form.open = false">{{ t('pharmacy.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="pharmacy-return" :loading="saving" :disabled="!form.supplier.trim() || !form.reason.trim() || form.lines.some((line) => !line.batch || line.quantity < 1)">{{ t('pharmacy.returns.send') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="!!deciding" :title="t('pharmacy.returns.decide')" :description="t('pharmacy.returns.decide_text')" :icon="Undo2" @close="deciding = null">
            <AppField v-slot="{ id }" :label="t('pharmacy.returns.note')" :hint="t('pharmacy.returns.note_hint')">
                <input :id="id" v-model="note" class="field-input" maxlength="300" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" :icon="X" :disabled="!note.trim()" @click="decide(false)">{{ t('pharmacy.returns.reject') }}</AppButton>
                <AppButton variant="primary" :icon="Check" @click="decide(true)">{{ t('pharmacy.returns.approve') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
