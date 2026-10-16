<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Plus, Sparkles, Trash2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feedbackApi } from '../api';
import { useFeedbackSetup } from '../setup';

/**
 * Making or changing a feedback round: name, session, dates and the
 * questions (Bangla and English; the ready-made ones to start with). Once a
 * round is open only its last day can move; the server checks that too.
 */
const props = defineProps({ open: Boolean, round: { type: Object, default: null } });
const emit = defineEmits(['close', 'saved', 'conflict']);
const api = feedbackApi(currentOrganization().id);
const setup = useFeedbackSetup();

const form = reactive({ name: '', session_id: '', opens_on: '', closes_on: '', questions: [] });
const errors = ref({});
const busy = ref(false);
const isOpen = computed(() => props.round?.status === 'open');

watch(() => props.open, (open) => {
    if (!open) return;
    errors.value = {};
    const round = props.round;
    const session = setup.sessions.value.find((item) => item.status === 'open') ?? setup.sessions.value[0];
    Object.assign(form, round
        ? { name: round.name, session_id: round.session_id, opens_on: round.opens_on, closes_on: round.closes_on, questions: round.question_texts.map((text) => ({ bn: text.bn ?? '', en: text.en ?? '' })) }
        : { name: '', session_id: session?.id ?? '', opens_on: setup.data.value?.today ?? '', closes_on: '', questions: (setup.data.value?.default_questions ?? []).map((text) => ({ ...text })) });
});

const addQuestion = () => form.questions.push({ bn: '', en: '' });
const ready = computed(() => form.name.trim().length >= 3 && form.session_id && form.opens_on && form.closes_on && form.closes_on >= form.opens_on
    && form.questions.length > 0 && form.questions.every((question) => question.bn.trim().length >= 2 || question.en.trim().length >= 2));

async function save() {
    busy.value = true;
    errors.value = {};
    // Each question in the languages filled in.
    const questions = form.questions.map((question) => Object.fromEntries(Object.entries(question).map(([lang, text]) => [lang, text.trim()]).filter(([, text]) => text.length >= 2)));
    try {
        const { data } = !props.round
            ? await api.create({ name: form.name.trim(), session_id: form.session_id, opens_on: form.opens_on, closes_on: form.closes_on, questions })
            : await api.update(props.round.id, isOpen.value
                ? { closes_on: form.closes_on, base_version: props.round.version }
                : { name: form.name.trim(), opens_on: form.opens_on, closes_on: form.closes_on, questions, base_version: props.round.version });
        toast.success(t('teacher_feedback.dialog.saved'));
        emit('saved', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('teacher_feedback.conflict'));
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
    <AppDialog :open="open" size="lg" :icon="Sparkles" :title="round ? t('teacher_feedback.dialog.edit_title') : t('teacher_feedback.dialog.title')" :description="isOpen ? t('teacher_feedback.dialog.open_text') : t('teacher_feedback.dialog.text')" @close="emit('close')">
        <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="ready && save()">
            <AppField v-slot="{ id }" class="sm:col-span-2" :label="t('teacher_feedback.fields.name')" :error="errors.name?.[0] ?? null">
                <input :id="id" v-model="form.name" class="field-input" maxlength="100" :disabled="isOpen" :placeholder="t('teacher_feedback.fields.name_hint')" />
            </AppField>
            <AppField v-slot="{ id }" class="sm:col-span-2" :label="t('teacher_feedback.fields.session')" :error="errors.session_id?.[0] ?? null">
                <select :id="id" v-model="form.session_id" class="field-input" :disabled="!!round">
                    <option v-for="session in setup.sessions.value" :key="session.id" :value="session.id">{{ textIn(session.name) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('teacher_feedback.fields.opens_on')" :error="errors.opens_on?.[0] ?? null">
                <input :id="id" v-model="form.opens_on" type="date" class="field-input" :disabled="isOpen" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('teacher_feedback.fields.closes_on')" :error="errors.closes_on?.[0] ?? (form.closes_on && form.closes_on < form.opens_on ? t('teacher_feedback.fields.dates') : null)">
                <input :id="id" v-model="form.closes_on" type="date" class="field-input" :min="form.opens_on" />
            </AppField>

            <fieldset class="sm:col-span-2" :disabled="isOpen">
                <legend class="mb-2 text-[13px] font-medium text-fg">{{ t('teacher_feedback.fields.questions') }}</legend>
                <p v-if="errors.questions?.[0]" class="mb-2 text-[12.5px] text-bad">{{ errors.questions[0] }}</p>
                <ol class="space-y-2">
                    <li v-for="(question, index) in form.questions" :key="index" class="flex items-start gap-2">
                        <span class="tabular mt-2.5 w-5 shrink-0 text-end text-[12.5px] text-muted">{{ index + 1 }}.</span>
                        <div class="grid min-w-0 flex-1 gap-2 sm:grid-cols-2">
                            <label>
                                <span class="sr-only">{{ t('teacher_feedback.fields.question_bn', { number: index + 1 }) }}</span>
                                <input v-model="question.bn" class="field-input" lang="bn" maxlength="200" :placeholder="t('teacher_feedback.fields.in_bn')" />
                            </label>
                            <label>
                                <span class="sr-only">{{ t('teacher_feedback.fields.question_en', { number: index + 1 }) }}</span>
                                <input v-model="question.en" class="field-input" lang="en" maxlength="200" :placeholder="t('teacher_feedback.fields.in_en')" />
                            </label>
                        </div>
                        <AppButton v-if="!isOpen && form.questions.length > 1" size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('teacher_feedback.fields.remove_question', { number: index + 1 })" @click="form.questions.splice(index, 1)" />
                    </li>
                </ol>
                <AppButton v-if="!isOpen && form.questions.length < 12" size="sm" variant="ghost" :icon="Plus" class="mt-2" @click="addQuestion">{{ t('teacher_feedback.fields.add_question') }}</AppButton>
            </fieldset>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('teacher_feedback.cancel') }}</AppButton>
            <AppButton variant="primary" :loading="busy" :disabled="!ready" @click="save">{{ t('teacher_feedback.dialog.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
