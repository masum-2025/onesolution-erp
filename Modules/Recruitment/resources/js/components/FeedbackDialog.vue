<script setup>
import { reactive, ref, watch } from 'vue';
import { Star } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/** An interviewer's own feedback on a candidate: a score of 1 to 5, yes / maybe / no, and a note. Given again, it replaces theirs. */
const props = defineProps({
    open: { type: Boolean, default: false },
    interview: { type: Object, default: null },
    // the person interviewed (for the heading)
    name: { type: String, default: '' },
    // one's feedback given before, to change it
    given: { type: Object, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);

const form = reactive({ score: 0, recommendation: '', note: '' });
const errors = ref({});
const saving = ref(false);

watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, { score: props.given?.score ?? 0, recommendation: props.given?.recommendation ?? '', note: props.given?.note ?? '' });
    errors.value = {};
});

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await props.calls.feedback(props.interview.id, { score: form.score, recommendation: form.recommendation, note: form.note.trim() || null });
        toast.success(t('recruitment.feedback.saved'));
        emit('saved');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('recruitment.feedback.title')" :description="t('recruitment.feedback.text', { name })" :icon="Star" @close="emit('close')">
        <form id="recruitment-feedback" class="space-y-4" novalidate @submit.prevent="save">
            <fieldset>
                <legend class="mb-2 text-[13px] font-medium text-fg-2">{{ t('recruitment.feedback.score') }}</legend>
                <div class="flex gap-1" role="radiogroup" :aria-label="t('recruitment.feedback.score')">
                    <button
                        v-for="value in 5"
                        :key="value"
                        type="button"
                        role="radio"
                        :aria-checked="form.score === value"
                        :aria-label="t('recruitment.feedback.stars', { count: value })"
                        class="grid size-10 place-items-center rounded-lg hover:bg-subtle"
                        @click="form.score = value"
                    >
                        <Star class="size-6" :class="value <= form.score ? 'fill-warn text-warn' : 'text-muted'" aria-hidden="true" />
                    </button>
                </div>
                <p v-if="errors.score" class="mt-1 text-[12.5px] text-bad" role="alert">{{ errors.score[0] }}</p>
            </fieldset>
            <div>
                <p class="mb-2 text-[13px] font-medium text-fg-2">{{ t('recruitment.feedback.recommendation') }}</p>
                <AppSegmented v-model="form.recommendation" block :label="t('recruitment.feedback.recommendation')" :options="['yes', 'maybe', 'no'].map((value) => ({ value, label: t(`recruitment.recommendations.${value}`) }))" />
            </div>
            <AppField v-slot="{ id }" :label="t('recruitment.feedback.note')" :hint="t('recruitment.feedback.note_hint')" optional>
                <textarea :id="id" v-model="form.note" rows="4" class="field-input" maxlength="3000"></textarea>
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('recruitment.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="recruitment-feedback" :loading="saving" :disabled="!form.score || !form.recommendation">{{ t('recruitment.feedback.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
