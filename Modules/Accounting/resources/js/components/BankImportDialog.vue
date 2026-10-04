<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { FileUp } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { csvPreview, guessBankColumns } from '../lib';

/**
 * Bring in a statement file (CSV): choose it, say which columns hold the
 * date, words and amount (guessed from the headings, or the account's last
 * choice), see the first lines, and send. The server reads the whole file.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    books: { type: Object, required: true },
    accountId: { type: String, required: true },
    format: { type: Object, default: null },
});
const emit = defineEmits(['close', 'imported']);

// How the bank writes dates, shown as the last day of a year.
const DATE_FORMATS = { 'd/m/Y': '31/12/2026', 'Y-m-d': '2026-12-31', 'd-m-Y': '31-12-2026', 'd.m.Y': '31.12.2026', 'm/d/Y': '12/31/2026', 'd-M-Y': '31-Dec-2026', 'd M Y': '31 Dec 2026', 'd/m/y': '31/12/26' };
const file = ref(null);
const preview = ref({ columns: [], rows: [] });
const mode = ref('in_out');
const columns = reactive({ date: '', description: '', reference: '', amount: '', money_in: '', money_out: '' });
const dateFormat = ref('d/m/Y');
const sending = ref(false);
const errors = ref({});

watch(() => props.open, (open) => {
    if (!open) return;
    file.value = null;
    preview.value = { columns: [], rows: [] };
    errors.value = {};
});

async function choose(event) {
    const chosen = event.target.files?.[0] ?? null;
    file.value = chosen;
    errors.value = {};
    if (!chosen) return;
    preview.value = csvPreview(await chosen.slice(0, 64 * 1024).text());
    const known = new Set(preview.value.columns);
    const saved = props.format?.columns ?? {};
    // The account's last choice when the file has those columns; otherwise a guess from the headings.
    const pick = saved.date && known.has(saved.date) ? saved : guessBankColumns(preview.value.columns);
    Object.assign(columns, { date: '', description: '', reference: '', amount: '', money_in: '', money_out: '', ...pick });
    mode.value = columns.amount ? 'amount' : 'in_out';
    if (props.format?.date_format) dateFormat.value = props.format.date_format;
}

const sample = computed(() => {
    const at = (name) => preview.value.columns.indexOf(name);
    return preview.value.rows.map((row) => ({
        date: row[at(columns.date)] ?? '',
        description: row[at(columns.description)] ?? '',
        amount: mode.value === 'amount' ? (row[at(columns.amount)] ?? '') : [row[at(columns.money_in)], row[at(columns.money_out)] ? `−${row[at(columns.money_out)]}` : ''].filter(Boolean).join(' '),
    }));
});
const ready = computed(() => file.value && columns.date && (mode.value === 'amount' ? columns.amount : columns.money_in || columns.money_out));

async function send() {
    const body = new FormData();
    body.append('file', file.value);
    body.append('date_format', dateFormat.value);
    const chosen = mode.value === 'amount' ? ['date', 'description', 'reference', 'amount'] : ['date', 'description', 'reference', 'money_in', 'money_out'];
    for (const key of chosen) if (columns[key]) body.append(`columns[${key}]`, columns[key]);

    sending.value = true;
    errors.value = {};
    try {
        const { data } = await props.books.importStatement(props.accountId, body);
        toast.success(t('accounting.bank.imported', { added: data.added, skipped: data.skipped }));
        emit('imported');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        sending.value = false;
    }
}

const fileErrors = computed(() => [errors.value.file ?? [], errors.value['columns.date'] ?? [], errors.value['columns.amount'] ?? []].flat());
</script>

<template>
    <AppDialog :open="open" :title="t('accounting.bank.import_title')" :description="t('accounting.bank.import_text')" size="lg" :icon="FileUp" @close="emit('close')">
        <form id="accounting-bank-import" class="grid gap-4" novalidate @submit.prevent="send">
            <AppField v-slot="{ id }" :label="t('accounting.bank.file')" :hint="t('accounting.bank.file_hint')">
                <input :id="id" type="file" accept=".csv,text/csv,text/plain" class="field-input py-2" @change="choose" />
            </AppField>

            <ul v-if="fileErrors.length" class="grid gap-1 rounded-lg bg-bad-soft px-3 py-2 text-[12.5px] text-bad" role="alert">
                <li v-for="(message, index) in fileErrors" :key="index">{{ message }}</li>
            </ul>

            <template v-if="preview.columns.length">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('accounting.bank.col_date')">
                        <select :id="id" v-model="columns.date" class="field-input">
                            <option value="">{{ t('accounting.bank.choose_column') }}</option>
                            <option v-for="name in preview.columns" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('accounting.bank.date_format')">
                        <select :id="id" v-model="dateFormat" class="field-input" dir="ltr">
                            <option v-for="(example, format) in DATE_FORMATS" :key="format" :value="format">{{ example }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('accounting.bank.col_description')" optional>
                        <select :id="id" v-model="columns.description" class="field-input">
                            <option value="">—</option>
                            <option v-for="name in preview.columns" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('accounting.bank.col_reference')" optional>
                        <select :id="id" v-model="columns.reference" class="field-input">
                            <option value="">—</option>
                            <option v-for="name in preview.columns" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </AppField>
                </div>

                <fieldset class="grid gap-3">
                    <legend class="mb-1 text-[13px] font-medium">{{ t('accounting.bank.amount_mode') }}</legend>
                    <div class="flex flex-wrap gap-4 text-[13px]">
                        <label class="flex items-center gap-2"><input v-model="mode" type="radio" value="in_out" /> {{ t('accounting.bank.mode_in_out') }}</label>
                        <label class="flex items-center gap-2"><input v-model="mode" type="radio" value="amount" /> {{ t('accounting.bank.mode_amount') }}</label>
                    </div>
                    <div v-if="mode === 'in_out'" class="grid gap-4 sm:grid-cols-2">
                        <AppField v-slot="{ id }" :label="t('accounting.bank.col_money_in')">
                            <select :id="id" v-model="columns.money_in" class="field-input">
                                <option value="">—</option>
                                <option v-for="name in preview.columns" :key="name" :value="name">{{ name }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('accounting.bank.col_money_out')">
                            <select :id="id" v-model="columns.money_out" class="field-input">
                                <option value="">—</option>
                                <option v-for="name in preview.columns" :key="name" :value="name">{{ name }}</option>
                            </select>
                        </AppField>
                    </div>
                    <AppField v-else v-slot="{ id }" :label="t('accounting.bank.col_amount')" :hint="t('accounting.bank.amount_hint')">
                        <select :id="id" v-model="columns.amount" class="field-input">
                            <option value="">{{ t('accounting.bank.choose_column') }}</option>
                            <option v-for="name in preview.columns" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </AppField>
                </fieldset>

                <!-- The first lines as they will be read. -->
                <div v-if="sample.length" class="overflow-x-auto rounded-lg border border-line">
                    <p class="border-b border-line px-3 py-2 text-[12px] font-medium text-muted">{{ t('accounting.bank.sample') }}</p>
                    <table class="w-full text-[12.5px]">
                        <tbody class="divide-y divide-line">
                            <tr v-for="(row, index) in sample" :key="index">
                                <td class="whitespace-nowrap px-3 py-1.5" dir="ltr">{{ row.date }}</td>
                                <td class="px-3 py-1.5">{{ row.description }}</td>
                                <td class="tabular whitespace-nowrap px-3 py-1.5 text-end" dir="ltr">{{ row.amount }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('accounting.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="accounting-bank-import" :icon="FileUp" :loading="sending" :disabled="!ready">{{ t('accounting.bank.import') }}</AppButton>
        </template>
    </AppDialog>
</template>
