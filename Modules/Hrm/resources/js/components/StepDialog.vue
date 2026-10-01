<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { api } from '@/lib/http';
import { confirmAction } from '@/lib/dialogs';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * One employment step (confirm, transfer, promote, notice, exit, rehire)
 * with only the fields that step needs. Ending employment asks once more.
 */
const props = defineProps({
    open: Boolean,
    step: { type: String, default: null },
    employee: { type: Object, required: true },
    hrm: { type: Object, required: true },
    options: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done', 'conflict']);

const form = reactive({ on: '', reason: '', to_organization_id: '', position_id: '', exits_on: '' });
const errors = ref({});
const saving = ref(false);
const units = ref([]);
const positions = ref([]);

const needsUnit = computed(() => ['transfer', 'promote', 'rehire'].includes(props.step));
const needsPosition = computed(() => ['promote', 'rehire'].includes(props.step));
const dateLabel = computed(() => t(props.step === 'exit' ? 'hrm.steps.on_exit' : props.step === 'rehire' ? 'hrm.steps.on_rehire' : 'hrm.steps.on'));

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        Object.assign(form, { on: new Date().toISOString().slice(0, 10), reason: '', to_organization_id: props.step === 'transfer' ? '' : '', position_id: '', exits_on: '' });
        errors.value = {};
        if (needsUnit.value) {
            const result = await api('/api/organizations', { query: { per_page: 100 } }).catch(() => ({ data: [] }));
            units.value = result.data.filter((unit) => ['company', 'branch', 'department', 'personal'].includes(unit.type) && unit.id !== props.employee.unit.id);
        }
        if (needsPosition.value) positions.value = (await props.hrm.positions().catch(() => ({ data: [] }))).data;
    },
);

async function submit() {
    if (props.step === 'exit') {
        const confirmed = await confirmAction({
            title: t('hrm.steps.exit_confirm_title', { name: props.employee.full_name }),
            message: t('hrm.steps.exit_confirm_text'),
            confirmLabel: t('hrm.steps.exit_confirm'),
            danger: true,
        });
        if (!confirmed) return;
    }

    saving.value = true;
    errors.value = {};
    try {
        const body = { base_version: props.employee.version, ...Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '')) };
        const { data } = await props.hrm.step(props.employee.id, props.step, body);
        toast.success(t('hrm.steps.done', { step: t(`hrm.steps.${props.step}`) }));
        emit('done', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('hrm.profile.conflict'));
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
    <AppDialog :open="open" :title="step ? t(`hrm.steps.${step}`) : ''" :description="employee.full_name" :tone="step === 'exit' ? 'bad' : 'brand'" @close="emit('close')">
        <form id="hrm-step" class="space-y-4" novalidate @submit.prevent="submit">
            <AppField v-slot="{ id }" :label="dateLabel" :error="fieldError('on')">
                <input :id="id" v-model="form.on" type="date" class="field-input" />
            </AppField>

            <AppField v-if="needsUnit" v-slot="{ id }" :label="t('hrm.steps.to_unit')" :error="fieldError('to_organization_id')" :optional="step !== 'transfer'">
                <select :id="id" v-model="form.to_organization_id" class="field-input">
                    <option value="">–</option>
                    <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.display_name }}</option>
                </select>
            </AppField>

            <AppField v-if="needsPosition" v-slot="{ id }" :label="t('hrm.steps.to_position')" :error="fieldError('position_id')" :optional="step !== 'promote'">
                <select :id="id" v-model="form.position_id" class="field-input">
                    <option value="">–</option>
                    <option v-for="position in positions" :key="position.id" :value="position.id">{{ position.title }}</option>
                </select>
            </AppField>

            <AppField
                v-if="step === 'notice'"
                v-slot="{ id }"
                :label="t('hrm.steps.exits_on')"
                :hint="options ? t('hrm.steps.exits_on_hint', { count: options.notice_period_days.value }) : ''"
                :error="fieldError('exits_on')"
                optional
            >
                <input :id="id" v-model="form.exits_on" type="date" class="field-input" :min="form.on || undefined" />
            </AppField>

            <AppField v-slot="{ id }" :label="t('hrm.steps.reason')" :error="fieldError('reason')" :optional="step !== 'exit'" :hint="step === 'exit' ? t('hrm.steps.reason_required') : ''">
                <textarea :id="id" v-model="form.reason" rows="3" class="field-input" maxlength="500" />
            </AppField>
        </form>

        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('hrm.profile.cancel') }}</AppButton>
            <AppButton :variant="step === 'exit' ? 'danger' : 'primary'" type="submit" form="hrm-step" :loading="saving">{{ step ? t(`hrm.steps.${step}`) : '' }}</AppButton>
        </template>
    </AppDialog>
</template>
