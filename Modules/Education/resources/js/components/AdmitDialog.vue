<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { UserCheck } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import OwnFields from '@/components/OwnFields.vue';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { useEducationSetup } from '../setup';
import { fieldsToApi } from '../lib';
import CapacityMeter from './CapacityMeter.vue';

/**
 * Admit an application: the student is made with their guardians and their
 * place in the class and session applied for, in a section with a seat (or
 * placed later). The birth registration number is asked only of people
 * allowed to see private details; required own student fields are asked now.
 */
const props = defineProps({
    open: Boolean,
    admission: { type: Object, required: true },
    education: { type: Object, required: true },
});
const emit = defineEmits(['close', 'done', 'conflict']);

const setup = useEducationSetup();
const sensitive = computed(() => setup.can('view_sensitive'));
const fields = computed(() => setup.fields('student'));
const batches = computed(() => (setup.data.value?.batches ?? []).filter((batch) => batch.program_id === props.admission.program_id && batch.is_active));

const form = reactive({ section_id: null, batch_id: '', category_id: '', birth_registration_no: '', admitted_on: '' });
const extra = ref({});
const sections = ref([]);
const loading = ref(false);
const errors = ref({});
const saving = ref(false);

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        Object.assign(form, { section_id: null, batch_id: '', category_id: '', birth_registration_no: '', admitted_on: new Date().toISOString().slice(0, 10) });
        extra.value = {};
        errors.value = {};
        sections.value = [];
        if (!props.admission.session_id) return;
        loading.value = true;
        try {
            const { data } = await props.education.overview({ session_id: props.admission.session_id });
            sections.value = data.sections.filter((section) => section.level_id === props.admission.level_id && section.is_active);
            // The first section with a seat, so the usual case is one click.
            form.section_id = sections.value.find((section) => section.taken < section.capacity)?.id ?? null;
        } catch {
            sections.value = [];
        } finally {
            loading.value = false;
        }
    },
);

async function submit() {
    saving.value = true;
    errors.value = {};
    try {
        const body = {
            base_version: props.admission.version,
            section_id: form.section_id,
            batch_id: form.batch_id || null,
            category_id: form.category_id || null,
            admitted_on: form.admitted_on || null,
            extra: fieldsToApi(fields.value, extra.value),
            ...(sensitive.value && form.birth_registration_no.trim() ? { birth_registration_no: form.birth_registration_no.trim() } : {}),
        };
        const { data } = await props.education.admit(props.admission.id, body);
        toast.success(t('education.admit_dialog.done', { name: data.name, code: data.code }));
        emit('done', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('education.conflict'));
            emit('conflict');
            return;
        }
        errors.value = error.errors ?? {};
        const named = error.code === 'section_full' ? 'section_id' : error.data?.field;
        if (named) errors.value = { ...errors.value, [named]: [error.message] };
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
const levelName = computed(() => setup.levelText(props.admission.level_id));
</script>

<template>
    <AppDialog :open="open" :title="t('education.admit_dialog.title', { name: admission.applicant?.name ?? '' })" :description="t('education.admit_dialog.text', { level: levelName })" :icon="UserCheck" size="lg" @close="emit('close')">
        <form id="education-admit" class="space-y-4" novalidate @submit.prevent="submit">
            <fieldset class="space-y-2">
                <legend class="mb-1.5 text-[13px] font-medium text-fg-2">{{ setup.sectionWord(admission.program_id, t('education.admit_dialog.section')) }}</legend>
                <p v-if="loading" class="text-[12.5px] text-muted">…</p>
                <label
                    v-for="section in sections"
                    :key="section.id"
                    class="flex items-center gap-3 rounded-xl border border-line-strong px-3 py-2.5 has-[:checked]:border-brand has-[:checked]:bg-brand-soft"
                    :class="section.taken >= section.capacity ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'"
                >
                    <input v-model="form.section_id" type="radio" :value="section.id" class="accent-[var(--brand)]" :disabled="section.taken >= section.capacity" />
                    <span class="w-24 shrink-0 truncate text-[13.5px] font-medium">{{ section.name }}</span>
                    <CapacityMeter :taken="section.taken" :capacity="section.capacity" class="flex-1" />
                </label>
                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-line-strong px-3 py-2.5 text-[13.5px] has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                    <input v-model="form.section_id" type="radio" :value="null" class="accent-[var(--brand)]" />
                    {{ t('education.admit_dialog.section_later') }}
                </label>
                <p v-if="fieldError('section_id')" class="text-[12.5px] text-bad" role="alert">{{ fieldError('section_id') }}</p>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('education.admit_dialog.category')" :error="fieldError('category_id')" optional>
                    <select :id="id" v-model="form.category_id" class="field-input">
                        <option value="">—</option>
                        <option v-for="item in setup.list('category')" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
                <AppField v-if="batches.length" v-slot="{ id }" :label="t('education.admit_dialog.batch')" :error="fieldError('batch_id')" optional>
                    <select :id="id" v-model="form.batch_id" class="field-input">
                        <option value="">—</option>
                        <option v-for="item in batches" :key="item.id" :value="item.id">{{ item.name }}</option>
                    </select>
                </AppField>
                <AppField v-if="sensitive" v-slot="{ id }" :label="t('education.admit_dialog.birth_registration_no')" :error="fieldError('birth_registration_no')" optional>
                    <input :id="id" v-model="form.birth_registration_no" dir="ltr" inputmode="numeric" class="field-input tabular" maxlength="40" autocomplete="off" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.admit_dialog.admitted_on')" :error="fieldError('admitted_on')">
                    <input :id="id" v-model="form.admitted_on" type="date" class="field-input" />
                </AppField>
            </div>
            <OwnFields v-model="extra" :fields="fields" :errors="errors" prefix="extra" />
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="education-admit" :loading="saving">{{ t('education.admit_dialog.confirm') }}</AppButton>
        </template>
    </AppDialog>
</template>
