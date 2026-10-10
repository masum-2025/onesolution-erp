<script setup>
import { computed } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import OwnFields from '@/components/OwnFields.vue';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { useEducationSetup } from '../setup';
import { emptyGuardian } from '../lib';

/**
 * Guardians given with a new student or an application (up to six): one
 * block each, the first the main contact. v-model is the list of guardian
 * forms (emptyGuardian()); errors come as {"<prefix>.<index>.<field>": [..]}.
 * The national id is asked only of people allowed to see private details.
 */
const props = defineProps({
    modelValue: { type: Array, required: true },
    errors: { type: Object, default: () => ({}) },
    prefix: { type: String, default: 'guardians' },
    // Switches that belong to a student link (not asked on an application).
    links: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue']);

const setup = useEducationSetup();
const sensitive = computed(() => setup.can('view_sensitive'));
const fields = computed(() => setup.fields('guardian'));
const error = (index, name) => props.errors[`${props.prefix}.${index}.${name}`]?.[0] ?? null;

function add() {
    const used = new Set(props.modelValue.map((guardian) => guardian.relation));
    const relation = setup.list('relation').map((item) => item.key).find((key) => !used.has(key)) ?? 'guardian';
    emit('update:modelValue', [...props.modelValue, { ...emptyGuardian(relation), is_primary: props.modelValue.length === 0 }]);
}

function remove(index) {
    const kept = props.modelValue.filter((_, at) => at !== index);
    if (props.modelValue[index].is_primary && kept.length) kept[0] = { ...kept[0], is_primary: true };
    emit('update:modelValue', kept);
}

function makePrimary(index) {
    emit('update:modelValue', props.modelValue.map((guardian, at) => ({ ...guardian, is_primary: at === index })));
}
</script>

<template>
    <div class="space-y-4">
        <fieldset v-for="(guardian, index) in modelValue" :key="index" class="space-y-4 rounded-xl border border-line p-4">
            <legend class="flex items-center gap-2 px-1 text-[13px] font-medium text-fg-2">
                {{ setup.listName('relation', guardian.relation) || t('education.guardian.title') }}
                <span v-if="guardian.is_primary" class="rounded-full bg-brand-soft px-2 py-0.5 text-[11.5px] text-brand-text">{{ t('education.guardian.primary') }}</span>
            </legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('education.guardian.relation')" :error="error(index, 'relation')">
                    <select :id="id" v-model="guardian.relation" class="field-input">
                        <option v-for="item in setup.list('relation')" :key="item.key" :value="item.key">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.guardian.name')" :error="error(index, 'name')">
                    <input :id="id" v-model="guardian.name" class="field-input" maxlength="150" autocomplete="off" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.guardian.phone')" :hint="t('education.guardian.phone_hint')" :error="error(index, 'phone')">
                    <input :id="id" v-model="guardian.phone" type="tel" inputmode="tel" dir="ltr" class="field-input" maxlength="30" autocomplete="off" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.guardian.occupation')" :error="error(index, 'occupation')" optional>
                    <input :id="id" v-model="guardian.occupation" class="field-input" maxlength="100" autocomplete="off" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.guardian.email')" :error="error(index, 'email')" optional>
                    <input :id="id" v-model="guardian.email" type="email" dir="ltr" class="field-input" maxlength="190" autocomplete="off" />
                </AppField>
                <AppField v-if="sensitive" v-slot="{ id }" :label="t('education.guardian.national_id')" :error="error(index, 'national_id')" optional>
                    <input :id="id" v-model="guardian.national_id" dir="ltr" inputmode="numeric" class="field-input tabular" maxlength="40" autocomplete="off" />
                </AppField>
            </div>
            <OwnFields v-model="guardian.extra" :fields="fields" :errors="errors" :prefix="`${prefix}.${index}.extra`" />
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <AppSwitch :model-value="guardian.is_primary" :label="t('education.guardian.primary')" show-label @update:model-value="makePrimary(index)" />
                <template v-if="links">
                    <AppSwitch v-model="guardian.can_pick_up" :label="t('education.guardian.can_pick_up')" show-label />
                    <AppSwitch v-model="guardian.receives_notices" :label="t('education.guardian.receives_notices')" show-label />
                </template>
                <AppButton size="sm" variant="danger-soft" :icon="Trash2" class="ms-auto" @click="remove(index)">{{ t('education.guardian.remove') }}</AppButton>
            </div>
        </fieldset>
        <AppButton v-if="modelValue.length < 6" size="sm" :icon="Plus" @click="add">{{ t('education.new_student.add_guardian') }}</AppButton>
    </div>
</template>
