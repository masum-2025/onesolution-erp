<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, Save, SlidersHorizontal, Trash2 } from 'lucide-vue-next';
import { AppButton, AppField, EmptyState, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { labApi } from '../api';

/**
 * A test's values (Hb, WBC, a finding) and their normal ranges by sex and age.
 * Ages are typed in years or days (newborns); saved as days. Removing a value
 * only stops it on new results; old reports keep it.
 */
const lab = labApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useResource(() => lab.setup());
const info = computed(() => setup.data.value?.data ?? {});
const tests = computed(() => info.value.options?.tests ?? []);
const testId = ref(typeof route.query.test === 'string' ? route.query.test : '');
const rows = ref([]);
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const DAYS = 365;

const toRange = (range) => {
    const years = (range.age_from_days % DAYS === 0) && (range.age_to_days === null || (range.age_to_days + 1) % DAYS === 0);
    return {
        sex: range.sex, unit: years ? 'years' : 'days',
        from: years ? range.age_from_days / DAYS : range.age_from_days,
        to: range.age_to_days === null ? '' : (years ? (range.age_to_days + 1) / DAYS : range.age_to_days),
        low: range.low ?? '', high: range.high ?? '', critical_low: range.critical_low ?? '', critical_high: range.critical_high ?? '', normal_text: range.normal_text ?? '',
    };
};
const fromRange = (range) => {
    const factor = range.unit === 'years' ? DAYS : 1;
    const text = (value) => (String(value ?? '').trim() === '' ? null : String(value).trim());
    return {
        sex: range.sex, age_from_days: Number(range.from || 0) * factor,
        age_to_days: text(range.to) === null ? null : Number(range.to) * factor - (range.unit === 'years' ? 1 : 0),
        low: text(range.low), high: text(range.high), critical_low: text(range.critical_low), critical_high: text(range.critical_high), normal_text: text(range.normal_text),
    };
};
const blankRange = () => ({ sex: 'any', unit: 'years', from: 0, to: '', low: '', high: '', critical_low: '', critical_high: '', normal_text: '' });
const blankRow = () => ({ key: crypto.randomUUID(), id: null, name_en: '', name_bn: '', unit: '', result_type: 'number', decimals: 1, choices: '', ranges: [blankRange()] });

async function load() {
    errors.value = {};
    if (!testId.value) {
        rows.value = [];
        return;
    }
    loading.value = true;
    try {
        const data = (await lab.parameters(testId.value)).data;
        rows.value = data.parameters.map((parameter) => ({
            key: parameter.id, id: parameter.id, name_en: parameter.name.en ?? '', name_bn: parameter.name.bn ?? '', unit: parameter.unit ?? '',
            result_type: parameter.result_type, decimals: parameter.decimals, choices: (parameter.choices ?? []).join(', '), ranges: parameter.ranges.map(toRange),
        }));
    } catch (error) {
        toast.error(error.message);
    } finally {
        loading.value = false;
    }
}
watch(testId, () => {
    router.replace({ query: testId.value ? { test: testId.value } : {} });
    load();
}, { immediate: true });

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await lab.saveParameters(testId.value, { parameters: rows.value.map((row) => ({
            ...(row.id ? { id: row.id } : {}),
            name: { en: row.name_en.trim(), ...(row.name_bn.trim() ? { bn: row.name_bn.trim() } : {}) },
            unit: row.unit.trim() || null, result_type: row.result_type, decimals: Number(row.decimals) || 0,
            choices: row.result_type === 'choice' ? row.choices.split(',').map((choice) => choice.trim()).filter(Boolean) : null,
            ranges: row.ranges.map(fromRange),
        })) });
        toast.success(t('diagnostics.parameters.saved'));
        load();
    } catch (error) {
        errors.value = error.errors ?? {};
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
const types = ['number', 'text', 'choice'];
const sexes = ['any', 'male', 'female'];
</script>

<template>
    <PageFrame :title="t('diagnostics.parameters.title')" :description="t('diagnostics.parameters.text')" width="xl" guide="diagnostics.guide.parameters">
        <template v-if="testId && info.can?.manage" #actions>
            <AppButton variant="primary" :icon="Save" :loading="saving" :disabled="rows.some((row) => !row.name_en.trim())" @click="save">{{ t('diagnostics.parameters.save') }}</AppButton>
        </template>
        <AppField v-slot="{ id }" :label="t('diagnostics.parameters.test')" class="mb-4 max-w-md">
            <select :id="id" v-model="testId" class="field-input">
                <option value="">{{ t('diagnostics.parameters.pick_test') }}</option>
                <option v-for="test in tests" :key="test.id" :value="test.id">{{ test.code }} · {{ test.name }}</option>
            </select>
        </AppField>
        <EmptyState v-if="!testId" :icon="SlidersHorizontal" :title="t('diagnostics.parameters.none_picked')" :text="tests.length ? '' : t('diagnostics.parameters.no_tests')" compact />
        <p v-else-if="loading" class="text-[13px] text-muted">{{ t('diagnostics.common.loading') }}</p>
        <template v-else>
            <section v-for="(row, index) in rows" :key="row.key" class="card mb-4 p-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_8rem_9rem_6rem_auto]">
                    <AppField v-slot="{ id }" :label="t('diagnostics.parameters.name_en')" :error="errors[`parameters.${index}.name.en`]?.[0]"><input :id="id" v-model="row.name_en" class="field-input" maxlength="100" /></AppField>
                    <AppField v-slot="{ id }" :label="t('diagnostics.parameters.name_bn')"><input :id="id" v-model="row.name_bn" class="field-input" maxlength="100" /></AppField>
                    <AppField v-slot="{ id }" :label="t('diagnostics.parameters.unit')"><input :id="id" v-model="row.unit" class="field-input" dir="ltr" maxlength="30" /></AppField>
                    <AppField v-slot="{ id }" :label="t('diagnostics.parameters.type')">
                        <select :id="id" v-model="row.result_type" class="field-input"><option v-for="type in types" :key="type" :value="type">{{ t(`diagnostics.parameters.types.${type}`) }}</option></select>
                    </AppField>
                    <AppField v-if="row.result_type === 'number'" v-slot="{ id }" :label="t('diagnostics.parameters.decimals')"><input :id="id" v-model.number="row.decimals" type="number" min="0" max="4" class="field-input tabular" /></AppField>
                    <span v-else />
                    <button type="button" class="mt-6 grid size-9 place-items-center rounded-lg text-muted hover:bg-subtle hover:text-bad" :aria-label="t('diagnostics.parameters.remove')" @click="rows.splice(index, 1)"><Trash2 class="size-4" aria-hidden="true" /></button>
                </div>
                <AppField v-if="row.result_type === 'choice'" v-slot="{ id }" :label="t('diagnostics.parameters.choices')" :hint="t('diagnostics.parameters.choices_hint')" class="mt-3"><input :id="id" v-model="row.choices" class="field-input" /></AppField>

                <p class="mt-4 text-[12.5px] font-semibold text-muted">{{ t('diagnostics.parameters.ranges') }}</p>
                <div v-for="(range, at) in row.ranges" :key="at" class="mt-2 grid grid-cols-2 items-end gap-2 sm:grid-cols-[7rem_5rem_5rem_6rem_repeat(4,minmax(0,1fr))_auto]">
                    <AppField v-slot="{ id }" :label="t('diagnostics.parameters.sex')"><select :id="id" v-model="range.sex" class="field-input"><option v-for="sex in sexes" :key="sex" :value="sex">{{ t(`diagnostics.parameters.sexes.${sex}`) }}</option></select></AppField>
                    <AppField v-slot="{ id }" :label="t('diagnostics.parameters.age_from')"><input :id="id" v-model="range.from" type="number" min="0" class="field-input tabular" /></AppField>
                    <AppField v-slot="{ id }" :label="t('diagnostics.parameters.age_to')"><input :id="id" v-model="range.to" type="number" min="0" class="field-input tabular" :placeholder="t('diagnostics.parameters.any_age')" /></AppField>
                    <AppField v-slot="{ id }" :label="t('diagnostics.parameters.age_unit')"><select :id="id" v-model="range.unit" class="field-input"><option value="years">{{ t('diagnostics.parameters.years') }}</option><option value="days">{{ t('diagnostics.parameters.days') }}</option></select></AppField>
                    <template v-if="row.result_type === 'number'">
                        <AppField v-slot="{ id }" :label="t('diagnostics.parameters.low')"><input :id="id" v-model="range.low" class="field-input tabular" dir="ltr" inputmode="decimal" /></AppField>
                        <AppField v-slot="{ id }" :label="t('diagnostics.parameters.high')"><input :id="id" v-model="range.high" class="field-input tabular" dir="ltr" inputmode="decimal" /></AppField>
                        <AppField v-slot="{ id }" :label="t('diagnostics.parameters.critical_low')"><input :id="id" v-model="range.critical_low" class="field-input tabular" dir="ltr" inputmode="decimal" /></AppField>
                        <AppField v-slot="{ id }" :label="t('diagnostics.parameters.critical_high')"><input :id="id" v-model="range.critical_high" class="field-input tabular" dir="ltr" inputmode="decimal" /></AppField>
                    </template>
                    <AppField v-else v-slot="{ id }" :label="t('diagnostics.parameters.normal_text')" class="sm:col-span-4"><input :id="id" v-model="range.normal_text" class="field-input" maxlength="150" /></AppField>
                    <button type="button" class="mb-0.5 grid size-9 place-items-center rounded-lg text-muted hover:bg-subtle hover:text-bad" :aria-label="t('diagnostics.parameters.remove_range')" @click="row.ranges.splice(at, 1)"><Trash2 class="size-4" aria-hidden="true" /></button>
                </div>
                <AppButton variant="ghost" size="sm" :icon="Plus" class="mt-2" @click="row.ranges.push(blankRange())">{{ t('diagnostics.parameters.add_range') }}</AppButton>
            </section>
            <AppButton v-if="info.can?.manage" :icon="Plus" @click="rows.push(blankRow())">{{ t('diagnostics.parameters.add') }}</AppButton>
        </template>
    </PageFrame>
</template>
