<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { FileText, Paperclip, Plus, Save, Send, Trash2, X } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import AppField from '@/components/AppField.vue';
import { confirmAction } from '@/lib/dialogs';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { amountText, amountToMinor, kmToTenths, mileageAmount, minorToText, needsReceipt, newOpId, tenthsToKm } from '../lib';

/**
 * Writing one's own claim: a title, the expenses (day, kind, what for, the
 * amount, or kilometres for a mileage kind) and a receipt for each line
 * above the limit. Saved as a draft first (receipts need a saved line),
 * then sent for approval. The server checks every rule again and says
 * clearly what is wrong; a repeated first save is the same draft.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    // the draft's detail, or null for a new claim
    claim: { type: Object, default: null },
    // active kinds [{ id, name, mileage }]
    categories: { type: Array, default: () => [] },
    // { receipt_required_above, max_claim_age_days, mileage_rate, receipt_max_kb }
    limits: { type: Object, default: () => ({}) },
    currency: { type: String, default: 'BDT' },
    // expensesApi(org)
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'changed', 'sent']);

const current = ref(null);
const form = reactive({ title: '', lines: [] });
const errors = ref({});
const saving = ref(false);
const uploading = ref(null);
let opId = newOpId();
let saved = '';
let nextKey = 1;

const today = () => new Date().toISOString().slice(0, 10);
const kindOf = (id) => props.categories.find((item) => item.id === id) ?? null;
const blankLine = () => ({ key: nextKey++, id: null, spent_on: today(), category_id: props.categories[0]?.id ?? '', description: '', amount: '', km: '' });

function load(claim) {
    current.value = claim;
    form.title = claim?.title ?? '';
    form.lines = claim
        ? claim.lines.map((line) => ({
            key: nextKey++, id: line.id, spent_on: line.spent_on, category_id: line.category_id, description: line.description,
            amount: line.mileage ? '' : minorToText(line.amount_minor, props.currency), km: line.mileage ? tenthsToKm(line.distance_tenths) : '',
        }))
        : [blankLine()];
    saved = JSON.stringify(body());
}

watch(() => props.open, (open) => {
    if (!open) return;
    errors.value = {};
    opId = newOpId();
    load(props.claim);
});

const amountOf = (line) => (kindOf(line.category_id)?.mileage ? mileageAmount(kmToTenths(line.km), props.limits.mileage_rate) : amountToMinor(line.amount, props.currency) ?? 0);
const total = computed(() => form.lines.reduce((sum, line) => sum + amountOf(line), 0));
const receiptsOf = (line) => (current.value?.receipts ?? []).filter((receipt) => line.id && receipt.line_id === line.id);
const looseReceipts = computed(() => (current.value?.receipts ?? []).filter((receipt) => !receipt.line_id || !form.lines.some((line) => line.id === receipt.line_id)));
const wantsReceipt = (line) => needsReceipt({ mileage: kindOf(line.category_id)?.mileage, amount_minor: amountOf(line) }, props.limits.receipt_required_above ?? 0) && !receiptsOf(line).length;
const oldest = computed(() => {
    const date = new Date();
    date.setDate(date.getDate() - (props.limits.max_claim_age_days ?? 60));
    return date.toISOString().slice(0, 10);
});

function body() {
    return {
        title: form.title.trim(),
        lines: form.lines.map((line) => {
            const mileage = kindOf(line.category_id)?.mileage;
            return {
                ...(line.id ? { id: line.id } : {}),
                spent_on: line.spent_on,
                category_id: line.category_id,
                description: line.description.trim(),
                amount_minor: mileage ? null : amountToMinor(line.amount, props.currency),
                distance_tenths: mileage ? kmToTenths(line.km) : null,
            };
        }),
    };
}
const dirty = () => !current.value || JSON.stringify(body()) !== saved;
const ready = computed(() => form.title.trim().length >= 2 && form.lines.length > 0 && form.lines.every((line) => line.category_id && line.spent_on && line.description.trim().length >= 2));

function addLine() {
    form.lines.push(blankLine());
}
function removeLine(index) {
    form.lines.splice(index, 1);
}

/** Saves the draft when something changed; false when the server said no. */
async function save(quiet = false) {
    if (!dirty()) return true;
    saving.value = true;
    errors.value = {};
    try {
        const response = current.value
            ? await props.calls.updateDraft(current.value.id, { ...body(), base_version: current.value.version })
            : await props.calls.draft({ ...body(), op_id: opId });
        load(response.data);
        if (!quiet) toast.success(t('expenses.editor.saved'));
        emit('changed');
        return true;
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
        return false;
    } finally {
        saving.value = false;
    }
}

async function attach(line, event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (file.size > (props.limits.receipt_max_kb ?? 5120) * 1024) {
        toast.error(t('expenses.editor.too_big', { kb: props.limits.receipt_max_kb }));
        return;
    }
    // Saving first gives a new line its id; the server keeps the lines in the order sent.
    const index = form.lines.indexOf(line);
    uploading.value = index;
    try {
        if (!(await save(true))) return;
        const lineId = current.value.lines[index]?.id ?? null;
        const response = await props.calls.addReceipt(current.value.id, file, lineId);
        current.value = response.data;
        toast.success(t('expenses.editor.receipt_added'));
    } catch (error) {
        toast.error(error.errors?.file?.[0] ?? error.errors?.line_id?.[0] ?? error.message);
    } finally {
        uploading.value = null;
    }
}

async function detach(receipt) {
    try {
        const response = await props.calls.removeReceipt(current.value.id, receipt.id);
        current.value = response.data;
    } catch (error) {
        toast.error(error.message);
    }
}

async function send() {
    if (!(await save(true))) return;
    saving.value = true;
    try {
        await props.calls.mine(current.value.id, 'submit', { base_version: current.value.version });
        toast.success(t('expenses.editor.sent'));
        emit('sent');
    } catch (error) {
        if (error.code === 'version_conflict') emit('changed');
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function discard() {
    if (!current.value) {
        emit('close');
        return;
    }
    const confirmed = await confirmAction({ title: t('expenses.editor.discard_title'), message: t('expenses.editor.discard_text'), confirmLabel: t('expenses.editor.discard'), danger: true });
    if (!confirmed) return;
    try {
        await props.calls.discard(current.value.id, { base_version: current.value.version });
        toast.success(t('expenses.editor.discarded'));
        emit('changed');
        emit('close');
    } catch (error) {
        toast.error(error.message);
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDrawer :open="open" :title="current ? t('expenses.editor.edit_title') : t('expenses.editor.new_title')" width="sm:max-w-[640px]" @close="emit('close')">
        <form id="expenses-claim" class="space-y-5" novalidate @submit.prevent="save()">
            <AppField v-slot="{ id }" :label="t('expenses.editor.title')" :hint="t('expenses.editor.title_hint')" :error="fieldError('title')">
                <input :id="id" v-model="form.title" class="field-input" maxlength="150" />
            </AppField>

            <ol class="space-y-3" :aria-label="t('expenses.editor.lines')">
                <li v-for="(line, index) in form.lines" :key="line.key" class="rounded-xl border border-line p-4">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <p class="text-[13px] font-semibold text-fg-2">{{ t('expenses.editor.line', { number: index + 1 }) }}</p>
                        <AppButton v-if="form.lines.length > 1" size="icon-sm" variant="ghost" :icon="X" :aria-label="t('expenses.editor.remove_line')" @click="removeLine(index)" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <AppField v-slot="{ id }" :label="t('expenses.editor.kind')" :error="fieldError(`lines.${index}.category_id`)">
                            <select :id="id" v-model="line.category_id" class="field-input">
                                <option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('expenses.editor.spent_on')" :error="fieldError(`lines.${index}.spent_on`)">
                            <input :id="id" v-model="line.spent_on" type="date" class="field-input" :min="oldest" :max="today()" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('expenses.editor.description')" :error="fieldError(`lines.${index}.description`)" class="sm:col-span-2">
                            <input :id="id" v-model="line.description" class="field-input" maxlength="300" :placeholder="t('expenses.editor.description_hint')" />
                        </AppField>
                        <template v-if="kindOf(line.category_id)?.mileage">
                            <AppField v-slot="{ id }" :label="t('expenses.editor.km')" :error="fieldError(`lines.${index}.distance_tenths`)">
                                <input :id="id" v-model="line.km" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
                            </AppField>
                            <p class="self-end pb-2 text-[13px] text-muted">
                                {{ limits.mileage_rate ? t('expenses.editor.km_amount', { rate: amountText(limits.mileage_rate, currency), amount: amountText(amountOf(line), currency) }) : t('expenses.editor.no_rate') }}
                            </p>
                        </template>
                        <AppField v-else v-slot="{ id }" :label="t('expenses.editor.amount', { currency })" :error="fieldError(`lines.${index}.amount_minor`)">
                            <input :id="id" v-model="line.amount" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
                        </AppField>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <a
                            v-for="receipt in receiptsOf(line)"
                            :key="receipt.id"
                            :href="receipt.url"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex max-w-full items-center gap-1.5 rounded-lg bg-subtle px-2.5 py-1 text-[12.5px]"
                        >
                            <FileText class="size-3.5 shrink-0" aria-hidden="true" /><span class="truncate">{{ receipt.name }}</span>
                        </a>
                        <AppButton v-for="receipt in receiptsOf(line)" :key="`x-${receipt.id}`" size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('expenses.editor.remove_receipt', { name: receipt.name })" @click="detach(receipt)" />
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-line px-2.5 py-1 text-[12.5px] font-medium hover:bg-subtle" :class="uploading === index ? 'opacity-60' : ''">
                            <Paperclip class="size-3.5" aria-hidden="true" />
                            {{ uploading === index ? t('expenses.editor.uploading') : t('expenses.editor.add_receipt') }}
                            <input type="file" class="sr-only" accept="image/jpeg,image/png,image/webp,application/pdf" :disabled="uploading !== null" @change="attach(line, $event)" />
                        </label>
                        <p v-if="wantsReceipt(line)" class="text-[12.5px] text-warn">{{ t('expenses.editor.receipt_needed', { amount: amountText(limits.receipt_required_above, currency) }) }}</p>
                    </div>
                </li>
            </ol>
            <AppButton :icon="Plus" @click="addLine">{{ t('expenses.editor.add_line') }}</AppButton>

            <div v-if="looseReceipts.length" class="space-y-1">
                <p class="text-[12.5px] text-muted">{{ t('expenses.editor.other_receipts') }}</p>
                <div class="flex flex-wrap gap-2">
                    <span v-for="receipt in looseReceipts" :key="receipt.id" class="inline-flex items-center gap-1">
                        <a :href="receipt.url" target="_blank" rel="noopener" class="rounded-lg bg-subtle px-2.5 py-1 text-[12.5px]">{{ receipt.name }}</a>
                        <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('expenses.editor.remove_receipt', { name: receipt.name })" @click="detach(receipt)" />
                    </span>
                </div>
            </div>

            <p class="flex items-baseline justify-between rounded-xl bg-subtle px-4 py-3">
                <span class="text-[13.5px] font-medium">{{ t('expenses.editor.total') }}</span>
                <span class="tabular text-[20px] font-semibold">{{ amountText(total, currency) }}</span>
            </p>
            <p class="text-[12.5px] text-muted">{{ t('expenses.editor.rules', { amount: amountText(limits.receipt_required_above, currency), days: limits.max_claim_age_days }) }}</p>
        </form>
        <template #footer>
            <div class="flex w-full flex-wrap items-center justify-between gap-2">
                <AppButton variant="danger-soft" :icon="Trash2" @click="discard">{{ current ? t('expenses.editor.discard') : t('expenses.common.cancel') }}</AppButton>
                <div class="flex flex-wrap gap-2">
                    <AppButton type="submit" form="expenses-claim" :icon="Save" :loading="saving" :disabled="!ready">{{ t('expenses.editor.save') }}</AppButton>
                    <AppButton variant="primary" :icon="Send" :loading="saving" :disabled="!ready || total <= 0" @click="send">{{ t('expenses.editor.send') }}</AppButton>
                </div>
            </div>
        </template>
    </AppDrawer>
</template>
