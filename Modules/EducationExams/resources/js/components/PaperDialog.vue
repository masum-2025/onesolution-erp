<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { FileText, Plus, Trash2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import { currentOrganization } from '@/lib/session';
import { languageName, t } from '@/lib/i18n';
import { cleanTexts, textIn, textLocales, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { centiText, parseCenti } from '../lib';

/**
 * A paper of an exam: a subject for a class, its full and pass marks, when
 * and where it sits, and parts that add up to the full marks (creative 70 +
 * MCQ 30). The class and subject are fixed once made; a paper with marks
 * keeps its full marks and parts (the server says so).
 */
const props = defineProps({ open: Boolean, examId: { type: String, required: true }, paper: { type: Object, default: null } });
const emit = defineEmits(['close', 'saved']);
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();

const KINDS = ['compulsory', 'elective', 'optional'];
const form = reactive({ level_id: '', subject_id: '', kind: 'compulsory', full: '', pass: '', sits_on: '', starts_at: '', minutes: '', room: '', split: false, parts: [] });
const errors = ref({});
const saving = ref(false);
const editing = computed(() => Boolean(props.paper?.id));
const blankPart = () => ({ code: '', name: textsFor({}), full: '', pass: '' });

watch(() => props.open, (open) => {
    if (!open) return;
    const paper = props.paper;
    const parts = paper?.parts ?? [];
    Object.assign(form, {
        level_id: paper?.level_id ?? setup.data.value?.levels[0]?.id ?? '',
        subject_id: paper?.subject_id ?? '',
        kind: paper?.kind ?? 'compulsory',
        full: paper ? centiText(paper.full_marks_centi) : '100',
        pass: paper ? centiText(paper.pass_marks_centi) : '',
        sits_on: paper?.sits_on ?? '',
        starts_at: paper?.starts_at?.slice(0, 5) ?? '',
        minutes: paper?.minutes ?? '',
        room: paper?.room ?? '',
        split: parts.length > 1,
        parts: parts.length > 1
            ? parts.map((part) => ({ code: part.code, name: textsFor(part.name), full: centiText(part.full_marks_centi), pass: centiText(part.pass_marks_centi) }))
            : [blankPart(), blankPart()],
    });
    errors.value = {};
});

const fullCenti = computed(() => parseCenti(form.full));
const passCenti = computed(() => (form.pass === '' ? null : parseCenti(form.pass)));
const partsSum = computed(() => form.parts.reduce((sum, part) => sum + (parseCenti(part.full) ?? 0), 0));
const partsValid = computed(() => !form.split || (form.parts.length >= 2
    && form.parts.every((part) => /^[A-Za-z0-9_-]{1,12}$/.test(part.code.trim()) && parseCenti(part.full) && (part.pass === '' || parseCenti(part.pass) !== null))
    && new Set(form.parts.map((part) => part.code.trim().toUpperCase())).size === form.parts.length
    && partsSum.value === fullCenti.value));
const ready = computed(() => form.level_id && form.subject_id && fullCenti.value >= 100 && (passCenti.value === null || passCenti.value <= fullCenti.value) && partsValid.value);
const error = (field) => errors.value[field]?.[0] ?? null;
const sumText = computed(() => t('education_exams.paper.parts_sum', { sum: centiText(partsSum.value), full: centiText(fullCenti.value ?? 0) }));

function partsPayload() {
    if (!form.split) {
        // One part again: the paper's whole marks (the server keeps a single part at the paper's full marks).
        return editing.value && props.paper.parts.length > 1 ? [{ code: 'TOTAL', name: null, full_marks_centi: fullCenti.value, pass_marks_centi: null }] : undefined;
    }
    return form.parts.map((part) => ({
        code: part.code.trim(), name: Object.keys(cleanTexts(part.name)).length ? cleanTexts(part.name) : null,
        full_marks_centi: parseCenti(part.full), pass_marks_centi: part.pass === '' ? null : parseCenti(part.pass),
    }));
}

async function save() {
    saving.value = true;
    errors.value = {};
    const body = {
        kind: form.kind, full_marks_centi: fullCenti.value, ...(passCenti.value === null ? {} : { pass_marks_centi: passCenti.value }),
        sits_on: form.sits_on || null, starts_at: form.starts_at || null, minutes: form.minutes === '' ? null : Number(form.minutes), room: form.room.trim() || null,
    };
    const parts = partsPayload();
    if (parts) body.parts = parts;
    try {
        const { data } = editing.value
            ? await api.updatePaper(props.examId, props.paper.id, { ...body, base_version: props.paper.version })
            : await api.createPaper(props.examId, { ...body, level_id: form.level_id, subject_id: form.subject_id });
        toast.success(t('education_exams.paper.saved'));
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
    <AppDialog :open="open" :title="t(editing ? 'education_exams.paper.edit' : 'education_exams.paper.new')" :description="t('education_exams.paper.form_text')" :icon="FileText" size="lg" @close="emit('close')">
        <form id="paper-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="ready && save()">
            <AppField v-slot="{ id }" :label="t('education_exams.paper.level')" :error="error('level_id')">
                <select :id="id" v-model="form.level_id" class="field-input" :disabled="editing">
                    <option v-for="level in setup.data.value?.levels ?? []" :key="level.id" :value="level.id">{{ setup.levelName(level.id) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_exams.paper.subject')" :error="error('subject_id')">
                <select :id="id" v-model="form.subject_id" class="field-input" :disabled="editing">
                    <option value="" disabled>{{ t('education_exams.paper.pick_subject') }}</option>
                    <option v-for="subject in setup.data.value?.subjects ?? []" :key="subject.id" :value="subject.id">{{ textIn(subject.name) }} ({{ subject.code }})</option>
                </select>
            </AppField>
            <AppField v-slot="{ id, describedby }" :label="t('education_exams.paper.kind')" :hint="t(`education_exams.paper_kinds_hint.${form.kind}`)" :error="error('kind')">
                <select :id="id" v-model="form.kind" class="field-input" :aria-describedby="describedby">
                    <option v-for="kind in KINDS" :key="kind" :value="kind">{{ t(`education_exams.paper_kinds.${kind}`) }}</option>
                </select>
            </AppField>
            <div class="grid grid-cols-2 gap-4">
                <AppField v-slot="{ id }" :label="t('education_exams.paper.full')" :error="error('full_marks_centi') ?? (form.full && !(fullCenti >= 100) ? t('education_exams.paper.full_invalid') : null)">
                    <input :id="id" v-model="form.full" inputmode="decimal" class="field-input tabular" dir="ltr" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education_exams.paper.pass')" :error="error('pass_marks_centi') ?? (passCenti !== null && fullCenti && passCenti > fullCenti ? t('education_exams.paper.pass_too_high') : null)" optional>
                    <input :id="id" v-model="form.pass" inputmode="decimal" class="field-input tabular" dir="ltr" />
                </AppField>
            </div>
            <AppField v-slot="{ id }" :label="t('education_exams.paper.sits_on')" :error="error('sits_on')" optional>
                <input :id="id" v-model="form.sits_on" type="date" class="field-input" />
            </AppField>
            <div class="grid grid-cols-2 gap-4">
                <AppField v-slot="{ id }" :label="t('education_exams.paper.starts_at')" :error="error('starts_at')" optional>
                    <input :id="id" v-model="form.starts_at" type="time" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education_exams.paper.minutes')" :error="error('minutes')" optional>
                    <input :id="id" v-model="form.minutes" type="number" min="5" max="600" class="field-input tabular" />
                </AppField>
            </div>
            <AppField v-slot="{ id }" :label="t('education_exams.paper.room')" :error="error('room')" optional>
                <input :id="id" v-model="form.room" maxlength="40" class="field-input" />
            </AppField>

            <div class="sm:col-span-2">
                <div class="flex items-start justify-between gap-4 rounded-lg bg-subtle/60 px-4 py-3">
                    <span>
                        <span class="block text-[13.5px] font-medium text-fg">{{ t('education_exams.paper.split') }}</span>
                        <span class="block text-[12.5px] text-muted">{{ t('education_exams.paper.split_text') }}</span>
                    </span>
                    <AppSwitch v-model="form.split" :label="t('education_exams.paper.split')" />
                </div>
            </div>
            <fieldset v-if="form.split" class="space-y-3 sm:col-span-2">
                <legend class="sr-only">{{ t('education_exams.paper.parts') }}</legend>
                <div v-for="(part, index) in form.parts" :key="index" class="grid grid-cols-2 gap-3 rounded-lg border border-line p-3 sm:grid-cols-[6rem_1fr_6rem_6rem_auto]">
                    <AppField v-slot="{ id }" :label="t('education_exams.paper.part_code')">
                        <input :id="id" v-model="part.code" maxlength="12" class="field-input font-mono uppercase" dir="ltr" :placeholder="index === 0 ? 'CQ' : 'MCQ'" />
                    </AppField>
                    <AppField :label="t('education_exams.paper.part_name')" optional class="col-span-2 sm:col-span-1">
                        <div class="grid gap-2" :class="textLocales().length > 1 ? 'sm:grid-cols-2' : ''">
                            <input v-for="locale in textLocales()" :key="locale" v-model="part.name[locale]" maxlength="120" class="field-input" :aria-label="`${t('education_exams.paper.part_name')} (${languageName(locale)})`" :placeholder="languageName(locale)" />
                        </div>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education_exams.paper.full')">
                        <input :id="id" v-model="part.full" inputmode="decimal" class="field-input tabular" dir="ltr" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education_exams.paper.pass')">
                        <input :id="id" v-model="part.pass" :placeholder="t('education_exams.paper.part_pass_none')" inputmode="decimal" class="field-input tabular" dir="ltr" />
                    </AppField>
                    <div class="flex items-end">
                        <AppButton size="icon" variant="ghost" :icon="Trash2" :disabled="form.parts.length <= 2" :aria-label="t('education_exams.paper.remove_part')" @click="form.parts.splice(index, 1)" />
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <AppButton size="sm" :icon="Plus" :disabled="form.parts.length >= 6" @click="form.parts.push(blankPart())">{{ t('education_exams.paper.add_part') }}</AppButton>
                    <p class="tabular text-[12.5px]" :class="partsSum === fullCenti ? 'text-muted' : 'font-medium text-bad'" role="status">{{ sumText }}</p>
                </div>
                <p v-if="error('parts')" class="text-[12.5px] text-bad">{{ error('parts') }}</p>
            </fieldset>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education_exams.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="paper-form" :loading="saving" :disabled="!ready">{{ t('education_exams.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
