<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ClipboardList } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { currentOrganization } from '@/lib/session';
import { cleanTexts, textIn, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';

/**
 * A new exam (or its name, kind, dates and grading changed). The session
 * and campus are fixed once made; the grading scheme starts as the rule
 * education_exams.default_grading_scheme says.
 */
const props = defineProps({ open: Boolean, exam: { type: Object, default: null }, sessionId: { type: String, default: '' } });
const emit = defineEmits(['close', 'saved']);
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();

const KINDS = ['term', 'final', 'test', 'assignment', 'other'];
const form = reactive({ name: textsFor({}), session_id: '', unit_id: '', kind: 'term', starts_on: '', ends_on: '', grading_scheme_id: '' });
const errors = ref({});
const saving = ref(false);
const editing = computed(() => Boolean(props.exam?.id));
const schemes = computed(() => (setup.data.value?.schemes ?? []).filter((scheme) => scheme.is_active || scheme.id === props.exam?.grading_scheme_id));
const campuses = computed(() => setup.data.value?.campuses ?? []);

watch(() => props.open, (open) => {
    if (!open) return;
    const exam = props.exam;
    const byRule = schemes.value.find((scheme) => scheme.code === setup.rules.value.default_grading_scheme)?.id ?? schemes.value[0]?.id ?? '';
    Object.assign(form, {
        name: textsFor(exam?.name),
        session_id: exam?.session_id ?? props.sessionId ?? '',
        unit_id: exam?.unit_id ?? '',
        kind: exam?.kind ?? 'term',
        starts_on: exam?.starts_on ?? '',
        ends_on: exam?.ends_on ?? '',
        grading_scheme_id: exam?.grading_scheme_id ?? byRule,
    });
    errors.value = {};
});
const ready = computed(() => Object.keys(cleanTexts(form.name)).length && form.session_id && form.grading_scheme_id && (!form.ends_on || !form.starts_on || form.ends_on >= form.starts_on));
const error = (field) => errors.value[field]?.[0] ?? null;

async function save() {
    saving.value = true;
    errors.value = {};
    const body = { name: cleanTexts(form.name), kind: form.kind, starts_on: form.starts_on || null, ends_on: form.ends_on || null, grading_scheme_id: form.grading_scheme_id };
    try {
        const { data } = editing.value
            ? await api.updateExam(props.exam.id, { ...body, base_version: props.exam.version })
            : await api.createExam({ ...body, session_id: form.session_id, unit_id: form.unit_id || null });
        toast.success(t(editing.value ? 'education_exams.exam.saved' : 'education_exams.exam.made'));
        emit('saved', data);
    } catch (caught) {
        errors.value = caught.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(caught.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t(editing ? 'education_exams.exam.edit' : 'education_exams.exam.new')" :description="t('education_exams.exam.form_text')" :icon="ClipboardList" size="lg" @close="emit('close')">
        <form id="exam-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="ready && save()">
            <TranslatedFields v-model="form.name" :label="t('education_exams.exam.name')" :errors="errors" class="sm:col-span-2" required autofocus />
            <AppField v-slot="{ id }" :label="t('education_exams.exam.session')" :error="error('session_id')">
                <select :id="id" v-model="form.session_id" class="field-input" :disabled="editing">
                    <option v-for="session in setup.data.value?.sessions ?? []" :key="session.id" :value="session.id">{{ textIn(session.name) }}</option>
                </select>
            </AppField>
            <AppField v-if="campuses.length > 1" v-slot="{ id }" :label="t('education_exams.exam.campus')" :hint="t('education_exams.exam.campus_hint')" :error="error('unit_id')">
                <select :id="id" v-model="form.unit_id" class="field-input" :disabled="editing">
                    <option value="">{{ t('education_exams.exam.campus_here') }}</option>
                    <option v-for="campus in campuses" :key="campus.id" :value="campus.id">{{ campus.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_exams.exam.kind')" :error="error('kind')">
                <select :id="id" v-model="form.kind" class="field-input">
                    <option v-for="kind in KINDS" :key="kind" :value="kind">{{ t(`education_exams.kinds.${kind}`) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id, describedby }" :label="t('education_exams.exam.scheme')" :hint="t('education_exams.exam.scheme_hint')" :error="error('grading_scheme_id')">
                <select :id="id" v-model="form.grading_scheme_id" class="field-input" :aria-describedby="describedby">
                    <option v-for="scheme in schemes" :key="scheme.id" :value="scheme.id">{{ textIn(scheme.name) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_exams.exam.starts_on')" :error="error('starts_on')" optional>
                <input :id="id" v-model="form.starts_on" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_exams.exam.ends_on')" :error="error('ends_on') ?? (form.ends_on && form.starts_on && form.ends_on < form.starts_on ? t('education_exams.exam.ends_before') : null)" optional>
                <input :id="id" v-model="form.ends_on" type="date" class="field-input" :min="form.starts_on || undefined" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education_exams.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="exam-form" :loading="saving" :disabled="!ready">{{ t(editing ? 'education_exams.save' : 'education_exams.exam.make') }}</AppButton>
        </template>
    </AppDialog>
</template>
