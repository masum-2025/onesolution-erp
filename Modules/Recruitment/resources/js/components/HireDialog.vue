<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { BadgeCheck } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { has, t } from '@/lib/i18n';
import { toast } from '@/lib/toast';
import { dayOf } from '../lib';
import PersonPicker from './PersonPicker.vue';

/**
 * Hiring a candidate: they become an employee in HR with their name and
 * contacts, the job's unit and position, the first day, the kind of
 * employment and a manager. HR checks its own rules first and says what
 * is missing (fill it in HR after hiring, or there first).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    application: { type: Object, default: null },
    // the opening (its kind of employment)
    opening: { type: Object, default: null },
    employmentTypes: { type: Array, default: () => [] },
    // the first day of the offer the candidate accepted, if any
    startOn: { type: String, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'hired']);

const form = reactive({ joined_on: '', employment_type: '', manager: null });
const errors = ref({});
const saving = ref(false);
const today = () => dayOf();

watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, { joined_on: props.startOn ?? today(), employment_type: props.opening?.employment_type ?? props.employmentTypes[0] ?? '', manager: null });
    errors.value = {};
});

const typeLabel = (type) => (has(`recruitment.employment.${type}`) ? t(`recruitment.employment.${type}`) : type);
// Errors of HR fields this form does not have (a rule asks for them), shown together.
const otherErrors = computed(() => Object.entries(errors.value).filter(([key]) => !['joined_on', 'employment_type', 'manager_id', 'base_version'].includes(key)).map(([, messages]) => messages[0]));

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const response = await props.calls.hire(props.application.id, {
            base_version: props.application.version, joined_on: form.joined_on, employment_type: form.employment_type || null, manager_id: form.manager?.id ?? null,
        });
        toast.success(t('recruitment.hire.done', { name: props.application.name }));
        emit('hired', response.data);
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
    <AppDialog :open="open" :title="t('recruitment.hire.title')" :description="application ? t('recruitment.hire.text', { name: application.name }) : ''" :icon="BadgeCheck" @close="emit('close')">
        <form id="recruitment-hire" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('recruitment.hire.joined_on')" :error="fieldError('joined_on')">
                <input :id="id" v-model="form.joined_on" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('recruitment.editor.employment_type')" :error="fieldError('employment_type')">
                <select :id="id" v-model="form.employment_type" class="field-input">
                    <option v-for="type in employmentTypes" :key="type" :value="type">{{ typeLabel(type) }}</option>
                </select>
            </AppField>
            <div class="sm:col-span-2">
                <PersonPicker v-model="form.manager" :search="(q) => calls.people(application.id, q)" :label="t('recruitment.hire.manager')" :error="fieldError('manager_id')" optional />
            </div>
            <div v-if="otherErrors.length" class="rounded-lg bg-bad/10 px-3 py-2 text-[13px] text-bad sm:col-span-2" role="alert">
                <p class="font-medium">{{ t('recruitment.hire.hr_needs') }}</p>
                <ul class="list-disc ps-5">
                    <li v-for="message in otherErrors" :key="message">{{ message }}</li>
                </ul>
            </div>
            <p class="text-[12.5px] text-muted sm:col-span-2">{{ t('recruitment.hire.note') }}</p>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('recruitment.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="recruitment-hire" :icon="BadgeCheck" :loading="saving" :disabled="!form.joined_on">{{ t('recruitment.hire.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
