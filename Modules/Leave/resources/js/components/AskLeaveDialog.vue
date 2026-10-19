<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { CalendarPlus } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import EmployeePicker from './EmployeePicker.vue';
import { daysText, newOpId } from '../lib';

/**
 * Ask for leave: the kind, the first and last day (half a day at either
 * end where the kind allows it) and why. For oneself, or (HR) for the
 * employee picked. The server counts the working days, checks the balance
 * and says clearly what is wrong; a repeated send is the same request.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    // [{ id, name, code, half_day, paid }] (active kinds)
    types: { type: Array, default: () => [] },
    // { type_id: available_halves } for oneself; empty for someone else
    available: { type: Object, default: () => ({}) },
    send: { type: Function, required: true },
    people: { type: Function, default: null },
});
const emit = defineEmits(['close', 'sent']);

const form = reactive({ employee: null, type_id: '', from_date: '', to_date: '', from_half: false, to_half: false, reason: '' });
const errors = ref({});
const saving = ref(false);
let opId = newOpId();

watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, { employee: null, type_id: props.types[0]?.id ?? '', from_date: '', to_date: '', from_half: false, to_half: false, reason: '' });
    errors.value = {};
    opId = newOpId();
});

const type = computed(() => props.types.find((item) => item.id === form.type_id) ?? null);
const oneDay = computed(() => form.from_date && form.from_date === form.to_date);
watch(() => form.from_date, (value) => {
    if (value && (!form.to_date || form.to_date < value)) form.to_date = value;
});
watch(type, (value) => {
    if (!value?.half_day) Object.assign(form, { from_half: false, to_half: false });
});

const ready = computed(() => form.type_id && form.from_date && form.to_date && form.reason.trim().length >= 3 && (!props.people || form.employee));

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await props.send({
            ...(props.people ? { employee_id: form.employee.id } : { op_id: opId }),
            type_id: form.type_id,
            from_date: form.from_date,
            to_date: form.to_date,
            from_half: form.from_half,
            to_half: form.to_half,
            reason: form.reason.trim(),
        });
        toast.success(t('leave.ask.sent'));
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
    <AppDialog :open="open" :title="t(people ? 'leave.ask.title_for' : 'leave.ask.title')" :description="t('leave.ask.text')" :icon="CalendarPlus" @close="emit('close')">
        <form id="leave-ask" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <div v-if="people" class="sm:col-span-2">
                <EmployeePicker v-model="form.employee" :search="people" :label="t('leave.ask.employee')" :error="fieldError('employee_id')" />
            </div>
            <AppField v-slot="{ id }" :label="t('leave.ask.type')" :error="fieldError('type_id')" class="sm:col-span-2">
                <select :id="id" v-model="form.type_id" class="field-input">
                    <option v-for="item in types" :key="item.id" :value="item.id">
                        {{ item.name }}<template v-if="available[item.id] !== undefined && item.paid"> — {{ t('leave.ask.left', { days: daysText(available[item.id]) }) }}</template><template v-if="!item.paid"> — {{ t('leave.common.unpaid') }}</template>
                    </option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('leave.ask.from')" :error="fieldError('from_date')">
                <input :id="id" v-model="form.from_date" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('leave.ask.to')" :error="fieldError('to_date')">
                <input :id="id" v-model="form.to_date" type="date" class="field-input" :min="form.from_date" />
            </AppField>
            <fieldset v-if="type?.half_day" class="flex flex-wrap gap-x-6 gap-y-2 sm:col-span-2">
                <legend class="sr-only">{{ t('leave.ask.halves') }}</legend>
                <label class="inline-flex items-center gap-2 text-[13.5px]">
                    <input v-model="form.from_half" type="checkbox" class="size-4 accent-[var(--brand)]" :disabled="oneDay && form.to_half" />
                    {{ t(oneDay ? 'leave.ask.afternoon_only' : 'leave.ask.from_afternoon') }}
                </label>
                <label class="inline-flex items-center gap-2 text-[13.5px]">
                    <input v-model="form.to_half" type="checkbox" class="size-4 accent-[var(--brand)]" :disabled="oneDay && form.from_half" />
                    {{ t(oneDay ? 'leave.ask.morning_only' : 'leave.ask.to_noon') }}
                </label>
                <p v-if="fieldError('from_half') || fieldError('to_half')" class="w-full text-[12.5px] text-bad" role="alert">{{ fieldError('from_half') ?? fieldError('to_half') }}</p>
            </fieldset>
            <AppField v-slot="{ id }" :label="t('leave.ask.reason')" :error="fieldError('reason')" class="sm:col-span-2">
                <textarea :id="id" v-model="form.reason" rows="3" class="field-input" maxlength="500"></textarea>
            </AppField>
            <p class="text-[12.5px] text-muted sm:col-span-2">{{ t('leave.ask.counted') }}</p>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('leave.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="leave-ask" :loading="saving" :disabled="!ready">{{ t('leave.ask.send') }}</AppButton>
        </template>
    </AppDialog>
</template>
