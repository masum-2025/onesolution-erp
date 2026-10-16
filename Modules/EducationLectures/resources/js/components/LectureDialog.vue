<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { BookOpen } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { lecturesApi } from '../api';
import { useLecturesSetup } from '../setup';
import { looksLikeVideo } from '../lib';

/**
 * Posting a lecture (or changing one): the section and subject (only the
 * ones the person teaches), the day, a title, the text and a video link.
 * Files are added on the lecture itself once it exists. Saved as a draft;
 * publishing is its own step.
 */
const props = defineProps({
    open: Boolean,
    // The lecture to change; null to post a new one.
    lecture: { type: Object, default: null },
    // A section and subject to start with (from the list's filter).
    start: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'saved', 'conflict']);
const api = lecturesApi(currentOrganization().id);
const setup = useLecturesSetup();

const form = reactive({ section_id: '', subject_id: '', lesson_on: '', title: '', body: '', video_url: '' });
const errors = ref({});
const busy = ref(false);
let opId = '';

watch(() => props.open, (open) => {
    if (!open) return;
    errors.value = {};
    opId = crypto.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
    const lecture = props.lecture;
    const first = setup.postable.value.find((section) => section.id === props.start.section_id) ?? setup.postable.value[0];
    Object.assign(form, lecture
        ? { section_id: lecture.section_id, subject_id: lecture.subject_id, lesson_on: lecture.lesson_on, title: lecture.title, body: lecture.body ?? '', video_url: lecture.video_url ?? '' }
        : { section_id: first?.id ?? '', subject_id: '', lesson_on: setup.today.value, title: '', body: '', video_url: '' });
    if (!lecture) {
        const subjects = first?.subjects ?? [];
        form.subject_id = subjects.find((subject) => subject.id === props.start.subject_id)?.id ?? (subjects.length === 1 ? subjects[0].id : '');
    }
});

const section = computed(() => setup.postable.value.find((item) => item.id === form.section_id));
const subjects = computed(() => section.value?.subjects ?? []);
watch(() => form.section_id, () => {
    if (!props.lecture && !subjects.value.some((subject) => subject.id === form.subject_id)) {
        form.subject_id = subjects.value.length === 1 ? subjects.value[0].id : '';
    }
});
const videoBad = computed(() => !looksLikeVideo(form.video_url.trim()));
const ready = computed(() => form.section_id && form.subject_id && form.lesson_on && form.title.trim().length >= 3 && !videoBad.value);

async function save() {
    busy.value = true;
    errors.value = {};
    const body = { lesson_on: form.lesson_on, title: form.title.trim(), body: form.body.trim() || null, video_url: form.video_url.trim() || null };
    try {
        const { data } = props.lecture
            ? await api.update(props.lecture.id, { ...body, base_version: props.lecture.version })
            : await api.create({ ...body, section_id: form.section_id, subject_id: form.subject_id, op_id: opId });
        toast.success(t(props.lecture ? 'education_lectures.dialog.changed' : 'education_lectures.dialog.saved'));
        emit('saved', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('education_lectures.conflict'));
            emit('conflict');
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" size="lg" :icon="BookOpen" :title="lecture ? t('education_lectures.dialog.edit_title') : t('education_lectures.dialog.title')" :description="t('education_lectures.dialog.text')" @close="emit('close')">
        <p v-if="!lecture && !setup.postable.value.length" class="rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn">{{ t('education_lectures.dialog.nothing_to_post') }}</p>
        <form v-else class="grid gap-4 sm:grid-cols-2" @submit.prevent="ready && save()">
            <AppField v-slot="{ id }" :label="t('education_lectures.fields.section')" :error="errors.section_id?.[0] ?? null">
                <select :id="id" v-model="form.section_id" class="field-input" :disabled="!!lecture">
                    <option v-for="item in lecture ? setup.sections.value : setup.postable.value" :key="item.id" :value="item.id">{{ textIn(item.level) }} · {{ item.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_lectures.fields.subject')" :error="errors.subject_id?.[0] ?? null">
                <select :id="id" v-model="form.subject_id" class="field-input" :disabled="!!lecture">
                    <option value="" disabled>{{ t('education_lectures.fields.subject_pick') }}</option>
                    <option v-for="item in lecture ? (setup.sections.value.find((s) => s.id === form.section_id)?.subjects ?? []) : subjects" :key="item.id" :value="item.id">{{ textIn(item.name) }} ({{ item.code }})</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_lectures.fields.lesson_on')" :error="errors.lesson_on?.[0] ?? null">
                <input :id="id" v-model="form.lesson_on" type="date" class="field-input" required />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_lectures.fields.title')" :error="errors.title?.[0] ?? null">
                <input :id="id" v-model="form.title" class="field-input" maxlength="150" required :placeholder="t('education_lectures.fields.title_hint')" />
            </AppField>
            <AppField v-slot="{ id, describedby }" class="sm:col-span-2" :label="t('education_lectures.fields.body')" :hint="t('education_lectures.fields.body_hint')" :error="errors.body?.[0] ?? null" optional>
                <textarea :id="id" v-model="form.body" class="field-input min-h-36" maxlength="10000" :aria-describedby="describedby" />
            </AppField>
            <AppField
                v-slot="{ id, describedby }"
                class="sm:col-span-2"
                :label="t('education_lectures.fields.video_url')"
                :hint="(setup.rules.value.video_sites ?? []).length ? t('education_lectures.fields.video_hint', { sites: setup.rules.value.video_sites.map((site) => t(`education_lectures.sites.${site}`)).join(', ') }) : t('education_lectures.fields.video_off')"
                :error="(videoBad ? t('education_lectures.fields.video_https') : null) ?? errors.video_url?.[0] ?? null"
                optional
            >
                <input :id="id" v-model="form.video_url" type="url" inputmode="url" class="field-input" dir="ltr" maxlength="500" placeholder="https://" :aria-describedby="describedby" :disabled="!(setup.rules.value.video_sites ?? []).length" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education_lectures.cancel') }}</AppButton>
            <AppButton v-if="lecture || setup.postable.value.length" variant="primary" :loading="busy" :disabled="!ready" @click="save">{{ lecture ? t('education_lectures.dialog.change') : t('education_lectures.dialog.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
