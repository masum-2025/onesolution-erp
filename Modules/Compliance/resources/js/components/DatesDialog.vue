<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { CalendarCheck } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { formatDate } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { expiryFrom } from '../lib';

/**
 * The official last audit and expiry days of a register line, always with a
 * reason (kept in its history and the audit log). The expiry day may be
 * proposed from the service's usual months; it is never set without a click.
 */
const props = defineProps({
    open: Boolean,
    line: { type: Object, default: null },
    months: { type: Number, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);

const form = reactive({ last_audit_on: '', expires_on: '', reason: '' });
const errors = ref({});
const saving = ref(false);
watch(() => props.open, (open) => {
    if (!open || !props.line) return;
    Object.assign(form, { last_audit_on: props.line.last_audit_on ?? '', expires_on: props.line.expires_on ?? '', reason: '' });
    errors.value = {};
});
const proposed = computed(() => expiryFrom(form.last_audit_on, props.months));

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await props.calls.dates(props.line.id, {
            base_version: props.line.version,
            last_audit_on: form.last_audit_on || null,
            expires_on: form.expires_on || null,
            reason: form.reason.trim(),
        });
        toast.success(t('compliance.dates.saved'));
        emit('saved', data);
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
    <AppDialog :open="open" :title="t('compliance.dates.title')" :description="line ? `${line.factory.name} · ${line.service?.name}` : ''" @close="emit('close')">
        <form id="compliance-dates" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('compliance.fields.last_audit_on')" :error="fieldError('last_audit_on')" optional>
                <input :id="id" v-model="form.last_audit_on" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('compliance.fields.expires_on')" :error="fieldError('expires_on')" optional>
                <input :id="id" v-model="form.expires_on" type="date" class="field-input" />
            </AppField>
            <p v-if="proposed && proposed !== form.expires_on" class="flex flex-wrap items-center gap-2 rounded-lg bg-subtle px-3 py-2 text-[13px] sm:col-span-2">
                <span>{{ t('compliance.dates.proposed', { date: formatDate(proposed), months: months }) }}</span>
                <AppButton size="sm" variant="ghost" :icon="CalendarCheck" @click="form.expires_on = proposed">{{ t('compliance.dates.use') }}</AppButton>
            </p>
            <AppField v-slot="{ id }" :label="t('compliance.dates.reason')" :hint="t('compliance.dates.reason_hint')" :error="fieldError('reason')" class="sm:col-span-2">
                <textarea :id="id" v-model="form.reason" rows="2" class="field-input" maxlength="500"></textarea>
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('compliance.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="compliance-dates" :loading="saving" :disabled="form.reason.trim().length < 5">{{ t('compliance.dates.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
