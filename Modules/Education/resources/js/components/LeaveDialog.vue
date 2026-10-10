<script setup>
import { reactive, ref, watch } from 'vue';
import { DoorOpen } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { confirmAction } from '@/lib/dialogs';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * A student leaves or graduates, with a reason kept in the record. Asked
 * once more before it is done: they leave their section from that day.
 */
const props = defineProps({
    open: Boolean,
    student: { type: Object, required: true },
    education: { type: Object, required: true },
});
const emit = defineEmits(['close', 'done', 'conflict']);

const form = reactive({ status: 'left', reason: '', on: '' });
const errors = ref({});
const saving = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        Object.assign(form, { status: 'left', reason: '', on: new Date().toISOString().slice(0, 10) });
        errors.value = {};
    },
);

async function submit() {
    const confirmed = await confirmAction({
        title: t('education.leave.confirm_title', { name: props.student.name }),
        message: t('education.leave.confirm_text'),
        confirmLabel: t('education.leave.confirm'),
        danger: form.status === 'left',
    });
    if (!confirmed) return;

    saving.value = true;
    errors.value = {};
    try {
        const { data } = await props.education.leave(props.student.id, { base_version: props.student.version, status: form.status, reason: form.reason.trim(), on: form.on || null });
        toast.success(t('education.leave.done', { name: data.name, status: t(`education.statuses.${data.status}`) }));
        emit('done', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('education.conflict'));
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
    <AppDialog :open="open" :title="t('education.leave.title', { name: student.name })" :description="student.code" :icon="DoorOpen" tone="warn" @close="emit('close')">
        <form id="education-leave" class="space-y-4" novalidate @submit.prevent="submit">
            <AppSegmented
                v-model="form.status"
                block
                :label="t('education.leave.kind')"
                :options="[{ value: 'left', label: t('education.leave.left') }, { value: 'graduated', label: t('education.leave.graduated') }]"
            />
            <AppField v-slot="{ id, invalid, describedby }" :label="t('education.leave.reason')" :hint="t('education.leave.reason_hint')" :error="fieldError('reason')">
                <textarea :id="id" v-model="form.reason" rows="3" class="field-input" maxlength="300" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.leave.on')" :error="fieldError('on')" class="sm:w-56">
                <input :id="id" v-model="form.on" type="date" class="field-input" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education.cancel') }}</AppButton>
            <AppButton :variant="form.status === 'left' ? 'danger' : 'primary'" type="submit" form="education-leave" :loading="saving" :disabled="form.reason.trim().length < 3">
                {{ t('education.leave.confirm') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
