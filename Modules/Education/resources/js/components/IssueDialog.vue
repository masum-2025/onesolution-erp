<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { FileBadge } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { confirmAction } from '@/lib/dialogs';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { newOpId } from '../lib';

/**
 * Issue a document with an active design to one student or many (a whole
 * section's ID cards): the design, its questions and the date on it. A
 * student who already has a valid ID card is asked about once: replacing
 * revokes the old card. All or nothing; sent with an op id, so pressing
 * twice never issues twice.
 */
const props = defineProps({
    open: Boolean,
    students: { type: Array, default: () => [] }, // [{ id, name }]
    kind: { type: String, default: null }, // only designs of this kind
    education: { type: Object, required: true },
});
const emit = defineEmits(['close', 'issued']);

const templates = ref([]);
const loading = ref(false);
const form = reactive({ template_id: '', issued_on: '', inputs: {} });
const errors = ref({});
const saving = ref(false);
let opId = null;

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        Object.assign(form, { template_id: '', issued_on: new Date().toISOString().slice(0, 10), inputs: {} });
        errors.value = {};
        opId = newOpId();
        loading.value = true;
        try {
            templates.value = (await props.education.templates({ status: 'active', ...(props.kind ? { kind: props.kind } : {}) })).data;
            if (templates.value.length === 1) form.template_id = templates.value[0].id;
        } catch {
            templates.value = [];
        } finally {
            loading.value = false;
        }
    },
);

const template = computed(() => templates.value.find((item) => item.id === form.template_id) ?? null);

async function submit(replace = false) {
    saving.value = true;
    errors.value = {};
    try {
        const result = await props.education.issue({
            template_id: form.template_id,
            student_ids: props.students.map((student) => student.id),
            inputs: form.inputs,
            issued_on: form.issued_on || null,
            replace,
            op_id: opId,
        });
        toast.success(t('education.issue.done', { count: result.data.length }));
        emit('issued', result.data);
    } catch (error) {
        if (error.code === 'document_exists' && !replace) {
            saving.value = false;
            const student = props.students.find((item) => item.id === error.data?.student_id);
            const confirmed = await confirmAction({
                title: t('education.issue.replace_title'),
                message: t('education.issue.replace_text', { name: student?.name ?? '', number: error.data?.number ?? '' }),
                confirmLabel: t('education.issue.replace_confirm'),
                danger: true,
            });
            if (confirmed) await submit(true);
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
        :title="students.length > 1 ? t('education.issue.title_many', { count: students.length }) : t('education.issue.title')"
        :description="students.length === 1 ? students[0].name : ''"
        :icon="FileBadge"
        @close="emit('close')"
    >
        <p v-if="!loading && !templates.length" class="rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn">{{ t('education.issue.no_designs') }}</p>
        <form v-else id="education-issue" class="space-y-4" novalidate @submit.prevent="submit()">
            <AppField v-slot="{ id }" :label="t('education.issue.design')" :error="fieldError('template_id')">
                <select :id="id" v-model="form.template_id" class="field-input" :disabled="loading">
                    <option value="" disabled>—</option>
                    <option v-for="item in templates" :key="item.id" :value="item.id">{{ item.name_text }} · {{ t(`education.document_kinds.${item.kind}`) }}</option>
                </select>
            </AppField>
            <AppField v-for="input in template?.inputs ?? []" :key="input.key" v-slot="{ id }" :label="textIn(input.label)" :optional="!input.required" :error="fieldError(`inputs.${input.key}`)">
                <textarea v-if="input.multiline" :id="id" v-model="form.inputs[input.key]" rows="3" class="field-input" maxlength="1000" />
                <input v-else :id="id" v-model="form.inputs[input.key]" class="field-input" maxlength="1000" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.issue.issued_on')" :error="fieldError('issued_on')" class="sm:w-56">
                <input :id="id" v-model="form.issued_on" type="date" class="field-input" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="education-issue" :loading="saving" :disabled="!form.template_id">{{ t('education.issue.print_now') }}</AppButton>
        </template>
    </AppDialog>
</template>
