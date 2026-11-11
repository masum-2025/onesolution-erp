<script setup>
import { computed, reactive, ref } from 'vue';
import { FlaskConical, NotebookPen, Plus, Stethoscope, Trash2 } from 'lucide-vue-next';
import { AppButton, AppField, newOpId, toast } from '@/ui';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * One kind of care note for a stay, newest first, with the form to add one
 * while the patient is in: doctors' round notes, nurses' notes (vitals and
 * medicines given) or tests sent to the lab. The server checks who may write.
 */
const props = defineProps({
    kind: { type: String, required: true }, // rounds, nursing, tests
    care: { type: Object, required: true },
    admitted: { type: Boolean, default: false },
    api: { type: Object, required: true },
    admissionId: { type: String, required: true },
});
const emit = defineEmits(['changed']);

const vitalKeys = ['bp_systolic', 'bp_diastolic', 'pulse', 'temperature', 'spo2', 'resp_rate', 'sugar'];
const routes = ['oral', 'iv', 'im', 'sc', 'inhaled', 'topical', 'other'];
const canWrite = computed(() => props.admitted && (props.kind === 'nursing' ? props.care.can.nurse : props.care.can.round) && (props.kind !== 'tests' || props.care.lab));

const roundNote = ref('');
const nursing = reactive({ vitals: Object.fromEntries(vitalKeys.map((key) => [key, ''])), note: '', medicines: [] });
const picked = ref([]);
const errors = ref({});
const busy = ref(false);
let opId = newOpId();

function clean() {
    roundNote.value = '';
    Object.assign(nursing, { vitals: Object.fromEntries(vitalKeys.map((key) => [key, ''])), note: '', medicines: [] });
    picked.value = [];
    errors.value = {};
    opId = newOpId();
}
function body() {
    if (props.kind === 'rounds') return { note: roundNote.value.trim(), op_id: opId };
    if (props.kind === 'tests') return { codes: picked.value, op_id: opId };
    const vitals = Object.fromEntries(vitalKeys.filter((key) => String(nursing.vitals[key]).trim() !== '').map((key) => [key, Number(nursing.vitals[key])]));
    return {
        vitals: Object.keys(vitals).length ? vitals : null,
        note: nursing.note.trim() || null,
        medicines_given: nursing.medicines.filter((row) => row.name.trim()).map((row) => ({ name: row.name.trim(), dose: row.dose.trim() || null, route: row.route || null })),
        op_id: opId,
    };
}
const send = { rounds: (data) => props.api.addRound(props.admissionId, data), nursing: (data) => props.api.addNursingNote(props.admissionId, data), tests: (data) => props.api.orderTests(props.admissionId, data) };
async function save() {
    busy.value = true;
    errors.value = {};
    try {
        await send[props.kind](body());
        toast.success(t(`inpatient.care.saved_${props.kind}`));
        clean();
        emit('changed');
    } catch (error) {
        errors.value = error.errors ?? {};
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
const ready = computed(() => (props.kind === 'rounds' ? roundNote.value.trim().length >= 2 : props.kind === 'tests' ? picked.value.length > 0 : true));
const vitalsOf = (note) => vitalKeys.filter((key) => note.vitals?.[key] !== undefined && note.vitals?.[key] !== null);
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
        <section class="card">
            <ul v-if="kind === 'rounds' && care.rounds.length" class="divide-y divide-line text-[13.5px]">
                <li v-for="item in care.rounds" :key="item.id" class="px-4 py-3">
                    <p class="text-[12px] text-muted">{{ formatDateTime(item.at) }} · {{ item.by }}</p>
                    <p class="mt-1 whitespace-pre-line">{{ item.note }}</p>
                </li>
            </ul>
            <ul v-else-if="kind === 'nursing' && care.nursing_notes.length" class="divide-y divide-line text-[13.5px]">
                <li v-for="item in care.nursing_notes" :key="item.id" class="px-4 py-3">
                    <p class="text-[12px] text-muted">{{ formatDateTime(item.at) }} · {{ item.by }}</p>
                    <p v-if="vitalsOf(item).length" class="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                        <span v-for="key in vitalsOf(item)" :key="key"><span class="text-muted">{{ t(`inpatient.vitals.${key}`) }}</span> <span class="tabular font-medium" dir="ltr">{{ item.vitals[key] }}</span></span>
                    </p>
                    <p v-if="item.note" class="mt-1 whitespace-pre-line">{{ item.note }}</p>
                    <p v-for="(medicine, index) in item.medicines_given" :key="index" class="mt-1">
                        💊 {{ medicine.name }}<template v-if="medicine.dose"> · <span dir="ltr">{{ medicine.dose }}</span></template><template v-if="medicine.route"> · {{ t(`inpatient.route.${medicine.route}`) }}</template>
                    </p>
                </li>
            </ul>
            <ul v-else-if="kind === 'tests' && care.test_orders.length" class="divide-y divide-line text-[13.5px]">
                <li v-for="item in care.test_orders" :key="item.id" class="px-4 py-3">
                    <p class="text-[12px] text-muted">{{ formatDateTime(item.at) }} · {{ item.by }}<template v-if="item.lab_order_number"> · <span dir="ltr">{{ item.lab_order_number }}</span></template></p>
                    <p class="mt-1">{{ item.tests.map((test) => test.name).join(', ') }}</p>
                </li>
            </ul>
            <p v-else class="px-4 py-8 text-center text-[13.5px] text-muted">{{ t(`inpatient.care.empty_${kind}`) }}</p>
        </section>

        <section v-if="canWrite" class="card grid content-start gap-3 p-4">
            <h2 class="flex items-center gap-2 text-[13.5px] font-semibold">
                <component :is="kind === 'rounds' ? Stethoscope : kind === 'tests' ? FlaskConical : NotebookPen" class="size-4" aria-hidden="true" />
                {{ t(`inpatient.care.add_${kind}`) }}
            </h2>
            <AppField v-if="kind === 'rounds'" v-slot="{ id }" :label="t('inpatient.care.round_note')" :error="errors.note?.[0]">
                <textarea :id="id" v-model="roundNote" rows="5" class="field-input" maxlength="4000" />
            </AppField>
            <template v-else-if="kind === 'nursing'">
                <div class="grid grid-cols-2 gap-2">
                    <AppField v-for="key in vitalKeys" :key="key" v-slot="{ id }" :label="t(`inpatient.vitals.${key}`)" :error="errors[`vitals.${key}`]?.[0]">
                        <input :id="id" v-model="nursing.vitals[key]" class="field-input" inputmode="decimal" dir="ltr" />
                    </AppField>
                </div>
                <AppField v-slot="{ id }" :label="t('inpatient.care.nursing_note')" :error="errors.note?.[0]">
                    <textarea :id="id" v-model="nursing.note" rows="3" class="field-input" maxlength="2000" />
                </AppField>
                <div class="grid gap-2">
                    <p class="text-[12.5px] font-medium">{{ t('inpatient.care.medicines_given') }}</p>
                    <div v-for="(row, index) in nursing.medicines" :key="index" class="grid grid-cols-[1fr_5rem_5.5rem_auto] gap-1.5">
                        <input v-model="row.name" class="field-input" maxlength="150" :aria-label="t('inpatient.care.medicine')" :placeholder="t('inpatient.care.medicine')" />
                        <input v-model="row.dose" class="field-input" maxlength="60" dir="ltr" :aria-label="t('inpatient.care.dose')" :placeholder="t('inpatient.care.dose')" />
                        <select v-model="row.route" class="field-input" :aria-label="t('inpatient.care.route')">
                            <option value="">—</option>
                            <option v-for="value in routes" :key="value" :value="value">{{ t(`inpatient.route.${value}`) }}</option>
                        </select>
                        <AppButton size="sm" variant="ghost" :icon="Trash2" :aria-label="t('inpatient.care.remove')" @click="nursing.medicines.splice(index, 1)" />
                    </div>
                    <AppButton size="sm" variant="ghost" :icon="Plus" @click="nursing.medicines.push({ name: '', dose: '', route: '' })">{{ t('inpatient.care.add_medicine') }}</AppButton>
                </div>
            </template>
            <fieldset v-else class="grid max-h-72 gap-1 overflow-y-auto">
                <legend class="mb-1 text-[12.5px] font-medium">{{ t('inpatient.care.pick_tests') }}</legend>
                <label v-for="test in care.catalogue" :key="test.code" class="flex items-center gap-2 text-[13.5px]">
                    <input v-model="picked" type="checkbox" :value="test.code" />
                    <span>{{ test.name }}</span> <span class="font-mono text-[12px] text-muted" dir="ltr">{{ test.code }}</span>
                </label>
            </fieldset>
            <AppButton variant="primary" :loading="busy" :disabled="!ready" @click="save">{{ t(`inpatient.care.save_${kind}`) }}</AppButton>
        </section>
        <p v-else-if="kind === 'tests' && !care.lab" class="card p-4 text-[13px] text-muted">{{ t('inpatient.care.lab_off') }}</p>
    </div>
</template>
