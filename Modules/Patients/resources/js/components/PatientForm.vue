<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ChevronDown } from 'lucide-vue-next';
import { AppField, AppSegmented, DateInput } from '@/ui';
import { t } from '@/lib/i18n';

/**
 * The patient's fields: name, sex, age or birth date and mobile first (enough
 * to register); guardian, blood group, ID, allergies and address folded
 * under "More" until needed. Emits the body to send (only changed fields
 * when editing).
 */
const props = defineProps({
    id: { type: String, required: true },
    patient: { type: Object, default: null },
    options: { type: Object, default: () => ({}) },
    requireIdNumber: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit']);

const blank = () => ({
    name: '', name_local: '', sex: '', by: 'age', age_years: '', date_of_birth: '', mobile: '',
    guardian_name: '', guardian_relation: '', blood_group: '', id_type: 'nid', id_number: '', allergies: '', address: '',
});
const form = reactive(blank());
const more = ref(false);
watch(() => props.patient, (patient) => {
    Object.assign(form, blank());
    if (!patient) return;
    Object.assign(form, {
        ...Object.fromEntries(Object.keys(blank()).filter((key) => key in patient).map((key) => [key, patient[key] ?? ''])),
        by: patient.dob_estimated ? 'age' : 'date',
        age_years: patient.age ?? '',
        id_type: patient.id_type === 'none' ? 'nid' : patient.id_type,
        id_number: '',
    });
    more.value = Boolean(patient.guardian_name || patient.blood_group || patient.allergies || patient.address);
}, { immediate: true });

const sexes = computed(() => (props.options.sexes ?? ['male', 'female', 'other']).map((value) => ({ value, label: t(`patients.sex.${value}`) })));
const ways = computed(() => [{ value: 'age', label: t('patients.form.by_age') }, { value: 'date', label: t('patients.form.by_date') }]);
const ready = computed(() => form.name.trim().length >= 2 && form.sex && (form.by === 'age' ? form.age_years !== '' : form.date_of_birth));

function body() {
    const text = (value) => (String(value ?? '').trim() === '' ? null : String(value).trim());
    const out = {
        name: form.name.trim(), name_local: text(form.name_local), sex: form.sex, mobile: text(form.mobile),
        guardian_name: text(form.guardian_name), guardian_relation: text(form.guardian_relation), blood_group: form.blood_group || null,
        allergies: text(form.allergies), address: text(form.address),
        ...(form.by === 'age' ? { age_years: Number(form.age_years) } : { date_of_birth: form.date_of_birth }),
    };
    // The ID number is sent only when typed (editing shows it masked).
    if (text(form.id_number)) Object.assign(out, { id_type: form.id_type, id_number: text(form.id_number) });
    if (!props.patient) return out;
    const before = props.patient;
    return Object.fromEntries(Object.entries(out).filter(([key, value]) => {
        if (key === 'age_years') return !before.dob_estimated || value !== before.age;
        if (key === 'date_of_birth') return before.dob_estimated || value !== before.date_of_birth;
        return !(key in before) || (before[key] ?? null) !== value;
    }));
}

defineExpose({ ready });
</script>

<template>
    <form :id="id" class="grid gap-3" novalidate @submit.prevent="ready && emit('submit', body())">
        <AppField v-slot="{ id: field }" :label="t('patients.form.name')" :error="errors.name?.[0]">
            <input :id="field" v-model="form.name" class="field-input" maxlength="150" autocomplete="off" required />
        </AppField>
        <AppField v-slot="{ id: field }" :label="t('patients.form.sex')" :error="errors.sex?.[0]">
            <AppSegmented :id="field" v-model="form.sex" :options="sexes" :label="t('patients.form.sex')" />
        </AppField>
        <div class="grid gap-2">
            <AppSegmented v-model="form.by" :options="ways" :label="t('patients.form.age_or_date')" />
            <AppField v-if="form.by === 'age'" v-slot="{ id: field }" :label="t('patients.form.age')" :hint="t('patients.form.age_hint')" :error="errors.age_years?.[0]">
                <input :id="field" v-model="form.age_years" type="number" inputmode="numeric" min="0" max="130" class="field-input tabular max-w-32" />
            </AppField>
            <AppField v-else v-slot="{ id: field }" :label="t('patients.form.date_of_birth')" :error="errors.date_of_birth?.[0]">
                <DateInput :id="field" v-model="form.date_of_birth" not-future />
            </AppField>
        </div>
        <AppField v-slot="{ id: field }" :label="t('patients.form.mobile')" :hint="t('patients.form.mobile_hint')" :error="errors.mobile?.[0]">
            <input :id="field" v-model="form.mobile" type="tel" inputmode="tel" dir="ltr" class="field-input" maxlength="20" autocomplete="off" />
        </AppField>
        <div v-if="requireIdNumber && !patient" class="grid gap-3 sm:grid-cols-[10rem_1fr]">
            <AppField v-slot="{ id: field }" :label="t('patients.form.id_type')">
                <select :id="field" v-model="form.id_type" class="field-input">
                    <option v-for="type in (options.id_types ?? []).filter((value) => value !== 'none')" :key="type" :value="type">{{ t(`patients.id_type.${type}`) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id: field }" :label="t('patients.form.id_number')" :error="errors.id_number?.[0]">
                <input :id="field" v-model="form.id_number" dir="ltr" class="field-input" maxlength="40" autocomplete="off" />
            </AppField>
        </div>

        <button type="button" class="flex items-center gap-1.5 justify-self-start text-[13px] font-medium text-accent" :aria-expanded="more" @click="more = !more">
            <ChevronDown class="size-4 transition-transform" :class="more && 'rotate-180'" aria-hidden="true" />{{ t('patients.form.more') }}
        </button>
        <div v-if="more" class="grid gap-3">
            <AppField v-slot="{ id: field }" :label="t('patients.form.name_local')">
                <input :id="field" v-model="form.name_local" class="field-input" maxlength="150" />
            </AppField>
            <div class="grid gap-3 sm:grid-cols-2">
                <AppField v-slot="{ id: field }" :label="t('patients.form.guardian_name')" :error="errors.guardian_name?.[0]">
                    <input :id="field" v-model="form.guardian_name" class="field-input" maxlength="150" />
                </AppField>
                <AppField v-slot="{ id: field }" :label="t('patients.form.guardian_relation')" :hint="t('patients.form.guardian_relation_hint')">
                    <input :id="field" v-model="form.guardian_relation" class="field-input" maxlength="30" />
                </AppField>
            </div>
            <div class="grid gap-3 sm:grid-cols-[10rem_10rem_1fr]">
                <AppField v-slot="{ id: field }" :label="t('patients.form.blood_group')">
                    <select :id="field" v-model="form.blood_group" class="field-input" dir="ltr">
                        <option value="">—</option>
                        <option v-for="group in options.blood_groups ?? []" :key="group" :value="group">{{ group }}</option>
                    </select>
                </AppField>
                <template v-if="!requireIdNumber || patient">
                    <AppField v-slot="{ id: field }" :label="t('patients.form.id_type')">
                        <select :id="field" v-model="form.id_type" class="field-input">
                            <option v-for="type in (options.id_types ?? []).filter((value) => value !== 'none')" :key="type" :value="type">{{ t(`patients.id_type.${type}`) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id: field }" :label="t('patients.form.id_number')" :hint="patient?.id_number ? t('patients.form.id_number_kept', { number: patient.id_number }) : ''" :error="errors.id_number?.[0]">
                        <input :id="field" v-model="form.id_number" dir="ltr" class="field-input" maxlength="40" autocomplete="off" />
                    </AppField>
                </template>
            </div>
            <AppField v-slot="{ id: field }" :label="t('patients.form.allergies')" :hint="t('patients.form.allergies_hint')" :error="errors.allergies?.[0]">
                <textarea :id="field" v-model="form.allergies" rows="2" class="field-input" maxlength="1000" />
            </AppField>
            <AppField v-slot="{ id: field }" :label="t('patients.form.address')" :error="errors.address?.[0]">
                <input :id="field" v-model="form.address" class="field-input" maxlength="300" />
            </AppField>
        </div>
    </form>
</template>
