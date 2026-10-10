<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Check, Plus, Trash2, UserPlus } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import OwnFields from '@/components/OwnFields.vue';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { fieldsToApi, fieldsToForm, fullness, newOpId, stepOfError } from '../lib';

/**
 * A student admitted directly (three steps: the student, their guardians,
 * their class and section) or a student's details changed (the first step
 * only; guardians and classes have their own screens). Private details are
 * asked only of people who may see them. A new student is sent with an op
 * id, so a retry after a dropped connection never makes them twice.
 */
const props = defineProps({
    open: Boolean,
    student: { type: Object, default: null },
    // Starting choices for a new student (the filters of the list it was opened from).
    start: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'saved', 'conflict']);

const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();

const editing = computed(() => props.student !== null);
const sensitive = computed(() => setup.can('view_sensitive'));
const studentFields = computed(() => setup.fields('student'));
const guardianFields = computed(() => setup.fields('guardian'));

const STEPS = [
    { key: 'student', prefixes: ['name', 'name_local', 'gender', 'date_of_birth', 'birth_registration_no', 'phone', 'email', 'program_id', 'batch_id', 'category_id', 'admission_no', 'admitted_on', 'extra'] },
    { key: 'guardians', prefixes: ['guardians'] },
    { key: 'place', prefixes: ['enrollment'] },
];
const steps = computed(() => (editing.value ? STEPS.slice(0, 1) : STEPS));
const step = ref('student');
const stepIndex = computed(() => steps.value.findIndex((item) => item.key === step.value));
const last = computed(() => stepIndex.value === steps.value.length - 1);

const form = reactive({});
const extra = ref({});
const guardians = ref([]);
const place = reactive({ session_id: '', level_id: '', section_id: '' });
const errors = ref({});
const saving = ref(false);
let opId = null;

const emptyGuardian = (relation) => ({ relation, name: '', phone: '', email: '', occupation: '', national_id: '', is_primary: false, can_pick_up: true, receives_notices: true, extra: {} });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const student = props.student;
        Object.assign(form, {
            name: student?.name ?? '',
            name_local: student?.name_local ?? '',
            gender: student?.gender ?? '',
            date_of_birth: student?.date_of_birth ?? '',
            birth_registration_no: student?.birth_registration_no ?? '',
            phone: student?.phone ?? '',
            email: student?.email ?? '',
            program_id: student?.program_id ?? props.start.program_id ?? (setup.data.value?.programs?.length === 1 ? setup.data.value.programs[0].id : ''),
            batch_id: student?.batch_id ?? '',
            category_id: student?.category_id ?? '',
            admission_no: student?.admission_no ?? '',
            admitted_on: new Date().toISOString().slice(0, 10),
        });
        extra.value = fieldsToForm(studentFields.value, student?.extra);
        guardians.value = student ? [] : [{ ...emptyGuardian('father'), is_primary: true }];
        Object.assign(place, { session_id: props.start.session_id ?? '', level_id: props.start.level_id ?? '', section_id: '' });
        errors.value = {};
        step.value = 'student';
        opId = newOpId();
    },
    // Also when the form is open from the start (the header "New" menu opens the list with ?new=1).
    { immediate: true },
);

// Choices that hang on the program: its batches, sessions and levels.
const program = computed(() => setup.program(form.program_id));
const batches = computed(() => (setup.data.value?.batches ?? []).filter((batch) => batch.program_id === form.program_id && batch.is_active));
const sessions = computed(() => (form.program_id ? setup.sessionsOf(form.program_id).filter((session) => session.status !== 'closed') : []));
const levels = computed(() => (form.program_id ? setup.levelsOf(form.program_id).filter((level) => level.is_active) : []));
const levelWord = computed(() => setup.levelWord(form.program_id, t('education.word.level')));
const sectionWord = computed(() => setup.sectionWord(form.program_id, t('education.word.section')));

watch(() => form.program_id, () => {
    if (!batches.value.some((batch) => batch.id === form.batch_id)) form.batch_id = '';
    if (!sessions.value.some((session) => session.id === place.session_id)) place.session_id = sessions.value.find((session) => session.status === 'open')?.id ?? sessions.value[0]?.id ?? '';
    if (!levels.value.some((level) => level.id === place.level_id)) place.level_id = '';
});

// Sections of the chosen session and level, with seats taken (read when both are known).
const sectionsLoading = ref(false);
const sessionSections = ref([]);
watch(
    () => [props.open, place.session_id],
    async ([open, sessionId]) => {
        sessionSections.value = [];
        if (!open || !sessionId || editing.value) return;
        sectionsLoading.value = true;
        try {
            sessionSections.value = (await education.overview({ session_id: sessionId })).data.sections;
        } catch {
            sessionSections.value = [];
        } finally {
            sectionsLoading.value = false;
        }
    },
);
const sections = computed(() => sessionSections.value.filter((section) => section.level_id === place.level_id && section.is_active));
watch(() => [place.session_id, place.level_id], () => {
    if (!sections.value.some((section) => section.id === place.section_id)) place.section_id = '';
});

function addGuardian() {
    const used = new Set(guardians.value.map((guardian) => guardian.relation));
    const relation = setup.list('relation').map((item) => item.key).find((key) => !used.has(key)) ?? 'guardian';
    guardians.value.push({ ...emptyGuardian(relation), is_primary: guardians.value.length === 0 });
}

function removeGuardian(index) {
    const [removed] = guardians.value.splice(index, 1);
    if (removed.is_primary && guardians.value.length) guardians.value[0].is_primary = true;
}

function makePrimary(index) {
    guardians.value.forEach((guardian, at) => (guardian.is_primary = at === index));
}

// Checks people can see before going on; the server checks everything again.
function checkStep() {
    const found = {};
    if (step.value === 'student') {
        if (!form.name.trim()) found.name = [t('education.required')];
        if (!form.program_id) found.program_id = [t('education.required')];
    }
    if (step.value === 'guardians') {
        guardians.value.forEach((guardian, index) => {
            if (!guardian.name.trim() && !guardian.phone.trim()) found[`guardians.${index}.name`] = [t('education.guardian.need_one')];
        });
    }
    errors.value = found;
    return !Object.keys(found).length;
}

function next() {
    if (!checkStep()) return;
    step.value = steps.value[stepIndex.value + 1].key;
}

function back() {
    errors.value = {};
    step.value = steps.value[stepIndex.value - 1].key;
}

const clean = (value) => (typeof value === 'string' ? value.trim() || null : value);

function studentBody() {
    const body = {
        name: form.name.trim(),
        name_local: clean(form.name_local),
        gender: form.gender || null,
        phone: clean(form.phone),
        email: clean(form.email),
        program_id: form.program_id,
        batch_id: form.batch_id || null,
        category_id: form.category_id || null,
        admission_no: clean(form.admission_no),
        extra: fieldsToApi(studentFields.value, extra.value, { clear: editing.value }),
    };
    if (sensitive.value) Object.assign(body, { date_of_birth: form.date_of_birth || null, birth_registration_no: clean(form.birth_registration_no) });
    return body;
}

async function submit() {
    if (!last.value) return next();
    if (!checkStep()) return;

    saving.value = true;
    errors.value = {};
    try {
        if (editing.value) {
            const { data } = await education.updateStudent(props.student.id, { ...studentBody(), base_version: props.student.version });
            toast.success(t('education.new_student.updated'));
            emit('saved', data);
            return;
        }
        const body = {
            ...studentBody(),
            op_id: opId,
            admitted_on: form.admitted_on || null,
            guardians: guardians.value.map((guardian) => ({
                relation: guardian.relation,
                name: clean(guardian.name),
                phone: clean(guardian.phone),
                email: clean(guardian.email),
                occupation: clean(guardian.occupation),
                ...(sensitive.value ? { national_id: clean(guardian.national_id) } : {}),
                is_primary: guardian.is_primary,
                can_pick_up: guardian.can_pick_up,
                receives_notices: guardian.receives_notices,
                extra: fieldsToApi(guardianFields.value, guardian.extra),
            })),
            enrollment: place.session_id && place.level_id ? { session_id: place.session_id, level_id: place.level_id, section_id: place.section_id || null } : null,
        };
        const { data } = await education.createStudent(body);
        toast.success(t('education.new_student.saved', { name: data.name, code: data.code }));
        emit('saved', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('education.conflict'));
            emit('conflict');
            return;
        }
        errors.value = error.errors ?? {};
        // A field the server names (a full section, a registration number held by another student).
        const named = error.code === 'section_full' ? 'section_id' : error.data?.field;
        if (named) errors.value = { ...errors.value, [['session_id', 'level_id', 'section_id'].includes(named) ? `enrollment.${named}` : named]: [error.message] };
        const keys = Object.keys(errors.value);
        if (keys.length) {
            step.value = stepOfError(keys, steps.value) ?? step.value;
            toast.error(t('education.new_student.check'));
        } else {
            toast.error(error.message);
        }
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
const sectionText = (section) => {
    const state = fullness(section.taken, section.capacity);
    return `${section.name} · ${section.taken}/${section.capacity}${state.state === 'full' ? ` · ${t('education.new_student.section_full')}` : ''}`;
};
</script>

<template>
    <AppDialog
        :open="open"
        :title="editing ? t('education.new_student.edit_title', { name: student.name }) : t('education.new_student.title')"
        :description="editing ? student.code : t('education.new_student.code_hint')"
        :icon="editing ? null : UserPlus"
        size="lg"
        @close="emit('close')"
    >
        <!-- Where you are in the steps: number, name and a tick when passed (never colour alone). -->
        <ol v-if="steps.length > 1" class="mb-5 flex items-center gap-2" :aria-label="t('education.new_student.title')">
            <li v-for="(item, index) in steps" :key="item.key" class="flex min-w-0 flex-1 items-center gap-2">
                <span
                    class="grid size-6 shrink-0 place-items-center rounded-full text-[12px] font-semibold"
                    :class="index < stepIndex ? 'bg-ok-soft text-ok' : index === stepIndex ? 'bg-brand text-brand-fg' : 'bg-subtle text-muted'"
                    :aria-current="index === stepIndex ? 'step' : undefined"
                >
                    <Check v-if="index < stepIndex" class="size-3.5" aria-hidden="true" />
                    <template v-else>{{ index + 1 }}</template>
                </span>
                <span class="truncate text-[12.5px]" :class="index === stepIndex ? 'font-semibold text-fg' : 'text-muted'">{{ t(`education.new_student.steps.${item.key}`) }}</span>
                <span v-if="index < steps.length - 1" class="hidden h-px flex-1 bg-line sm:block" aria-hidden="true" />
            </li>
        </ol>

        <form id="education-student" class="space-y-4" novalidate @submit.prevent="submit">
            <template v-if="step === 'student'">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id, invalid, describedby }" :label="t('education.fields.name')" :hint="t('education.fields.name_hint')" :error="fieldError('name')" class="sm:col-span-2">
                        <input :id="id" v-model="form.name" class="field-input" maxlength="150" autocomplete="off" :aria-invalid="invalid || undefined" :aria-describedby="describedby" autofocus />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.fields.name_local')" :error="fieldError('name_local')" optional>
                        <input :id="id" v-model="form.name_local" class="field-input" lang="bn" maxlength="150" autocomplete="off" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.fields.gender')" :error="fieldError('gender')" optional>
                        <select :id="id" v-model="form.gender" class="field-input">
                            <option value="">—</option>
                            <option v-for="item in setup.list('gender')" :key="item.key" :value="item.key">{{ textIn(item.name) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id, invalid, describedby }" :label="t('education.fields.program')" :error="fieldError('program_id')">
                        <select :id="id" v-model="form.program_id" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedby">
                            <option value="">—</option>
                            <option v-for="item in (setup.data.value?.programs ?? []).filter((p) => p.is_active)" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                        </select>
                    </AppField>
                    <AppField v-if="batches.length" v-slot="{ id }" :label="t('education.fields.batch')" :error="fieldError('batch_id')" optional>
                        <select :id="id" v-model="form.batch_id" class="field-input">
                            <option value="">—</option>
                            <option v-for="item in batches" :key="item.id" :value="item.id">{{ item.name }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.fields.category')" :error="fieldError('category_id')" optional>
                        <select :id="id" v-model="form.category_id" class="field-input">
                            <option value="">—</option>
                            <option v-for="item in setup.list('category')" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.fields.phone')" :error="fieldError('phone')" optional>
                        <input :id="id" v-model="form.phone" type="tel" inputmode="tel" dir="ltr" class="field-input" maxlength="30" autocomplete="off" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.fields.email')" :error="fieldError('email')" optional>
                        <input :id="id" v-model="form.email" type="email" dir="ltr" class="field-input" maxlength="190" autocomplete="off" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.fields.admission_no')" :hint="t('education.fields.admission_no_hint')" :error="fieldError('admission_no')" optional>
                        <input :id="id" v-model="form.admission_no" dir="ltr" class="field-input" maxlength="40" autocomplete="off" />
                    </AppField>
                    <AppField v-if="!editing" v-slot="{ id }" :label="t('education.fields.admitted_on')" :error="fieldError('admitted_on')">
                        <input :id="id" v-model="form.admitted_on" type="date" class="field-input" />
                    </AppField>
                </div>

                <!-- Private details: only for people allowed to see them. -->
                <fieldset v-if="sensitive" class="grid gap-4 rounded-xl border border-line p-4 sm:grid-cols-2">
                    <legend class="px-1 text-[12.5px] font-medium text-muted">{{ t('education.fields.private') }}</legend>
                    <AppField v-slot="{ id }" :label="t('education.fields.date_of_birth')" :error="fieldError('date_of_birth')" optional>
                        <input :id="id" v-model="form.date_of_birth" type="date" class="field-input" :max="new Date().toISOString().slice(0, 10)" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.fields.birth_registration_no')" :error="fieldError('birth_registration_no')" optional>
                        <input :id="id" v-model="form.birth_registration_no" dir="ltr" inputmode="numeric" class="field-input tabular" maxlength="40" autocomplete="off" />
                    </AppField>
                </fieldset>
                <p v-else-if="editing" class="text-[12.5px] text-muted">{{ t('education.student.sensitive_hidden') }}</p>

                <OwnFields v-model="extra" :fields="studentFields" :errors="errors" prefix="extra" />
            </template>

            <template v-else-if="step === 'guardians'">
                <p class="text-[13px] text-muted">{{ t('education.new_student.guardians_text') }}</p>
                <p v-if="!guardians.length" class="rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ t('education.new_student.no_guardians') }}</p>
                <fieldset v-for="(guardian, index) in guardians" :key="index" class="space-y-4 rounded-xl border border-line p-4">
                    <legend class="flex items-center gap-2 px-1 text-[13px] font-medium text-fg-2">
                        {{ setup.listName('relation', guardian.relation) || t('education.guardian.title') }}
                        <span v-if="guardian.is_primary" class="rounded-full bg-brand-soft px-2 py-0.5 text-[11.5px] text-brand-text">{{ t('education.guardian.primary') }}</span>
                    </legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppField v-slot="{ id }" :label="t('education.guardian.relation')" :error="fieldError(`guardians.${index}.relation`)">
                            <select :id="id" v-model="guardian.relation" class="field-input">
                                <option v-for="item in setup.list('relation')" :key="item.key" :value="item.key">{{ textIn(item.name) }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('education.guardian.name')" :error="fieldError(`guardians.${index}.name`)">
                            <input :id="id" v-model="guardian.name" class="field-input" maxlength="150" autocomplete="off" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('education.guardian.phone')" :hint="t('education.guardian.phone_hint')" :error="fieldError(`guardians.${index}.phone`)">
                            <input :id="id" v-model="guardian.phone" type="tel" inputmode="tel" dir="ltr" class="field-input" maxlength="30" autocomplete="off" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('education.guardian.occupation')" :error="fieldError(`guardians.${index}.occupation`)" optional>
                            <input :id="id" v-model="guardian.occupation" class="field-input" maxlength="100" autocomplete="off" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('education.guardian.email')" :error="fieldError(`guardians.${index}.email`)" optional>
                            <input :id="id" v-model="guardian.email" type="email" dir="ltr" class="field-input" maxlength="190" autocomplete="off" />
                        </AppField>
                        <AppField v-if="sensitive" v-slot="{ id }" :label="t('education.guardian.national_id')" :error="fieldError(`guardians.${index}.national_id`)" optional>
                            <input :id="id" v-model="guardian.national_id" dir="ltr" inputmode="numeric" class="field-input tabular" maxlength="40" autocomplete="off" />
                        </AppField>
                    </div>
                    <OwnFields v-model="guardian.extra" :fields="guardianFields" :errors="errors" :prefix="`guardians.${index}.extra`" />
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                        <AppSwitch :model-value="guardian.is_primary" :label="t('education.guardian.primary')" show-label @update:model-value="makePrimary(index)" />
                        <AppSwitch v-model="guardian.can_pick_up" :label="t('education.guardian.can_pick_up')" show-label />
                        <AppSwitch v-model="guardian.receives_notices" :label="t('education.guardian.receives_notices')" show-label />
                        <AppButton size="sm" variant="danger-soft" :icon="Trash2" class="ms-auto" @click="removeGuardian(index)">{{ t('education.guardian.remove') }}</AppButton>
                    </div>
                </fieldset>
                <AppButton v-if="guardians.length < 6" size="sm" :icon="Plus" @click="addGuardian">{{ t('education.new_student.add_guardian') }}</AppButton>
            </template>

            <template v-else>
                <p class="text-[13px] text-muted">{{ t('education.new_student.place_text') }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('education.fields.session')" :error="fieldError('enrollment.session_id')">
                        <select :id="id" v-model="place.session_id" class="field-input">
                            <option value="">{{ t('education.new_student.place_later') }}</option>
                            <option v-for="item in sessions" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="levelWord" :error="fieldError('enrollment.level_id')">
                        <select :id="id" v-model="place.level_id" class="field-input" :disabled="!place.session_id">
                            <option value="">—</option>
                            <option v-for="item in levels" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                        </select>
                    </AppField>
                </div>
                <fieldset v-if="place.session_id && place.level_id" class="space-y-2">
                    <legend class="mb-1.5 text-[13px] font-medium text-fg-2">{{ sectionWord }}</legend>
                    <p v-if="sectionsLoading" class="text-[12.5px] text-muted">…</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-line-strong px-3 py-2.5 text-[13px] has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                            <input v-model="place.section_id" type="radio" value="" class="accent-[var(--brand)]" />
                            {{ t('education.new_student.section_later') }}
                        </label>
                        <label
                            v-for="section in sections"
                            :key="section.id"
                            class="flex items-center gap-2.5 rounded-xl border border-line-strong px-3 py-2.5 text-[13px] has-[:checked]:border-brand has-[:checked]:bg-brand-soft"
                            :class="section.taken >= section.capacity ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'"
                        >
                            <input v-model="place.section_id" type="radio" :value="section.id" class="accent-[var(--brand)]" :disabled="section.taken >= section.capacity" />
                            <span class="min-w-0 flex-1 truncate">{{ sectionText(section) }}</span>
                        </label>
                    </div>
                    <p v-if="fieldError('enrollment.section_id')" class="text-[12.5px] text-bad" role="alert">{{ fieldError('enrollment.section_id') }}</p>
                </fieldset>
                <p v-if="program && !sessions.length" class="rounded-xl bg-warn-soft px-4 py-3 text-[12.5px] text-warn">{{ t('education.overview.no_session_text') }}</p>
            </template>
        </form>

        <template #footer>
            <AppButton v-if="stepIndex > 0" variant="ghost" class="me-auto" @click="back">{{ t('education.previous') }}</AppButton>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="education-student" :loading="saving">
                {{ !last ? t('education.next') : editing ? t('education.save') : t('education.new_student.save') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
