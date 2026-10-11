<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { FilePlus } from 'lucide-vue-next';
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
import { newOpId } from '../lib';

/**
 * Billing students: a month (monthly heads), the session's once-a-session
 * heads, or chosen "other" heads, for everyone here or one class. Makes a
 * draft to check; what was left out (already billed, no amount) is told.
 */
const props = defineProps({ open: Boolean, sessionId: { type: String, default: '' } });
const emit = defineEmits(['close']);
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();
const router = useRouter();

const form = reactive({ kind: 'monthly', session_id: '', period: '', head_ids: [], level_id: '', issue_date: '', due_date: '' });
const errors = ref({});
const saving = ref(false);
let opId = newOpId();
watch(() => props.open, (open) => {
    if (!open) return;
    const today = setup.today.value;
    Object.assign(form, { kind: 'monthly', session_id: props.sessionId, period: today.slice(0, 7), head_ids: [], level_id: '', issue_date: today, due_date: '' });
    errors.value = {};
    opId = newOpId();
});
const kinds = computed(() => ['monthly', 'session', 'other'].map((value) => ({ value, label: t(`education_fees.run_kinds.${value}`) })));
const otherHeads = computed(() => (setup.data.value?.heads ?? []).filter((head) => head.frequency === 'other' && head.is_active));
const ready = computed(() => form.session_id && form.issue_date
    && (form.kind === 'monthly' ? form.period : form.due_date)
    && (form.kind !== 'other' || form.head_ids.length));

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { data, meta } = await api.createRun({
            kind: form.kind, session_id: form.session_id, issue_date: form.issue_date, level_id: form.level_id || null, op_id: opId,
            ...(form.kind === 'monthly' ? { period: form.period } : {}),
            ...(form.kind === 'other' ? { head_ids: form.head_ids } : {}),
            ...(form.due_date ? { due_date: form.due_date } : {}),
        });
        toast.success(t('education_fees.runs.made', { count: data.bills_count }));
        emit('close');
        router.push({ name: 'fees-run', params: { id: data.id }, query: { billed: meta?.skipped?.billed || undefined, none: meta?.skipped?.no_amounts || undefined } });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('education_fees.runs.new')" :description="t('education_fees.runs.text')" :icon="FilePlus" @close="emit('close')">
        <form id="fee-run" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="ready && save()">
            <AppField :label="t('education_fees.runs.kind')" class="sm:col-span-2">
                <AppSegmented v-model="form.kind" :options="kinds" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_fees.runs.session')" :error="errors.session_id?.[0] ?? null">
                <select :id="id" v-model="form.session_id" class="field-input">
                    <option v-for="session in setup.data.value?.sessions ?? []" :key="session.id" :value="session.id">{{ textIn(session.name) }}</option>
                </select>
            </AppField>
            <AppField v-if="form.kind === 'monthly'" v-slot="{ id }" :label="t('education_fees.runs.period')" :error="errors.period?.[0] ?? null">
                <input :id="id" v-model="form.period" type="month" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_fees.runs.level')" :class="form.kind === 'monthly' ? 'sm:col-span-2' : ''">
                <select :id="id" v-model="form.level_id" class="field-input">
                    <option value="">{{ t('education_fees.runs.all_levels') }}</option>
                    <option v-for="level in setup.data.value?.levels ?? []" :key="level.id" :value="level.id">{{ textIn(level.program?.name) }} · {{ textIn(level.name) }}</option>
                </select>
            </AppField>
            <fieldset v-if="form.kind === 'other'" class="sm:col-span-2">
                <legend class="mb-1.5 text-[13px] font-medium text-fg">{{ t('education_fees.runs.heads') }}</legend>
                <p v-if="!otherHeads.length" class="text-[13px] text-muted">{{ t('education_fees.runs.no_other_heads') }}</p>
                <label v-for="head in otherHeads" :key="head.id" class="flex items-center gap-2 py-1 text-[13.5px]">
                    <input v-model="form.head_ids" type="checkbox" :value="head.id" class="size-4 accent-[var(--color-brand)]" />
                    <span class="font-mono text-muted" dir="ltr">{{ head.code }}</span> {{ textIn(head.name) }}
                </label>
                <p v-if="errors.head_ids" class="mt-1 text-[12.5px] text-bad">{{ errors.head_ids[0] }}</p>
            </fieldset>
            <AppField v-slot="{ id }" :label="t('education_fees.runs.issue_date')" :error="errors.issue_date?.[0] ?? null">
                <input :id="id" v-model="form.issue_date" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_fees.runs.due_date')" :error="errors.due_date?.[0] ?? null"
                :hint="form.kind === 'monthly' ? t('education_fees.runs.due_hint', { day: setup.rules.value.due_day }) : ''">
                <input :id="id" v-model="form.due_date" type="date" class="field-input" :min="form.issue_date" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education_fees.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="fee-run" :loading="saving" :disabled="!ready">{{ t('education_fees.runs.create') }}</AppButton>
        </template>
    </AppDialog>
</template>
