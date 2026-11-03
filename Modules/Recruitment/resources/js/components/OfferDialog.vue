<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { FileSignature } from 'lucide-vue-next';
import { DateInput, MoneyInput } from '@/ui';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { dayOf } from '../lib';

/** A job offer: the monthly salary, the first day and the day to answer by. Written as a draft (or a draft changed). */
const props = defineProps({
    open: { type: Boolean, default: false },
    application: { type: Object, default: null },
    // a draft to change, or null for a new one
    offer: { type: Object, default: null },
    currency: { type: String, default: 'BDT' },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);

const form = reactive({ salary_minor: null, start_on: '', respond_by: '' });
const salaryBad = ref(false);
const errors = ref({});
const saving = ref(false);
const money = computed(() => props.offer?.currency ?? props.currency);
const inDays = (days) => dayOf(days);

watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, props.offer
        ? { salary_minor: props.offer.salary_minor, start_on: props.offer.start_on, respond_by: props.offer.respond_by }
        : { salary_minor: null, start_on: inDays(30), respond_by: inDays(7) });
    errors.value = {};
});

const salaryMinor = computed(() => form.salary_minor);

async function save() {
    saving.value = true;
    errors.value = {};
    const body = { salary_minor: salaryMinor.value, start_on: form.start_on, respond_by: form.respond_by };
    try {
        const response = props.offer
            ? await props.calls.updateOffer(props.offer.id, { ...body, base_version: props.offer.version })
            : await props.calls.writeOffer(props.application.id, body);
        toast.success(t('recruitment.offer.saved'));
        emit('saved', response.data);
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
    <AppDialog :open="open" :title="offer ? t('recruitment.offer.edit_title') : t('recruitment.offer.new_title')" :description="application ? t('recruitment.offer.text', { name: application.name }) : ''" :icon="FileSignature" @close="emit('close')">
        <form id="recruitment-offer" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('recruitment.offer.salary', { currency: money })" :error="fieldError('salary_minor') ?? (salaryBad ? t('recruitment.editor.salary_bad') : null)" class="sm:col-span-2">
                <MoneyInput :id="id" v-model="form.salary_minor" v-model:invalid="salaryBad" :currency="money" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('recruitment.offer.respond_by')" :error="fieldError('respond_by')">
                <DateInput :id="id" v-model="form.respond_by" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('recruitment.offer.start_on')" :error="fieldError('start_on')">
                <DateInput :id="id" v-model="form.start_on" :min="form.respond_by" />
            </AppField>
            <p class="text-[12.5px] text-muted sm:col-span-2">{{ t('recruitment.offer.hint') }}</p>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('recruitment.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="recruitment-offer" :loading="saving" :disabled="!salaryMinor || !form.start_on || !form.respond_by">{{ t('recruitment.offer.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
