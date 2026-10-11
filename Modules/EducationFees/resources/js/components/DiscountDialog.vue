<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { BadgePercent } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import { parseAmount, parsePercent } from '../lib';

/**
 * Giving a student a discount or scholarship: a percent or a fixed amount,
 * on one head or every head, with a reason and dates. The server decides
 * whether someone else must approve it, and says so.
 */
const props = defineProps({ open: Boolean, student: { type: Object, default: null } });
const emit = defineEmits(['close', 'saved']);
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();

const form = reactive({ head_id: '', mode: 'percent', percent: '', amount: '', reason: '', starts_on: '', ends_on: '' });
const errors = ref({});
const saving = ref(false);
watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, { head_id: '', mode: 'percent', percent: '', amount: '', reason: '', starts_on: setup.today.value, ends_on: '' });
    errors.value = {};
});
const heads = computed(() => (setup.data.value?.heads ?? []).filter((head) => head.is_active));
const percentBp = computed(() => parsePercent(form.percent));
const amountMinor = computed(() => parseAmount(form.amount, setup.currency.value));
const ready = computed(() => form.reason.trim().length >= 3 && form.starts_on && (form.mode === 'percent' ? percentBp.value : amountMinor.value));

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.giveConcession({
            student_id: props.student.id, head_id: form.head_id || null, mode: form.mode, reason: form.reason.trim(),
            starts_on: form.starts_on, ends_on: form.ends_on || null,
            ...(form.mode === 'percent' ? { percent_bp: percentBp.value } : { amount_minor: amountMinor.value }),
        });
        toast.success(t(data.status === 'pending' ? 'education_fees.discount.pending' : 'education_fees.discount.given'));
        emit('saved', data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('education_fees.discount.title')" :description="t('education_fees.discount.text')" :icon="BadgePercent" @close="emit('close')">
        <form id="fee-discount" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="ready && save()">
            <AppField v-slot="{ id }" :label="t('education_fees.discount.head')" class="sm:col-span-2" :error="errors.head_id?.[0] ?? null">
                <select :id="id" v-model="form.head_id" class="field-input">
                    <option value="">{{ t('education_fees.discount.all_heads') }}</option>
                    <option v-for="head in heads" :key="head.id" :value="head.id">{{ head.code }} · {{ textIn(head.name) }}</option>
                </select>
            </AppField>
            <AppField :label="t('education_fees.discount.mode')" class="sm:col-span-2">
                <AppSegmented v-model="form.mode" :options="[{ value: 'percent', label: t('education_fees.discount.percent') }, { value: 'fixed', label: t('education_fees.discount.fixed') }]" />
            </AppField>
            <AppField v-if="form.mode === 'percent'" v-slot="{ id }" :label="t('education_fees.discount.percent_label')" class="sm:col-span-2"
                :error="(form.percent && !percentBp ? t('education_fees.discount.percent_invalid') : null) ?? errors.percent_bp?.[0] ?? null">
                <input :id="id" v-model="form.percent" inputmode="decimal" class="field-input sm:w-40" placeholder="25" />
            </AppField>
            <AppField v-else v-slot="{ id }" :label="t('education_fees.discount.amount_label')" class="sm:col-span-2"
                :error="(form.amount && !amountMinor ? t('education_fees.counter.amount_invalid') : null) ?? errors.amount_minor?.[0] ?? null">
                <input :id="id" v-model="form.amount" inputmode="decimal" class="field-input tabular sm:w-48" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_fees.discount.reason')" class="sm:col-span-2" :error="errors.reason?.[0] ?? null">
                <input :id="id" v-model="form.reason" class="field-input" maxlength="300" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_fees.discount.starts_on')" :error="errors.starts_on?.[0] ?? null">
                <input :id="id" v-model="form.starts_on" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_fees.discount.ends_on')" :error="errors.ends_on?.[0] ?? null">
                <input :id="id" v-model="form.ends_on" type="date" class="field-input" :min="form.starts_on" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education_fees.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="fee-discount" :loading="saving" :disabled="!ready">{{ t('education_fees.discount.give') }}</AppButton>
        </template>
    </AppDialog>
</template>
