<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { FilePlus } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import OwnFields from '@/components/OwnFields.vue';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { emptyGuardian, fieldsToApi, fieldsToForm, guardianToApi, guardianToForm, newOpId } from '../lib';
import GuardianFields from './GuardianFields.vue';

/**
 * An application made at the office, or changed while undecided: the
 * applicant, the place applied for, guardians and the institution's own
 * application fields. Private details (date of birth, national ids) are
 * asked only of people allowed to see them; the guardians of an application
 * are changed only by them, so ids they cannot see are never lost.
 */
const props = defineProps({
    open: Boolean,
    admission: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved', 'conflict']);

const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();

const editing = computed(() => props.admission !== null);
const sensitive = computed(() => setup.can('view_sensitive'));
const fields = computed(() => setup.fields('admission'));
const guardianFields = computed(() => setup.fields('guardian'));
const guardiansEditable = computed(() => !editing.value || sensitive.value);

const form = reactive({});
const extra = ref({});
const guardians = ref([]);
const errors = ref({});
const saving = ref(false);
let opId = null;

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const applicant = props.admission?.applicant ?? {};
        const programs = setup.data.value?.programs ?? [];
        Object.assign(form, {
            name: applicant.name ?? '',
            name_local: applicant.name_local ?? '',
            gender: applicant.gender ?? '',
            date_of_birth: applicant.date_of_birth ?? '',
            phone: applicant.phone ?? '',
            email: applicant.email ?? '',
            previous_school: applicant.previous_school ?? '',
            program_id: props.admission?.program_id ?? (programs.length === 1 ? programs[0].id : ''),
            level_id: props.admission?.level_id ?? '',
            session_id: props.admission?.session_id ?? '',
            note: props.admission?.note ?? '',
        });
        extra.value = fieldsToForm(fields.value, applicant.extra);
        guardians.value = props.admission ? (applicant.guardians ?? []).map((guardian) => guardianToForm(guardian, guardianFields.value)) : [{ ...emptyGuardian('father'), is_primary: true }];
        errors.value = {};
        opId = newOpId();
    },
    // Also when opened from the start (the header "New" menu opens the list with ?new=1).
    { immediate: true },
);

const levels = computed(() => (form.program_id ? setup.levelsOf(form.program_id).filter((level) => level.is_active) : []));
// Applications are for a session not closed yet (often the next one).
const sessions = computed(() => (form.program_id ? setup.sessionsOf(form.program_id).filter((session) => session.status !== 'closed') : []));
const levelWord = computed(() => setup.levelWord(form.program_id, t('education.word.level')));
watch(() => form.program_id, () => {
    if (!levels.value.some((level) => level.id === form.level_id)) form.level_id = '';
    if (!sessions.value.some((session) => session.id === form.session_id)) form.session_id = sessions.value.find((session) => session.status === 'open')?.id ?? sessions.value[0]?.id ?? '';
});

const clean = (value) => String(value ?? '').trim() || null;

function body() {
    const applicant = {
        name: form.name.trim(),
        name_local: clean(form.name_local),
        gender: form.gender || null,
        phone: clean(form.phone),
        email: clean(form.email),
        previous_school: clean(form.previous_school),
        extra: fieldsToApi(fields.value, extra.value, { clear: editing.value }),
        ...(sensitive.value ? { date_of_birth: form.date_of_birth || null } : {}),
        ...(guardiansEditable.value ? { guardians: guardians.value.map((guardian) => guardianToApi(guardian, guardianFields.value, { sensitive: sensitive.value, links: false })) } : {}),
    };
    return { program_id: form.program_id, level_id: form.level_id, session_id: form.session_id, note: clean(form.note), applicant };
}

function check() {
    const found = {};
    if (!form.name.trim()) found['applicant.name'] = [t('education.required')];
    for (const key of ['program_id', 'level_id', 'session_id']) if (!form[key]) found[key] = [t('education.required')];
    guardians.value.forEach((guardian, index) => {
        if (!guardian.name.trim() && !guardian.phone.trim()) found[`applicant.guardians.${index}.name`] = [t('education.guardian.need_one')];
    });
    errors.value = found;
    return !Object.keys(found).length;
}

async function submit() {
    if (!check()) return;
    saving.value = true;
    try {
        const { data } = editing.value
            ? await education.updateAdmission(props.admission.id, { ...body(), base_version: props.admission.version })
            : await education.createAdmission({ ...body(), op_id: opId });
        toast.success(editing.value ? t('education.application_form.updated') : t('education.application_form.saved', { number: data.number }));
        emit('saved', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('education.conflict'));
            emit('conflict');
            return;
        }
        errors.value = error.errors ?? {};
        if (error.data?.field) errors.value = { ...errors.value, [error.data.field]: [error.message] };
        toast.error(Object.keys(errors.value).length ? t('education.new_student.check') : error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog
        :open="open"
        :title="editing ? t('education.application_form.edit_title', { number: admission.number }) : t('education.application_form.title')"
        :description="editing ? '' : t('education.application_form.number_hint')"
        :icon="FilePlus"
        size="lg"
        @close="emit('close')"
    >
        <form id="education-application" class="space-y-5" novalidate @submit.prevent="submit">
            <fieldset class="grid gap-4 sm:grid-cols-3">
                <legend class="mb-2 text-[13px] font-semibold text-fg">{{ t('education.admission.place') }}</legend>
                <AppField v-slot="{ id }" :label="t('education.fields.program')" :error="fieldError('program_id')">
                    <select :id="id" v-model="form.program_id" class="field-input">
                        <option value="">—</option>
                        <option v-for="item in (setup.data.value?.programs ?? []).filter((p) => p.is_active)" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="levelWord" :error="fieldError('level_id')">
                    <select :id="id" v-model="form.level_id" class="field-input" :disabled="!form.program_id">
                        <option value="">—</option>
                        <option v-for="item in levels" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.fields.session')" :error="fieldError('session_id')">
                    <select :id="id" v-model="form.session_id" class="field-input" :disabled="!form.program_id">
                        <option value="">—</option>
                        <option v-for="item in sessions" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
            </fieldset>

            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 text-[13px] font-semibold text-fg">{{ t('education.admission.applicant') }}</legend>
                <AppField v-slot="{ id, invalid, describedby }" :label="t('education.fields.name')" :hint="t('education.fields.name_hint')" :error="fieldError('applicant.name')" class="sm:col-span-2">
                    <input :id="id" v-model="form.name" class="field-input" maxlength="150" autocomplete="off" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.fields.name_local')" :error="fieldError('applicant.name_local')" optional>
                    <input :id="id" v-model="form.name_local" class="field-input" lang="bn" maxlength="150" autocomplete="off" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.fields.gender')" :error="fieldError('applicant.gender')" optional>
                    <select :id="id" v-model="form.gender" class="field-input">
                        <option value="">—</option>
                        <option v-for="item in setup.list('gender')" :key="item.key" :value="item.key">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
                <AppField v-if="sensitive" v-slot="{ id }" :label="t('education.fields.date_of_birth')" :error="fieldError('applicant.date_of_birth')" optional>
                    <input :id="id" v-model="form.date_of_birth" type="date" class="field-input" :max="new Date().toISOString().slice(0, 10)" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.application_form.previous_school')" :error="fieldError('applicant.previous_school')" optional>
                    <input :id="id" v-model="form.previous_school" class="field-input" maxlength="150" autocomplete="off" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.fields.phone')" :error="fieldError('applicant.phone')" optional>
                    <input :id="id" v-model="form.phone" type="tel" inputmode="tel" dir="ltr" class="field-input" maxlength="30" autocomplete="off" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.fields.email')" :error="fieldError('applicant.email')" optional>
                    <input :id="id" v-model="form.email" type="email" dir="ltr" class="field-input" maxlength="190" autocomplete="off" />
                </AppField>
            </fieldset>
            <OwnFields v-model="extra" :fields="fields" :errors="errors" prefix="applicant.extra" />

            <section>
                <h3 class="mb-2 text-[13px] font-semibold text-fg">{{ t('education.admission.guardians') }}</h3>
                <GuardianFields v-if="guardiansEditable" v-model="guardians" :errors="errors" prefix="applicant.guardians" :links="false" />
                <p v-else class="rounded-xl bg-subtle px-4 py-3 text-[12.5px] text-muted">{{ t('education.student.sensitive_hidden') }}</p>
            </section>

            <AppField v-slot="{ id }" :label="t('education.application_form.note')" :error="fieldError('note')" optional>
                <textarea :id="id" v-model="form.note" rows="2" class="field-input" maxlength="500" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="education-application" :loading="saving">{{ t('education.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
