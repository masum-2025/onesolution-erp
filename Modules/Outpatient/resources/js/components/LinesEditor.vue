<script setup>
import { ref } from 'vue';
import { FlaskConical, Pill, Plus, X } from 'lucide-vue-next';
import { AppButton, AppField } from '@/ui';
import { t } from '@/lib/i18n';

/**
 * Medicines and advised tests of a consultation or a template. With the
 * pharmacy on, typing a name lists its medicines (picking one keeps its id);
 * with the lab on, tests are picked from its list. Anything can also be
 * written by hand. Errors come per line from the server ("medicines.0.…").
 */
const props = defineProps({
    medicines: { type: Array, required: true },
    tests: { type: Array, required: true },
    options: { type: Object, required: true }, // { pharmacy, lab, tests, timings }
    search: { type: Function, default: null }, // (q) => Promise<list of medicines>
    errors: { type: Object, default: () => ({}) },
    disabled: Boolean,
});

const found = ref({});
const timers = {};
function typed(index, value) {
    const row = props.medicines[index];
    row.medicine_text = value;
    row.medicine_id = null;
    clearTimeout(timers[index]);
    if (!props.options.pharmacy || !props.search || value.trim().length < 2) {
        found.value = { ...found.value, [index]: [] };
        return;
    }
    timers[index] = setTimeout(async () => {
        const list = await props.search(value.trim()).catch(() => []);
        found.value = { ...found.value, [index]: list };
    }, 300);
}
function pick(index, medicine) {
    Object.assign(props.medicines[index], { medicine_id: medicine.id, medicine_text: [medicine.name, medicine.strength].filter(Boolean).join(' ') });
    found.value = { ...found.value, [index]: [] };
}
const addMedicine = () => props.medicines.push({ medicine_id: null, medicine_text: '', dose: '', duration: '', timing: '', note: '' });
const addTest = () => props.tests.push({ test_code: null, test_text: '' });
function pickTest(row, code) {
    const test = props.options.tests.find((item) => item.code === code);
    Object.assign(row, { test_code: test?.code ?? null, test_text: test?.name ?? row.test_text });
}
const error = (path) => props.errors[path]?.[0] ?? null;
</script>

<template>
    <section class="grid gap-2">
        <div class="flex items-center justify-between">
            <h3 class="flex items-center gap-2 text-[13.5px] font-semibold"><Pill class="size-4 text-muted" aria-hidden="true" />{{ t('outpatient.consult.medicines') }}</h3>
            <AppButton v-if="!disabled" size="sm" variant="ghost" :icon="Plus" @click="addMedicine">{{ t('outpatient.consult.add_medicine') }}</AppButton>
        </div>
        <p v-if="!medicines.length" class="text-[12.5px] text-muted">{{ t('outpatient.consult.no_medicines') }}</p>
        <ol class="grid gap-3">
            <li v-for="(row, index) in medicines" :key="index" class="grid gap-2 rounded-lg p-3 ring-1 ring-line sm:grid-cols-[2fr_1fr_1fr_1.2fr_auto] sm:items-end">
                <div class="relative">
                    <AppField v-slot="{ id }" :label="t('outpatient.consult.medicine', { n: index + 1 })" :error="error(`medicines.${index}.medicine_text`) ?? error(`medicines.${index}.medicine_id`)">
                        <input :id="id" :value="row.medicine_text" class="field-input" maxlength="150" autocomplete="off" :disabled="disabled"
                               :placeholder="options.pharmacy ? t('outpatient.consult.medicine_search') : t('outpatient.consult.medicine_write')" @input="typed(index, $event.target.value)" />
                    </AppField>
                    <ul v-if="found[index]?.length" class="absolute inset-x-0 top-full z-20 mt-1 max-h-56 overflow-auto rounded-lg bg-surface shadow-lg ring-1 ring-line">
                        <li v-for="medicine in found[index]" :key="medicine.id">
                            <button type="button" class="w-full px-3 py-2 text-start text-[13px] hover:bg-subtle" @click="pick(index, medicine)">
                                <span class="font-medium">{{ medicine.name }}</span> {{ medicine.strength }} <span class="text-muted">· {{ medicine.generic }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
                <AppField v-slot="{ id }" :label="t('outpatient.consult.dose')"><input :id="id" v-model="row.dose" class="field-input" maxlength="40" placeholder="1+0+1" :disabled="disabled" /></AppField>
                <AppField v-slot="{ id }" :label="t('outpatient.consult.duration')"><input :id="id" v-model="row.duration" class="field-input" maxlength="40" :placeholder="t('outpatient.consult.duration_hint')" :disabled="disabled" /></AppField>
                <AppField v-slot="{ id }" :label="t('outpatient.consult.timing')" :error="error(`medicines.${index}.timing`)">
                    <select :id="id" v-model="row.timing" class="field-input" :disabled="disabled">
                        <option value="">—</option>
                        <option v-for="timing in options.timings" :key="timing" :value="timing">{{ t(`outpatient.timing.${timing}`) }}</option>
                    </select>
                </AppField>
                <AppButton v-if="!disabled" size="sm" variant="ghost" :icon="X" :aria-label="t('outpatient.consult.remove')" @click="medicines.splice(index, 1)" />
                <AppField v-slot="{ id }" :label="t('outpatient.consult.note')" class="sm:col-span-4"><input :id="id" v-model="row.note" class="field-input" maxlength="200" :disabled="disabled" /></AppField>
            </li>
        </ol>

        <div class="mt-2 flex items-center justify-between">
            <h3 class="flex items-center gap-2 text-[13.5px] font-semibold"><FlaskConical class="size-4 text-muted" aria-hidden="true" />{{ t('outpatient.consult.tests') }}</h3>
            <AppButton v-if="!disabled" size="sm" variant="ghost" :icon="Plus" @click="addTest">{{ t('outpatient.consult.add_test') }}</AppButton>
        </div>
        <p v-if="!tests.length" class="text-[12.5px] text-muted">{{ t('outpatient.consult.no_tests') }}</p>
        <ol class="grid gap-2">
            <li v-for="(row, index) in tests" :key="index" class="grid gap-2 sm:grid-cols-[1fr_1.5fr_auto] sm:items-end">
                <AppField v-if="options.lab" v-slot="{ id }" :label="t('outpatient.consult.lab_test')" :error="error(`tests.${index}.test_code`)">
                    <select :id="id" :value="row.test_code ?? ''" class="field-input" :disabled="disabled" @change="pickTest(row, $event.target.value)">
                        <option value="">{{ t('outpatient.consult.not_from_lab') }}</option>
                        <option v-for="test in options.tests" :key="test.code" :value="test.code">{{ test.code }} · {{ test.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('outpatient.consult.test', { n: index + 1 })" :error="error(`tests.${index}.test_text`)" :class="!options.lab && 'sm:col-span-2'">
                    <input :id="id" v-model="row.test_text" class="field-input" maxlength="150" :disabled="disabled" />
                </AppField>
                <AppButton v-if="!disabled" size="sm" variant="ghost" :icon="X" :aria-label="t('outpatient.consult.remove')" @click="tests.splice(index, 1)" />
            </li>
        </ol>
    </section>
</template>
