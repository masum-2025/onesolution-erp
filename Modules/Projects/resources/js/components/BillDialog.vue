<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { formatMoney } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { amountToMinor, quantityToMilli } from '../lib';

/**
 * A quote (estimate or quotation, made in CRM) or an invoice (made in
 * Accounting) for the project's client: lines of work with quantity and
 * price. Both start as drafts in their module, where VAT, sending,
 * accepting and approval happen.
 */
const props = defineProps({
    open: Boolean,
    mode: { type: String, default: 'quote' }, // quote | invoice
    project: { type: Object, default: null },
    currency: { type: String, default: null },
    today: { type: String, default: '' },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);

const form = reactive({ kind: 'quotation', issue_date: '', lines: [] });
const errors = ref({});
const saving = ref(false);
const blank = () => ({ description: '', quantity: '1', price: '' });

watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, { kind: 'quotation', issue_date: props.today, lines: [{ ...blank(), description: props.project?.service ?? '' }] });
    errors.value = {};
});

const kinds = computed(() => [{ value: 'estimate', label: t('projects.money.estimate') }, { value: 'quotation', label: t('projects.money.quotation') }]);
const parsed = computed(() => form.lines.map((line) => ({
    description: line.description.trim(),
    quantity_milli: quantityToMilli(line.quantity),
    unit_price_minor: amountToMinor(line.price, props.currency),
})));
const valid = computed(() => parsed.value.length > 0 && parsed.value.every((line) => line.description && line.quantity_milli && line.unit_price_minor !== null));
// Before VAT (the module adds it by its own rules): whole minor units, quantity in thousandths, rounded half up.
const total = computed(() => parsed.value.reduce((sum, line) => sum + (line.quantity_milli && line.unit_price_minor !== null ? Math.floor((line.quantity_milli * line.unit_price_minor + 500) / 1000) : 0), 0));

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = props.mode === 'quote'
            ? await props.calls.quote(props.project.id, form.kind, parsed.value)
            : await props.calls.invoice(props.project.id, form.issue_date, parsed.value);
        toast.success(t(props.mode === 'quote' ? 'projects.money.quote_made' : 'projects.money.invoice_made', { number: data.number ?? '' }));
        emit('saved', data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
const lineError = (index) => Object.entries(errors.value).find(([key]) => key.startsWith(`lines.${index}.`))?.[1]?.[0] ?? null;
</script>

<template>
    <AppDialog :open="open" :title="t(mode === 'quote' ? 'projects.money.quote_title' : 'projects.money.invoice_title')"
        :description="project ? `${project.code} · ${project.client.name}` : ''" @close="emit('close')">
        <form id="bill-form" class="space-y-4" novalidate @submit.prevent="save">
            <AppSegmented v-if="mode === 'quote'" v-model="form.kind" size="sm" :label="t('projects.money.kind')" :options="kinds" />
            <AppField v-else v-slot="{ id }" :label="t('projects.money.issue_date')" :error="errors.issue_date?.[0]">
                <input :id="id" v-model="form.issue_date" type="date" class="field-input sm:w-56" />
            </AppField>
            <fieldset>
                <legend class="mb-2 text-[13px] font-medium text-fg-2">{{ t('projects.money.lines') }}</legend>
                <ol class="space-y-2">
                    <li v-for="(line, index) in form.lines" :key="index" class="grid grid-cols-[minmax(0,1fr)_5rem_7.5rem_auto] items-start gap-2">
                        <label class="sr-only" :for="`line-${index}`">{{ t('projects.money.description') }}</label>
                        <input :id="`line-${index}`" v-model="line.description" class="field-input" maxlength="255" :placeholder="t('projects.money.description')" />
                        <label class="sr-only" :for="`qty-${index}`">{{ t('projects.money.quantity') }}</label>
                        <input :id="`qty-${index}`" v-model="line.quantity" class="field-input tabular" inputmode="decimal" :placeholder="t('projects.money.quantity')" />
                        <label class="sr-only" :for="`price-${index}`">{{ t('projects.money.price') }}</label>
                        <input :id="`price-${index}`" v-model="line.price" class="field-input tabular" inputmode="decimal" :placeholder="t('projects.money.price')" />
                        <AppButton size="icon" variant="ghost" class="text-bad" :icon="Trash2" :disabled="form.lines.length === 1" :aria-label="t('projects.money.remove_line')" @click="form.lines.splice(index, 1)" />
                        <p v-if="lineError(index)" class="col-span-4 text-[12.5px] text-bad" role="alert">{{ lineError(index) }}</p>
                    </li>
                </ol>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <AppButton size="sm" :icon="Plus" @click="form.lines.push(blank())">{{ t('projects.money.add_line') }}</AppButton>
                    <p class="text-[13.5px]">{{ t('projects.money.before_vat') }} <strong class="tabular">{{ formatMoney({ amount: total, currency }) }}</strong></p>
                </div>
            </fieldset>
            <p class="rounded-lg bg-subtle px-3 py-2 text-[12.5px] text-fg-2">{{ t(mode === 'quote' ? 'projects.money.quote_hint' : 'projects.money.invoice_hint') }}</p>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('projects.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="bill-form" :loading="saving" :disabled="!valid">{{ t(mode === 'quote' ? 'projects.money.make_quote' : 'projects.money.make_invoice') }}</AppButton>
        </template>
    </AppDialog>
</template>
