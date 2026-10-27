<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Save } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { has, i18n, t } from '@/lib/i18n';
import { amountToMinor, minorToText, nameIn } from '../lib';

/**
 * A job opening: its title and description per language, the unit and
 * HRM position, the kind of employment, how many people, a salary range
 * (shown on the careers page or not), public or not, the last day to
 * apply. New ones are drafts; opening them is a step of its own.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    // the opening, or null for a new one
    opening: { type: Object, default: null },
    // { units, positions, employment_types }
    options: { type: Object, default: () => ({}) },
    currency: { type: String, default: 'BDT' },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);

const blank = () => ({
    title: textsFor({}), description: textsFor({}), unit_id: '', position_id: '', employment_type: '', vacancies: 1,
    salary_min: '', salary_max: '', show_salary: false, is_public: true, closes_on: '',
});
const form = reactive(blank());
const errors = ref({});
const saving = ref(false);
const money = computed(() => props.opening?.currency ?? props.currency);

watch(() => props.open, (open) => {
    if (!open) return;
    const item = props.opening;
    Object.assign(form, blank(), item ? {
        title: textsFor(item.titles), description: textsFor(item.descriptions ?? {}), unit_id: item.unit_id, position_id: item.position_id ?? '',
        employment_type: item.employment_type ?? '', vacancies: item.vacancies, salary_min: minorToText(item.salary_min_minor, money.value),
        salary_max: minorToText(item.salary_max_minor, money.value), show_salary: item.show_salary, is_public: item.is_public, closes_on: item.closes_on ?? '',
    } : { unit_id: props.options.units?.[0]?.id ?? '' });
    errors.value = {};
});

const clean = (texts) => Object.fromEntries(Object.entries(texts).filter(([, value]) => value?.trim()));
const salaryBad = computed(() => (form.salary_min !== '' && amountToMinor(form.salary_min, money.value) === null) || (form.salary_max !== '' && amountToMinor(form.salary_max, money.value) === null));

async function save() {
    const body = {
        title: clean(form.title), description: clean(form.description), unit_id: form.unit_id, position_id: form.position_id || null,
        employment_type: form.employment_type || null, vacancies: Number(form.vacancies) || 1,
        salary_min_minor: amountToMinor(form.salary_min, money.value), salary_max_minor: amountToMinor(form.salary_max, money.value),
        show_salary: form.show_salary, is_public: form.is_public, closes_on: form.closes_on || null,
    };
    saving.value = true;
    errors.value = {};
    try {
        const response = props.opening
            ? await props.calls.updateOpening(props.opening.id, { ...body, base_version: props.opening.version })
            : await props.calls.createOpening(body);
        toast.success(t('recruitment.editor.saved'));
        emit('saved', response.data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
        if (error.code === 'version_conflict') emit('close');
    } finally {
        saving.value = false;
    }
}

const typeLabel = (type) => (has(`recruitment.employment.${type}`) ? t(`recruitment.employment.${type}`) : type);
const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDrawer :open="open" :title="opening ? t('recruitment.editor.edit_title') : t('recruitment.editor.new_title')" width="sm:max-w-[640px]" @close="emit('close')">
        <form id="recruitment-opening" class="space-y-5" novalidate @submit.prevent="save">
            <TranslatedFields v-model="form.title" :label="t('recruitment.editor.title')" :errors="errors" error-prefix="title" required :maxlength="150" />
            <TranslatedFields v-model="form.description" :label="t('recruitment.editor.description')" :errors="errors" error-prefix="description" multiline :maxlength="8000" />

            <div class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('recruitment.editor.unit')" :error="fieldError('unit_id')">
                    <select :id="id" v-model="form.unit_id" class="field-input">
                        <option v-for="unit in options.units ?? []" :key="unit.id" :value="unit.id">{{ '— '.repeat(Math.max(0, unit.depth - (options.units?.[0]?.depth ?? 0))) }}{{ unit.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('recruitment.editor.position')" :error="fieldError('position_id')" optional>
                    <select :id="id" v-model="form.position_id" class="field-input">
                        <option value="">{{ t('recruitment.editor.no_position') }}</option>
                        <option v-for="position in options.positions ?? []" :key="position.id" :value="position.id">{{ nameIn(position.title, i18n.locale) }} ({{ position.code }})</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('recruitment.editor.employment_type')" :error="fieldError('employment_type')" optional>
                    <select :id="id" v-model="form.employment_type" class="field-input">
                        <option value="">—</option>
                        <option v-for="type in options.employment_types ?? []" :key="type" :value="type">{{ typeLabel(type) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('recruitment.editor.vacancies')" :error="fieldError('vacancies')">
                    <input :id="id" v-model.number="form.vacancies" type="number" min="1" max="500" class="field-input tabular text-end" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('recruitment.editor.salary_min', { currency: money })" :error="fieldError('salary_min_minor')" optional>
                    <input :id="id" v-model="form.salary_min" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('recruitment.editor.salary_max', { currency: money })" :error="fieldError('salary_max_minor') ?? (salaryBad ? t('recruitment.editor.salary_bad') : null)" optional>
                    <input :id="id" v-model="form.salary_max" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('recruitment.editor.closes_on')" :hint="t('recruitment.editor.closes_hint')" :error="fieldError('closes_on')" optional>
                    <input :id="id" v-model="form.closes_on" type="date" class="field-input" />
                </AppField>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <AppSwitch v-model="form.is_public" :label="t('recruitment.editor.is_public')" show-label />
                <AppSwitch v-model="form.show_salary" :label="t('recruitment.editor.show_salary')" show-label />
            </div>
            <p class="text-[12.5px] text-muted">{{ form.is_public ? t('recruitment.editor.public_hint') : t('recruitment.editor.private_hint') }}</p>
        </form>
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <AppButton variant="ghost" @click="emit('close')">{{ t('recruitment.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="recruitment-opening" :icon="Save" :loading="saving" :disabled="!form.title.en?.trim() || salaryBad">{{ t('recruitment.common.save') }}</AppButton>
            </div>
        </template>
    </AppDrawer>
</template>
