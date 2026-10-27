<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Save } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import AppField from '@/components/AppField.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * A review cycle: its name per language, the unit whose people take part,
 * the period reviewed and the last day of each step. An open cycle's last
 * days may still move; the rest is fixed once it opens.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    cycle: { type: Object, default: null },
    // { units, self_review }
    options: { type: Object, default: () => ({}) },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);

const year = new Date().getFullYear();
const blank = () => ({
    name: textsFor({}), unit_id: '', period_from: `${year}-01-01`, period_to: `${year}-12-31`, goals_due: '', self_due: '', manager_due: '',
});
const form = reactive(blank());
const errors = ref({});
const saving = ref(false);
const fixed = computed(() => props.cycle && props.cycle.status !== 'draft');
const withSelf = computed(() => props.cycle?.self_review ?? props.options.self_review ?? true);

watch(() => props.open, (open) => {
    if (!open) return;
    const item = props.cycle;
    Object.assign(form, blank(), item ? {
        name: textsFor(item.names), unit_id: item.unit_id, period_from: item.period_from, period_to: item.period_to,
        goals_due: item.goals_due, self_due: item.self_due ?? '', manager_due: item.manager_due,
    } : { unit_id: props.options.units?.[0]?.id ?? '' });
    errors.value = {};
});

async function save() {
    const dates = { goals_due: form.goals_due, self_due: withSelf.value ? form.self_due || null : null, manager_due: form.manager_due };
    const body = fixed.value ? dates : {
        name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())), unit_id: form.unit_id,
        period_from: form.period_from, period_to: form.period_to, ...dates,
    };
    saving.value = true;
    errors.value = {};
    try {
        const response = props.cycle
            ? await props.calls.updateCycle(props.cycle.id, { ...body, base_version: props.cycle.version })
            : await props.calls.createCycle(body);
        toast.success(t('appraisals.editor.saved'));
        emit('saved', response.data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDrawer :open="open" :title="cycle ? t('appraisals.editor.edit_title') : t('appraisals.editor.new_title')" width="sm:max-w-[600px]" @close="emit('close')">
        <form id="appraisals-cycle" class="space-y-5" novalidate @submit.prevent="save">
            <p v-if="fixed" class="rounded-lg bg-subtle px-3 py-2 text-[13px]">{{ t('appraisals.editor.fixed') }}</p>
            <template v-else>
                <TranslatedFields v-model="form.name" :label="t('appraisals.editor.name')" :errors="errors" error-prefix="name" required :maxlength="120" />
                <AppField v-slot="{ id }" :label="t('appraisals.editor.unit')" :hint="t('appraisals.editor.unit_hint')" :error="fieldError('unit_id')">
                    <select :id="id" v-model="form.unit_id" class="field-input">
                        <option v-for="unit in options.units ?? []" :key="unit.id" :value="unit.id">{{ '— '.repeat(Math.max(0, unit.depth - (options.units?.[0]?.depth ?? 0))) }}{{ unit.name }}</option>
                    </select>
                </AppField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('appraisals.editor.period_from')" :error="fieldError('period_from')">
                        <input :id="id" v-model="form.period_from" type="date" class="field-input" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('appraisals.editor.period_to')" :error="fieldError('period_to')">
                        <input :id="id" v-model="form.period_to" type="date" class="field-input" :min="form.period_from" />
                    </AppField>
                </div>
            </template>
            <fieldset class="grid gap-4 sm:grid-cols-3">
                <legend class="mb-2 text-[13px] font-semibold text-fg-2">{{ t('appraisals.editor.last_days') }}</legend>
                <AppField v-slot="{ id }" :label="t('appraisals.stages.goals')" :error="fieldError('goals_due')">
                    <input :id="id" v-model="form.goals_due" type="date" class="field-input" />
                </AppField>
                <AppField v-if="withSelf" v-slot="{ id }" :label="t('appraisals.stages.self')" :error="fieldError('self_due')">
                    <input :id="id" v-model="form.self_due" type="date" class="field-input" :min="form.goals_due" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('appraisals.stages.manager')" :error="fieldError('manager_due')">
                    <input :id="id" v-model="form.manager_due" type="date" class="field-input" :min="form.self_due || form.goals_due" />
                </AppField>
            </fieldset>
            <p class="text-[12.5px] text-muted">{{ t('appraisals.editor.hint') }}</p>
        </form>
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <AppButton variant="ghost" @click="emit('close')">{{ t('appraisals.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="appraisals-cycle" :icon="Save" :loading="saving" :disabled="(!fixed && !form.name.en?.trim()) || !form.goals_due || !form.manager_due">{{ t('appraisals.common.save') }}</AppButton>
            </div>
        </template>
    </AppDrawer>
</template>
