<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { currentOrganization } from '@/lib/session';
import { cleanTexts, textIn, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { keyFrom } from '../lib';

/**
 * One entry of the institution's structure made or changed: a program, a
 * class (level), a year, a session, a section, a list entry or a batch.
 * What is fixed once made (a program's progression, a list key, the parent
 * of a level or session) is shown but not editable. Names people read are
 * asked in every language the app speaks.
 */
const props = defineProps({
    open: Boolean,
    kind: { type: String, default: null }, // programs | levels | years | sessions | sections | lists | batches
    record: { type: Object, default: null },
    // Starting values of a new entry (the program of a new class, the year of a new session…).
    start: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'saved', 'conflict']);

const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();

const TRANSLATED = ['programs', 'levels', 'sessions', 'lists'];
const SWITCHABLE = ['programs', 'levels', 'sections', 'lists', 'batches'];
const LIST_KINDS = ['gender', 'relation', 'category', 'shift', 'medium', 'stream'];

const editing = computed(() => props.record !== null);
const form = reactive({});
const names = ref({});
const levelLabel = ref({});
const sectionLabel = ref({});
const errors = ref({});
const saving = ref(false);
const keyTouched = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const record = props.record ?? {};
        const start = props.start;
        names.value = textsFor(TRANSLATED.includes(props.kind) ? record.name : {});
        levelLabel.value = textsFor(record.level_label);
        sectionLabel.value = textsFor(record.section_label);
        const campuses = setup.data.value?.campuses ?? [];
        Object.keys(form).forEach((key) => delete form[key]);
        Object.assign(form, {
            code: record.code ?? '',
            progression: record.progression ?? 'year',
            periods_per_year: record.periods_per_year ?? 1,
            sort_order: record.sort_order ?? 0,
            program_id: record.program_id ?? start.program_id ?? '',
            sequence: record.sequence ?? start.sequence ?? 1,
            next_level_id: record.next_level_id ?? '',
            min_age: record.min_age ?? '',
            name: TRANSLATED.includes(props.kind) ? '' : record.name ?? '',
            starts_on: record.starts_on ?? start.starts_on ?? '',
            ends_on: record.ends_on ?? start.ends_on ?? '',
            status: record.status ?? (props.kind === 'years' || props.kind === 'sessions' ? 'open' : ''),
            academic_year_id: record.academic_year_id ?? start.academic_year_id ?? setup.data.value?.years?.[0]?.id ?? '',
            kind: record.kind ?? start.kind ?? (props.kind === 'lists' ? 'category' : 'year'),
            key: record.key ?? '',
            unit_id: record.unit_id ?? (campuses.some((campus) => campus.id === org.id) ? org.id : campuses[0]?.id ?? ''),
            session_id: record.session_id ?? start.session_id ?? '',
            level_id: record.level_id ?? start.level_id ?? '',
            capacity: record.capacity ?? '',
            class_teacher_id: record.class_teacher_id ?? '',
            shift_id: record.shift_id ?? '',
            medium_id: record.medium_id ?? '',
            stream_id: record.stream_id ?? '',
            intake_session_id: record.intake_session_id ?? '',
            is_active: record.is_active ?? true,
        });
        keyTouched.value = false;
        errors.value = {};
    },
);

// A year-by-year program has one session a year.
watch(() => form.progression, (progression) => {
    if (progression === 'year') form.periods_per_year = 1;
    else if (form.periods_per_year < 2) form.periods_per_year = progression === 'semester' ? 2 : 3;
});

function setNames(value) {
    names.value = value;
    if (props.kind === 'lists' && !editing.value && !keyTouched.value) form.key = keyFrom(value.en);
}

const programs = computed(() => setup.data.value?.programs ?? []);
const levelsOfProgram = computed(() => setup.levelsOf(form.program_id).filter((level) => level.id !== props.record?.id));
const sectionLevels = computed(() => {
    const session = setup.session(form.session_id);
    return (setup.data.value?.levels ?? [])
        .filter((level) => level.is_active && (!session || setup.program(level.program_id)?.progression === session.kind))
        .sort((a, b) => setup.rank(a.id) - setup.rank(b.id));
});
const teachers = computed(() => setup.data.value?.teachers ?? []);
const programSessions = computed(() => setup.sessionsOf(form.program_id));
const sectionWord = computed(() => (form.level_id ? setup.sectionWord(setup.level(form.level_id)?.program_id, t('education.word.section')) : t('education.word.section')));

const optionalTexts = (texts) => {
    const clean = cleanTexts(texts);
    return Object.keys(clean).length ? clean : null;
};
const orNull = (value) => (value === '' || value === undefined ? null : value);

function body() {
    const create = !editing.value;
    const kind = props.kind;
    const out = {};
    if (kind === 'programs') {
        Object.assign(out, { code: form.code.trim(), name: cleanTexts(names.value), level_label: optionalTexts(levelLabel.value), section_label: optionalTexts(sectionLabel.value), periods_per_year: Number(form.periods_per_year), sort_order: Number(form.sort_order) || 0 });
        if (create) out.progression = form.progression;
    } else if (kind === 'levels') {
        Object.assign(out, { sequence: Number(form.sequence), code: form.code.trim(), name: cleanTexts(names.value), next_level_id: orNull(form.next_level_id), min_age: form.min_age === '' ? null : Number(form.min_age) });
        if (create) out.program_id = form.program_id;
    } else if (kind === 'years') {
        Object.assign(out, { name: form.name.trim(), starts_on: form.starts_on, ends_on: form.ends_on, status: form.status });
    } else if (kind === 'sessions') {
        Object.assign(out, { sequence: Number(form.sequence), name: cleanTexts(names.value), starts_on: form.starts_on, ends_on: form.ends_on, status: form.status });
        if (create) Object.assign(out, { academic_year_id: form.academic_year_id, kind: form.kind });
    } else if (kind === 'sections') {
        Object.assign(out, { name: form.name.trim(), class_teacher_id: orNull(form.class_teacher_id), shift_id: orNull(form.shift_id), medium_id: orNull(form.medium_id), stream_id: orNull(form.stream_id) });
        if (form.capacity !== '' && form.capacity !== null) out.capacity = Number(form.capacity);
        if (create) Object.assign(out, { unit_id: form.unit_id, session_id: form.session_id, level_id: form.level_id });
    } else if (kind === 'lists') {
        Object.assign(out, { name: cleanTexts(names.value), sort_order: Number(form.sort_order) || 0 });
        if (create) Object.assign(out, { kind: form.kind, key: form.key });
    } else if (kind === 'batches') {
        Object.assign(out, { name: form.name.trim(), intake_session_id: orNull(form.intake_session_id) });
        if (create) out.program_id = form.program_id;
    }
    if (!create) {
        out.base_version = props.record.version;
        if (SWITCHABLE.includes(kind)) out.is_active = form.is_active;
    }
    return out;
}

async function submit() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = editing.value ? await education.update(props.kind, props.record.id, body()) : await education.create(props.kind, body());
        toast.success(t('education.structure.saved'));
        await setup.reload();
        emit('saved', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('education.conflict'));
            emit('conflict');
            return;
        }
        errors.value = error.errors ?? {};
        if (error.data?.field) errors.value = { ...errors.value, [error.data.field]: [error.message] };
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
const title = computed(() => (props.kind ? t(`education.structure.${editing.value ? 'edit' : 'add'}.${props.kind}`) : ''));
</script>

<template>
    <AppDialog :open="open" :title="title" size="lg" @close="emit('close')">
        <form id="education-structure" class="space-y-4" novalidate @submit.prevent="submit">
            <TranslatedFields
                v-if="TRANSLATED.includes(kind)"
                :model-value="names"
                :label="t('education.structure.form.name')"
                required
                :maxlength="kind === 'sessions' ? 60 : 120"
                :errors="errors"
                error-prefix="name"
                autofocus
                @update:model-value="setNames"
            />

            <!-- Programs -->
            <template v-if="kind === 'programs'">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('education.structure.form.code')" :hint="t('education.structure.form.code_hint')" :error="fieldError('code')">
                        <input :id="id" v-model="form.code" class="field-input font-mono uppercase" dir="ltr" maxlength="20" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.structure.form.progression')" :hint="t('education.structure.form.progression_hint')" :error="fieldError('progression')">
                        <select :id="id" v-model="form.progression" class="field-input" :disabled="editing">
                            <option v-for="value in ['year', 'semester', 'term']" :key="value" :value="value">{{ t(`education.progressions.${value}`) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.structure.form.periods_per_year')" :error="fieldError('periods_per_year')">
                        <input :id="id" v-model="form.periods_per_year" type="number" min="1" max="4" class="field-input tabular" :disabled="form.progression === 'year'" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.structure.form.sort_order')" :error="fieldError('sort_order')" optional>
                        <input :id="id" v-model="form.sort_order" type="number" min="0" max="1000" class="field-input tabular" />
                    </AppField>
                </div>
                <TranslatedFields v-model="levelLabel" :label="t('education.structure.form.level_label')" :maxlength="40" :errors="errors" error-prefix="level_label" />
                <p class="-mt-2 text-[12px] text-muted">{{ t('education.structure.form.level_label_hint') }}</p>
                <TranslatedFields v-model="sectionLabel" :label="t('education.structure.form.section_label')" :maxlength="40" :errors="errors" error-prefix="section_label" />
                <p class="-mt-2 text-[12px] text-muted">{{ t('education.structure.form.section_label_hint') }}</p>
            </template>

            <!-- Classes (levels) -->
            <div v-else-if="kind === 'levels'" class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('education.structure.form.program')" :error="fieldError('program_id')">
                    <select :id="id" v-model="form.program_id" class="field-input" :disabled="editing">
                        <option v-for="item in programs" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.code')" :hint="t('education.structure.form.code_hint')" :error="fieldError('code')">
                    <input :id="id" v-model="form.code" class="field-input font-mono uppercase" dir="ltr" maxlength="20" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.sequence')" :error="fieldError('sequence')">
                    <input :id="id" v-model="form.sequence" type="number" min="1" max="100" class="field-input tabular" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.min_age')" :error="fieldError('min_age')" optional>
                    <input :id="id" v-model="form.min_age" type="number" min="1" max="80" class="field-input tabular" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.next_level')" :error="fieldError('next_level_id')" class="sm:col-span-2" optional>
                    <select :id="id" v-model="form.next_level_id" class="field-input">
                        <option value="">{{ t('education.structure.form.next_level_none') }}</option>
                        <option v-for="item in levelsOfProgram" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
            </div>

            <!-- Years -->
            <div v-else-if="kind === 'years'" class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('education.structure.form.year_name')" :hint="t('education.wizard.year_name_hint')" :error="fieldError('name')" class="sm:col-span-2">
                    <input :id="id" v-model="form.name" class="field-input" maxlength="40" autofocus />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.starts_on')" :error="fieldError('starts_on')">
                    <input :id="id" v-model="form.starts_on" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.ends_on')" :error="fieldError('ends_on')">
                    <input :id="id" v-model="form.ends_on" type="date" class="field-input" :min="form.starts_on || undefined" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.status')" :error="fieldError('status')">
                    <select :id="id" v-model="form.status" class="field-input">
                        <option v-for="value in ['planned', 'open', 'closed']" :key="value" :value="value">{{ t(`education.session_statuses.${value}`) }}</option>
                    </select>
                </AppField>
            </div>

            <!-- Sessions -->
            <div v-else-if="kind === 'sessions'" class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('education.structure.form.year')" :error="fieldError('academic_year_id')">
                    <select :id="id" v-model="form.academic_year_id" class="field-input" :disabled="editing">
                        <option v-for="item in setup.data.value?.years ?? []" :key="item.id" :value="item.id">{{ item.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.kind')" :error="fieldError('kind')">
                    <select :id="id" v-model="form.kind" class="field-input" :disabled="editing">
                        <option v-for="value in ['year', 'semester', 'term']" :key="value" :value="value">{{ t(`education.progressions.${value}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.session_sequence')" :error="fieldError('sequence')">
                    <input :id="id" v-model="form.sequence" type="number" min="1" max="4" class="field-input tabular" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.status')" :error="fieldError('status')">
                    <select :id="id" v-model="form.status" class="field-input">
                        <option v-for="value in ['planned', 'open', 'closed']" :key="value" :value="value">{{ t(`education.session_statuses.${value}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.starts_on')" :error="fieldError('starts_on')">
                    <input :id="id" v-model="form.starts_on" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.ends_on')" :error="fieldError('ends_on')">
                    <input :id="id" v-model="form.ends_on" type="date" class="field-input" :min="form.starts_on || undefined" />
                </AppField>
            </div>

            <!-- Sections -->
            <div v-else-if="kind === 'sections'" class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('education.structure.form.session')" :error="fieldError('session_id')">
                    <select :id="id" v-model="form.session_id" class="field-input" :disabled="editing">
                        <option value="">—</option>
                        <option v-for="item in setup.data.value?.sessions ?? []" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.level')" :error="fieldError('level_id')">
                    <select :id="id" v-model="form.level_id" class="field-input" :disabled="editing">
                        <option value="">—</option>
                        <option v-for="item in sectionLevels" :key="item.id" :value="item.id">{{ setup.levelText(item.id) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="`${t('education.structure.form.section_name')} (${sectionWord})`" :hint="t('education.structure.form.section_name_hint')" :error="fieldError('name')">
                    <input :id="id" v-model="form.name" class="field-input" maxlength="40" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.capacity')" :hint="t('education.structure.form.capacity_hint')" :error="fieldError('capacity')" optional>
                    <input :id="id" v-model="form.capacity" type="number" min="1" max="2000" class="field-input tabular" />
                </AppField>
                <AppField v-if="(setup.data.value?.campuses?.length ?? 0) > 1" v-slot="{ id }" :label="t('education.structure.form.unit')" :error="fieldError('unit_id')">
                    <select :id="id" v-model="form.unit_id" class="field-input" :disabled="editing">
                        <option v-for="item in setup.data.value?.campuses ?? []" :key="item.id" :value="item.id">{{ item.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id, describedby }" :label="t('education.structure.form.class_teacher')" :hint="setup.data.value?.hrm ? '' : t('education.structure.form.class_teacher_hrm')" :error="fieldError('class_teacher_id')" optional>
                    <select :id="id" v-model="form.class_teacher_id" class="field-input" :disabled="!setup.data.value?.hrm" :aria-describedby="describedby">
                        <option value="">{{ t('education.structure.form.class_teacher_none') }}</option>
                        <option v-for="item in teachers" :key="item.id" :value="item.id">{{ item.name }} ({{ item.code }})</option>
                    </select>
                </AppField>
                <AppField v-for="list in ['shift', 'medium', 'stream']" :key="list" v-slot="{ id }" :label="t(`education.structure.form.${list}`)" :error="fieldError(`${list}_id`)" optional>
                    <select :id="id" v-model="form[`${list}_id`]" class="field-input">
                        <option value="">—</option>
                        <option v-for="item in setup.list(list)" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
            </div>

            <!-- List entries -->
            <div v-else-if="kind === 'lists'" class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('education.structure.form.list_kind')" :error="fieldError('kind')">
                    <select :id="id" v-model="form.kind" class="field-input" :disabled="editing">
                        <option v-for="value in LIST_KINDS" :key="value" :value="value">{{ t(`education.structure.list_kinds.${value}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.key')" :hint="t('education.structure.form.key_hint')" :error="fieldError('key')">
                    <input :id="id" v-model="form.key" class="field-input font-mono" dir="ltr" maxlength="40" :disabled="editing" @input="keyTouched = true" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.sort_order')" :error="fieldError('sort_order')" optional>
                    <input :id="id" v-model="form.sort_order" type="number" min="0" max="1000" class="field-input tabular" />
                </AppField>
            </div>

            <!-- Batches -->
            <div v-else-if="kind === 'batches'" class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('education.structure.form.program')" :error="fieldError('program_id')">
                    <select :id="id" v-model="form.program_id" class="field-input" :disabled="editing">
                        <option value="">—</option>
                        <option v-for="item in programs" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.batch_name')" :error="fieldError('name')">
                    <input :id="id" v-model="form.name" class="field-input" maxlength="60" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.structure.form.intake_session')" :error="fieldError('intake_session_id')" optional>
                    <select :id="id" v-model="form.intake_session_id" class="field-input">
                        <option value="">—</option>
                        <option v-for="item in programSessions" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
            </div>

            <AppSwitch v-if="editing && SWITCHABLE.includes(kind)" v-model="form.is_active" :label="t('education.structure.form.active')" show-label />
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="education-structure" :loading="saving">{{ t('education.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
