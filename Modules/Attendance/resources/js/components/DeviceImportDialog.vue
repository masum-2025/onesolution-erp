<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { HardDriveUpload } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { guessDeviceColumns } from '../lib';

/**
 * Bring in an attendance machine's file (CSV): which column is the employee
 * code and which the time (one column, or a date and a time), how times
 * look; the company's last choice is offered. Afterwards: how many came in,
 * were in already, and which codes are not people of this unit.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    attendance: { type: Object, required: true },
});
const emit = defineEmits(['close', 'imported']);

// How machines write times, shown as the last minute of a year.
const FORMATS = {
    'Y-m-d H:i:s': '2026-12-31 17:30:00',
    'Y-m-d H:i': '2026-12-31 17:30',
    'd/m/Y H:i:s': '31/12/2026 17:30:00',
    'd/m/Y H:i': '31/12/2026 17:30',
    'm/d/Y H:i:s': '12/31/2026 17:30:00',
    'm/d/Y H:i': '12/31/2026 17:30',
    'd-m-Y H:i:s': '31-12-2026 17:30:00',
    'd.m.Y H:i': '31.12.2026 17:30',
};

const file = ref(null);
const headings = ref([]);
const sample = ref([]);
const mode = ref('one');
const columns = reactive({ code: '', datetime: '', date: '', time: '' });
const format = ref('Y-m-d H:i:s');
const sending = ref(false);
const errors = ref({});
const result = ref(null);
let saved = null;

watch(() => props.open, async (open) => {
    if (!open) return;
    Object.assign(columns, { code: '', datetime: '', date: '', time: '' });
    file.value = null;
    headings.value = [];
    result.value = null;
    errors.value = {};
    saved = (await props.attendance.deviceFormat().catch(() => ({ data: null }))).data;
    if (saved?.datetime_format) format.value = saved.datetime_format;
});

async function choose(event) {
    file.value = event.target.files?.[0] ?? null;
    result.value = null;
    errors.value = {};
    if (!file.value) return;
    const lines = (await file.value.slice(0, 32 * 1024).text()).replace(/^﻿/, '').split(/\r?\n/).filter((line) => line.trim());
    const delimiter = [',', ';', '\t'].sort((a, b) => (lines[0] ?? '').split(b).length - (lines[0] ?? '').split(a).length)[0];
    headings.value = (lines[0] ?? '').split(delimiter).map((name) => name.replace(/^"|"$/g, '').trim()).filter(Boolean);
    sample.value = lines.slice(1, 3).map((line) => line.split(delimiter).map((cell) => cell.replace(/^"|"$/g, '').trim()));
    const known = new Set(headings.value);
    const pick = saved?.columns?.code && known.has(saved.columns.code) ? saved.columns : guessDeviceColumns(headings.value);
    Object.assign(columns, { code: '', datetime: '', date: '', time: '', ...pick });
    mode.value = columns.datetime ? 'one' : 'two';
}

const ready = computed(() => file.value && columns.code && (mode.value === 'one' ? columns.datetime : columns.date && columns.time));

async function send() {
    const body = new FormData();
    body.append('file', file.value);
    body.append('datetime_format', format.value);
    for (const key of mode.value === 'one' ? ['code', 'datetime'] : ['code', 'date', 'time']) body.append(`columns[${key}]`, columns[key]);
    sending.value = true;
    errors.value = {};
    try {
        result.value = (await props.attendance.importDevice(body)).data;
        toast.success(t('attendance.device.done', { added: formatNumber(result.value.added) }));
        emit('imported');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        sending.value = false;
    }
}

const problems = computed(() => [errors.value.file ?? [], errors.value['columns.code'] ?? [], errors.value['columns.datetime'] ?? []].flat());
const at = (name) => headings.value.indexOf(name);
</script>

<template>
    <AppDialog :open="open" :title="t('attendance.device.title')" :description="t('attendance.device.text')" size="lg" :icon="HardDriveUpload" @close="emit('close')">
        <form id="attendance-device" class="grid gap-4" novalidate @submit.prevent="send">
            <AppField v-slot="{ id }" :label="t('attendance.device.file')">
                <input :id="id" type="file" accept=".csv,text/csv,text/plain" class="field-input py-2" @change="choose" />
            </AppField>
            <ul v-if="problems.length" class="grid gap-1 rounded-lg bg-bad-soft px-3 py-2 text-[12.5px] text-bad" role="alert">
                <li v-for="(message, index) in problems" :key="index">{{ message }}</li>
            </ul>

            <template v-if="headings.length">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('attendance.device.code')">
                        <select :id="id" v-model="columns.code" class="field-input">
                            <option value="">{{ t('attendance.device.choose') }}</option>
                            <option v-for="name in headings" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('attendance.device.format')">
                        <select :id="id" v-model="format" class="field-input" dir="ltr">
                            <option v-for="(example, key) in FORMATS" :key="key" :value="key">{{ example }}</option>
                        </select>
                    </AppField>
                </div>
                <fieldset class="grid gap-3">
                    <legend class="mb-1 text-[13px] font-medium">{{ t('attendance.device.time_in') }}</legend>
                    <div class="flex flex-wrap gap-4 text-[13px]">
                        <label class="flex items-center gap-2"><input v-model="mode" type="radio" value="one" /> {{ t('attendance.device.one_column') }}</label>
                        <label class="flex items-center gap-2"><input v-model="mode" type="radio" value="two" /> {{ t('attendance.device.two_columns') }}</label>
                    </div>
                    <AppField v-if="mode === 'one'" v-slot="{ id }" :label="t('attendance.device.datetime')">
                        <select :id="id" v-model="columns.datetime" class="field-input">
                            <option value="">{{ t('attendance.device.choose') }}</option>
                            <option v-for="name in headings" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </AppField>
                    <div v-else class="grid gap-4 sm:grid-cols-2">
                        <AppField v-slot="{ id }" :label="t('attendance.device.date')">
                            <select :id="id" v-model="columns.date" class="field-input">
                                <option value="">{{ t('attendance.device.choose') }}</option>
                                <option v-for="name in headings" :key="name" :value="name">{{ name }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('attendance.device.time')">
                            <select :id="id" v-model="columns.time" class="field-input">
                                <option value="">{{ t('attendance.device.choose') }}</option>
                                <option v-for="name in headings" :key="name" :value="name">{{ name }}</option>
                            </select>
                        </AppField>
                    </div>
                </fieldset>
                <div v-if="sample.length && columns.code" class="rounded-lg border border-line text-[12.5px]">
                    <p class="border-b border-line px-3 py-2 font-medium text-muted">{{ t('attendance.device.sample') }}</p>
                    <p v-for="(row, index) in sample" :key="index" class="flex justify-between gap-3 px-3 py-1.5" dir="ltr">
                        <span class="font-mono">{{ row[at(columns.code)] }}</span>
                        <span class="tabular">{{ mode === 'one' ? row[at(columns.datetime)] : `${row[at(columns.date)] ?? ''} ${row[at(columns.time)] ?? ''}` }}</span>
                    </p>
                </div>
            </template>

            <div v-if="result" class="rounded-lg bg-subtle px-4 py-3 text-[13px]" role="status">
                <p class="font-medium">{{ t('attendance.device.summary', { added: formatNumber(result.added), already: formatNumber(result.already) }) }}</p>
                <p v-if="result.not_employed" class="text-muted">{{ t('attendance.device.not_employed', { count: formatNumber(result.not_employed) }) }}</p>
                <p v-if="result.unknown_codes.length" class="text-muted">{{ t('attendance.device.unknown', { codes: result.unknown_codes.join(', ') }) }}</p>
            </div>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t(result ? 'attendance.device.close' : 'attendance.common.cancel') }}</AppButton>
            <AppButton v-if="!result" variant="primary" type="submit" form="attendance-device" :icon="HardDriveUpload" :loading="sending" :disabled="!ready">{{ t('attendance.device.import') }}</AppButton>
        </template>
    </AppDialog>
</template>
