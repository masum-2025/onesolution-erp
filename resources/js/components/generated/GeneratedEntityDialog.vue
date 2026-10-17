<script setup>
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { api } from '@/lib/http';
import { newOpId } from '@/lib/billing';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { fieldToInput, inputToApi } from '@/lib/generatedFields';

/**
 * Making or changing a record of a generated module's entity: one input per
 * field by its type, checked as typed (the server checks again); the
 * server's errors shown under their fields. Saves once per click (op id).
 */
const props = defineProps({
    open: Boolean,
    schema: { type: Object, required: true },
    entity: { type: Object, required: true },
    record: { type: Object, default: null },
    base: { type: String, required: true },
    setup: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'saved']);
const ns = computed(() => props.schema.key);
const label = (field) => t(`${ns.value}.fields.${props.entity.name}.${field.name}`);
const inputs = ref({});
const errors = ref({});
const busy = ref(false);
let opId = '';

watch(() => props.open, (open) => {
    if (!open) return;
    inputs.value = Object.fromEntries(props.entity.fields.map((field) => [field.name, fieldToInput(field, props.record?.[field.column], props.setup.currency)]));
    errors.value = {};
    opId = newOpId();
});

const checked = computed(() => Object.fromEntries(props.entity.fields.map((field) => [field.name, inputToApi(field, inputs.value[field.name], props.setup.currency)])));
const problems = computed(() => Object.entries(checked.value).filter(([, result]) => result.error));
const ready = computed(() => problems.value.length === 0);

async function save() {
    busy.value = true;
    errors.value = {};
    const body = Object.fromEntries(props.entity.fields.map((field) => [field.column, checked.value[field.name].value]));
    try {
        const { data } = props.record
            ? await api(`${props.base}/${props.record.id}`, { method: 'PATCH', body: { ...body, base_version: props.record.version } })
            : await api(props.base, { method: 'POST', body: { ...body, op_id: opId } });
        toast.success(t('generated.saved'));
        emit('saved', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('generated.conflict'));
            emit('saved', null);
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
const errorOf = (field) => errors.value[field.column]?.[0] ?? (checked.value[field.name]?.error && inputs.value[field.name] !== '' ? t(`generated.errors.${checked.value[field.name].error}`) : null);
</script>

<template>
    <AppDialog :open="open" size="lg" :title="record ? t('generated.edit', { name: t(`${ns}.entities.${entity.name}.label`) }) : t('generated.new', { name: t(`${ns}.entities.${entity.name}.label`) })" @close="emit('close')">
        <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="ready && save()">
            <template v-for="field in entity.fields" :key="field.name">
                <div v-if="field.type === 'translatable'" class="sm:col-span-2">
                    <TranslatedFields v-model="inputs[field.name]" :label="label(field)" :errors="errors" :error-prefix="field.column" :required="field.required" :maxlength="field.max ?? 150" />
                </div>
                <label v-else-if="field.type === 'boolean'" class="flex items-center gap-2 self-end py-2 text-[13.5px]">
                    <input v-model="inputs[field.name]" type="checkbox" class="size-4 accent-brand" />{{ label(field) }}
                </label>
                <AppField v-else v-slot="{ id }" :class="field.type === 'long_text' ? 'sm:col-span-2' : ''" :label="label(field)" :optional="!field.required" :error="errorOf(field)">
                    <textarea v-if="field.type === 'long_text'" :id="id" v-model="inputs[field.name]" class="field-input min-h-20" maxlength="5000" />
                    <select v-else-if="field.type === 'enum'" :id="id" v-model="inputs[field.name]" class="field-input">
                        <option v-if="!field.required" value="">—</option>
                        <option v-for="value in field.options" :key="value" :value="value">{{ t(`${ns}.options.${entity.name}.${field.name}.${value}`) }}</option>
                    </select>
                    <select v-else-if="field.type === 'belongs_to'" :id="id" v-model="inputs[field.name]" class="field-input">
                        <option v-if="!field.required" value="">—</option>
                        <option v-for="(title, value) in setup.options?.[field.entity] ?? {}" :key="value" :value="value">{{ title }}</option>
                    </select>
                    <select v-else-if="field.type === 'employee'" :id="id" v-model="inputs[field.name]" class="field-input">
                        <option v-if="!field.required" value="">—</option>
                        <option v-for="(name, value) in setup.employees ?? {}" :key="value" :value="value">{{ name }}</option>
                    </select>
                    <input v-else-if="field.type === 'date'" :id="id" v-model="inputs[field.name]" type="date" class="field-input" />
                    <input v-else-if="['integer', 'quantity', 'money'].includes(field.type)" :id="id" v-model="inputs[field.name]" inputmode="decimal" class="field-input tabular" dir="ltr" />
                    <input v-else :id="id" v-model="inputs[field.name]" class="field-input" :maxlength="field.max ?? 150" />
                </AppField>
            </template>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('generated.cancel') }}</AppButton>
            <AppButton variant="primary" :loading="busy" :disabled="!ready" @click="save">{{ t('generated.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
