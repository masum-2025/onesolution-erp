<script setup>
import { computed, ref, watch } from 'vue';
import { FilePen } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { readCell } from '../lib';
import { marksShown, partName } from '../labels';

/**
 * Asking to change a published mark: the new marks (or "a" for absent)
 * and why. Someone else approves it when the rule asks for that; the
 * results are worked out again once it is applied.
 */
const props = defineProps({
    open: Boolean,
    sheetId: { type: String, required: true },
    student: { type: Object, default: null },
    part: { type: Object, default: null },
    current: { type: Object, default: null }, // { marks_centi, absent }
});
const emit = defineEmits(['close', 'done']);
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();

const text = ref('');
const reason = ref('');
const saving = ref(false);
watch(() => props.open, (open) => {
    if (open) {
        text.value = '';
        reason.value = '';
    }
});
const cell = computed(() => readCell(text.value, props.part?.full_marks_centi ?? 0));
const same = computed(() => (cell.value.kind === 'absent' && props.current?.absent) || (cell.value.kind === 'marks' && !props.current?.absent && cell.value.centi === props.current?.marks_centi));
const ready = computed(() => ['marks', 'absent'].includes(cell.value.kind) && !same.value && reason.value.trim().length >= 3);
const error = computed(() => {
    if (cell.value.kind === 'invalid') return t(`education_exams.entry.invalid_${cell.value.reason}`, { full: marksShown(props.part?.full_marks_centi) });
    if (same.value) return t('education_exams.corrections.same');
    return null;
});
const was = computed(() => (props.current?.absent ? t('education_exams.entry.absent') : marksShown(props.current?.marks_centi)));

async function save() {
    saving.value = true;
    try {
        const { data } = await api.requestCorrection(props.sheetId, {
            student_id: props.student.id, part_id: props.part.id, reason: reason.value.trim(),
            ...(cell.value.kind === 'absent' ? { absent: true } : { marks_centi: cell.value.centi, absent: false }),
        });
        toast.success(t(data.status === 'applied' ? 'education_exams.corrections.applied_now' : 'education_exams.corrections.asked'));
        emit('done', data);
    } catch (caught) {
        toast.error(caught.errors?.reason?.[0] ?? caught.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('education_exams.corrections.ask_title')" :description="t(setup.rules.value.correction_needs_approval ? 'education_exams.corrections.ask_text' : 'education_exams.corrections.ask_text_now')" :icon="FilePen" size="sm" @close="emit('close')">
        <form id="correction-form" class="space-y-4" novalidate @submit.prevent="ready && save()">
            <p class="text-[13.5px] text-fg">
                <strong class="font-semibold">{{ student?.name }}</strong>
                <span class="text-muted"> · {{ partName(part) }} · {{ t('education_exams.corrections.now', { marks: was }) }}</span>
            </p>
            <AppField v-slot="{ id, describedby }" :label="t('education_exams.corrections.new_marks')" :hint="t('education_exams.corrections.new_marks_hint', { full: marksShown(part?.full_marks_centi) })" :error="error">
                <input :id="id" v-model="text" inputmode="decimal" autocomplete="off" class="field-input tabular w-32" dir="ltr" :aria-describedby="describedby" autofocus />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_exams.corrections.why')">
                <textarea :id="id" v-model="reason" rows="3" maxlength="300" class="field-input" :placeholder="t('education_exams.corrections.why_placeholder')" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education_exams.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="correction-form" :loading="saving" :disabled="!ready">{{ t('education_exams.corrections.ask') }}</AppButton>
        </template>
    </AppDialog>
</template>
