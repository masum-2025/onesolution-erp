<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { BookPlus } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { registrationApi } from '../api';
import { useRegistrationSetup } from '../setup';
import { credits } from '../lib';

/**
 * A subject offered in the session (made), or an offering changed: group,
 * kind, seats, credits, teacher, status, note. Subject, session and campus
 * stay once made.
 */
const props = defineProps({
    open: Boolean,
    offering: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved', 'conflict']);

const org = currentOrganization();
const api = registrationApi(org.id);
const setup = useRegistrationSetup();
const editing = computed(() => props.offering !== null);

const form = reactive({});
const errors = ref({});
const saving = ref(false);
const search = ref('');

watch(() => props.open, (open) => {
    if (!open) return;
    const offering = props.offering;
    const campuses = setup.data.value?.campuses ?? [];
    Object.assign(form, {
        subject_id: offering?.subject_id ?? '',
        level_id: offering?.level_id ?? '',
        unit_id: offering?.unit_id ?? (campuses.find((campus) => campus.id === org.id)?.id ?? campuses[0]?.id ?? ''),
        group_name: offering?.group_name ?? 'A',
        kind: offering?.kind ?? 'elective',
        capacity: offering?.capacity ?? 40,
        credits: offering ? credits(offering.credits_centi) : '',
        teacher_id: offering?.teacher_id ?? '',
        status: offering?.status ?? 'open',
        note: offering?.note ?? '',
    });
    search.value = '';
    errors.value = {};
});

const subjects = computed(() => {
    const term = search.value.trim().toLowerCase();
    const all = setup.data.value?.subjects ?? [];
    return term ? all.filter((subject) => subject.code.toLowerCase().includes(term) || textIn(subject.name).toLowerCase().includes(term)) : all;
});
const chosen = computed(() => (setup.data.value?.subjects ?? []).find((subject) => subject.id === form.subject_id) ?? null);
const levels = computed(() => (setup.data.value?.levels ?? []).filter((level) => level.program.progression === setup.session.value?.kind));
const centi = (text) => (String(text ?? '').trim() === '' ? undefined : Math.round(Number(text) * 100));

async function save() {
    saving.value = true;
    errors.value = {};
    const body = {
        level_id: form.level_id || null,
        group_name: form.group_name.trim(),
        kind: form.kind,
        capacity: Number(form.capacity),
        teacher_id: form.teacher_id || null,
        note: form.note.trim() || null,
        ...(centi(form.credits) !== undefined ? { credits_centi: centi(form.credits) } : {}),
    };
    try {
        const { data } = editing.value
            ? await api.updateOffering(props.offering.id, { ...body, status: form.status, base_version: props.offering.version })
            : await api.createOffering({ ...body, session_id: setup.sessionId.value, subject_id: form.subject_id, unit_id: form.unit_id });
        toast.success(t('course_registration.offering_form.saved'));
        emit('saved', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('course_registration.conflict'));
            emit('conflict');
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog
        :open="open"
        :title="editing ? t('course_registration.offering_form.edit_title', { subject: offering.subject?.code ?? '', group: offering.group_name }) : t('course_registration.offering_form.add_title')"
        :description="setup.sessionName(setup.sessionId.value)"
        :icon="BookPlus"
        size="lg"
        @close="emit('close')"
    >
        <form id="crs-offering" class="space-y-4" novalidate @submit.prevent="save">
            <AppField v-if="!editing" v-slot="{ id }" :label="t('course_registration.offering_form.subject')" :error="fieldError('subject_id')">
                <div class="space-y-2">
                    <input v-model="search" type="search" class="field-input" :placeholder="t('course_registration.offering_form.subject_search')" autocomplete="off" />
                    <select :id="id" v-model="form.subject_id" class="field-input" size="6">
                        <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.code }} · {{ textIn(subject.name) }} ({{ credits(subject.credits_centi) }})</option>
                    </select>
                </div>
            </AppField>
            <p v-else class="rounded-xl bg-subtle px-3 py-2 text-[13px] text-fg-2">
                <span class="font-mono" dir="ltr">{{ offering.subject?.code }}</span> · {{ textIn(offering.subject?.name) }} · {{ setup.campusName(offering.unit_id) }}
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('course_registration.offering_form.level')" :error="fieldError('level_id')" optional>
                    <select :id="id" v-model="form.level_id" class="field-input">
                        <option value="">{{ t('course_registration.offering_form.any_level') }}</option>
                        <option v-for="level in levels" :key="level.id" :value="level.id">{{ setup.levelText(level.id) }}</option>
                    </select>
                </AppField>
                <AppField v-if="!editing && (setup.data.value?.campuses?.length ?? 0) > 1" v-slot="{ id }" :label="t('course_registration.offering_form.campus')" :error="fieldError('unit_id')">
                    <select :id="id" v-model="form.unit_id" class="field-input">
                        <option v-for="campus in setup.data.value.campuses" :key="campus.id" :value="campus.id">{{ campus.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('course_registration.offering_form.group')" :hint="t('course_registration.offering_form.group_hint')" :error="fieldError('group_name')">
                    <input :id="id" v-model="form.group_name" class="field-input" maxlength="20" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('course_registration.offering_form.kind')" :error="fieldError('kind')">
                    <select :id="id" v-model="form.kind" class="field-input">
                        <option v-for="kind in ['compulsory', 'elective', 'optional']" :key="kind" :value="kind">{{ t(`course_registration.kinds.${kind}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('course_registration.offering_form.capacity')" :error="fieldError('capacity')">
                    <input :id="id" v-model="form.capacity" type="number" min="1" max="2000" class="field-input tabular" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('course_registration.offering_form.credits')" :hint="t('course_registration.offering_form.credits_hint')" :error="fieldError('credits_centi')" optional>
                    <input :id="id" v-model="form.credits" type="number" step="0.25" min="0" max="30" class="field-input tabular" :placeholder="chosen ? credits(chosen.credits_centi) : ''" />
                </AppField>
                <AppField v-slot="{ id, describedby }" :label="t('course_registration.offering_form.teacher')" :hint="setup.data.value?.hrm ? '' : t('course_registration.offering_form.teacher_hrm')" :error="fieldError('teacher_id')" optional>
                    <select :id="id" v-model="form.teacher_id" class="field-input" :disabled="!setup.data.value?.hrm" :aria-describedby="describedby">
                        <option value="">{{ t('course_registration.offering_form.teacher_none') }}</option>
                        <option v-for="teacher in setup.data.value?.teachers ?? []" :key="teacher.id" :value="teacher.id">{{ teacher.name }} ({{ teacher.code }})</option>
                    </select>
                </AppField>
                <AppField v-if="editing" v-slot="{ id }" :label="t('course_registration.offering_form.status')" :error="fieldError('status')">
                    <select :id="id" v-model="form.status" class="field-input">
                        <option v-for="status in ['open', 'closed', 'cancelled']" :key="status" :value="status">{{ t(`course_registration.offering_statuses.${status}`) }}</option>
                    </select>
                </AppField>
            </div>
            <AppField v-slot="{ id }" :label="t('course_registration.offering_form.note')" :error="fieldError('note')" optional>
                <input :id="id" v-model="form.note" class="field-input" maxlength="300" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('course_registration.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="crs-offering" :loading="saving" :disabled="!editing && !form.subject_id">{{ t('course_registration.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
