<script setup>
import { reactive, ref, watch } from 'vue';
import { PenLine } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Ask to fix a day: the in and/or out time it should have had, and why. An
 * out time earlier than the in time is the next morning (a night shift).
 * For oneself (send = the "me" call) or, for HR, for the employee given.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    send: { type: Function, required: true },
    date: { type: String, default: '' },
    employeeName: { type: String, default: '' },
});
const emit = defineEmits(['close', 'sent']);

const form = reactive({ work_date: '', in_time: '', out_time: '', reason: '' });
const errors = ref({});
const saving = ref(false);

watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, { work_date: props.date, in_time: '', out_time: '', reason: '' });
    errors.value = {};
});

function nextDay(date) {
    const day = new Date(`${date}T00:00:00Z`);
    day.setUTCDate(day.getUTCDate() + 1);
    return day.toISOString().slice(0, 10);
}

async function save() {
    const outDate = form.in_time && form.out_time && form.out_time <= form.in_time ? nextDay(form.work_date) : form.work_date;
    saving.value = true;
    errors.value = {};
    try {
        await props.send({
            work_date: form.work_date,
            in_at: form.in_time ? `${form.work_date}T${form.in_time}` : null,
            out_at: form.out_time ? `${outDate}T${form.out_time}` : null,
            reason: form.reason.trim(),
        });
        toast.success(t('attendance.correction.sent'));
        emit('sent');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog :open="open" :title="t('attendance.correction.title')" :description="employeeName ? t('attendance.correction.for', { name: employeeName }) : t('attendance.correction.text')" :icon="PenLine" @close="emit('close')">
        <form id="attendance-correction" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('attendance.correction.day')" :error="fieldError('work_date')" class="sm:col-span-2">
                <input :id="id" v-model="form.work_date" type="date" class="field-input" required />
            </AppField>
            <AppField v-slot="{ id }" :label="t('attendance.correction.in')" :error="fieldError('in_at')" optional>
                <input :id="id" v-model="form.in_time" type="time" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('attendance.correction.out')" :hint="form.in_time && form.out_time && form.out_time <= form.in_time ? t('attendance.correction.next_morning') : ''" :error="fieldError('out_at')" optional>
                <input :id="id" v-model="form.out_time" type="time" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('attendance.correction.reason')" :error="fieldError('reason')" class="sm:col-span-2">
                <textarea :id="id" v-model="form.reason" rows="3" class="field-input" maxlength="500"></textarea>
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('attendance.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="attendance-correction" :loading="saving" :disabled="!form.work_date || (!form.in_time && !form.out_time) || form.reason.trim().length < 5">
                {{ t('attendance.correction.send') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
